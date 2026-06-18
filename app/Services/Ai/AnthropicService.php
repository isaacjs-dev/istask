<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Driver opcional baseado em LLM (Claude / API da Anthropic). Interpreta o
 * comando em ações estruturadas usando a Messages API
 * (POST /v1/messages, headers x-api-key + anthropic-version).
 *
 * É acionado apenas quando AI_DRIVER=anthropic e há ANTHROPIC_API_KEY. Em
 * qualquer falha (sem chave, erro de rede, JSON inválido) retorna null e o
 * App\Services\Ai\AiCommandService recai automaticamente no motor de regras.
 */
class AnthropicService
{
    private const VALID_STATUS = ['pendente', 'andamento', 'aguardando', 'concluido', 'cancelado'];
    private const VALID_PRIORITY = ['urgente', 'alta', 'media', 'baixa'];

    public function enabled(): bool
    {
        return config('ai.driver') === 'anthropic' && filled(config('ai.anthropic.api_key'));
    }

    /** @return array{reply:string,replyCard:?array,actions:array}|null */
    public function process(string $text, array $tasks): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $cfg = config('ai.anthropic');
            $context = collect($tasks)->map(fn ($t) => [
                'id' => $t['id'], 'title' => $t['title'], 'status' => $t['status'],
                'priority' => $t['priority'], 'project' => $t['projectName'], 'due' => $t['due'],
            ])->all();

            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key'         => $cfg['api_key'],
                    'anthropic-version' => $cfg['version'],
                    'content-type'      => 'application/json',
                ])
                ->post(rtrim($cfg['base_url'], '/') . '/v1/messages', [
                    'model'      => $cfg['model'],
                    'max_tokens' => $cfg['max_tokens'],
                    'system'     => $this->systemPrompt(),
                    'messages'   => [[
                        'role'    => 'user',
                        'content' => "Tarefas atuais (JSON):\n" . json_encode($context, JSON_UNESCAPED_UNICODE)
                            . "\n\nComando do usuário:\n" . $text,
                    ]],
                ]);

            if (! $response->successful()) {
                Log::warning('Anthropic API erro', ['status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            $raw = $response->json('content.0.text');

            return $this->parse($raw, $tasks);
        } catch (Throwable $e) {
            Log::warning('Anthropic driver falhou: ' . $e->getMessage());

            return null;
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        Você é o assistente de um gerenciador de tarefas em português do Brasil.
        Recebe a lista atual de tarefas (com id) e um comando em linguagem natural
        (que pode conter erros de digitação). Corrija o texto, entenda a intenção e
        responda APENAS com um objeto JSON válido, sem markdown e sem texto fora do JSON,
        no formato:

        {
          "reply": "<resposta curta em PT-BR, pode usar <b>negrito</b>>",
          "actions": [ <0 ou mais ações> ]
        }

        Ações possíveis:
        - {"type":"create","task":{"title":"...","description":"<p>...</p>","status":"pendente","priority":"media","project":"Geral","due":null}}
        - {"type":"update","id":<id existente>,"patch":{ ... },"hist":"<descrição curta da alteração>"}
        - {"type":"delete","id":<id existente>}

        Chaves permitidas em "patch": status, priority, due, title, description, project, completedAt.
        - status: pendente | andamento | aguardando | concluido | cancelado
        - priority: urgente | alta | media | baixa
        - project: nome do projeto (ex.: Geral, Sistemas, Processos, Integrações, Comunicação ou outro já existente)
        - due: data no formato YYYY-MM-DD ou null
        - completedAt: true para concluir a tarefa

        Regras:
        - Use somente ids presentes na lista fornecida.
        - Nunca invente dados que o usuário não informou; sugira melhorias só quando fizer sentido.
        - Para criar tarefas, melhore o título e escreva uma descrição clara em HTML simples.
        - Se não houver ação clara, devolva "actions": [] e explique no "reply".
        PROMPT;
    }

    /** @return array{reply:string,replyCard:?array,actions:array}|null */
    private function parse(?string $raw, array $tasks): ?array
    {
        if (! $raw) {
            return null;
        }
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw);
        $data = json_decode(trim($raw), true);
        if (! is_array($data) || ! isset($data['reply'])) {
            return null;
        }

        $ids = array_column($tasks, 'id');
        $clean = [];
        $replyCard = null;

        foreach ($data['actions'] ?? [] as $a) {
            $type = $a['type'] ?? null;
            if ($type === 'create' && ! empty($a['task']['title'])) {
                $task = [
                    'title'       => (string) $a['task']['title'],
                    'description' => (string) ($a['task']['description'] ?? ''),
                    'status'      => in_array($a['task']['status'] ?? '', self::VALID_STATUS, true) ? $a['task']['status'] : 'pendente',
                    'priority'    => in_array($a['task']['priority'] ?? '', self::VALID_PRIORITY, true) ? $a['task']['priority'] : 'media',
                    'project'     => Str::slug((string) ($a['task']['project'] ?? 'Geral')) ?: 'geral',
                    'due'         => $this->validDate($a['task']['due'] ?? null),
                    'responsible' => 'Você',
                    'checklist'   => [],
                ];
                $clean[] = ['type' => 'create', 'task' => $task];
                $replyCard ??= $task;
            } elseif ($type === 'update' && in_array($a['id'] ?? null, $ids, true)) {
                $patch = $this->sanitizePatch($a['patch'] ?? []);
                if ($patch) {
                    $clean[] = ['type' => 'update', 'id' => $a['id'], 'patch' => $patch, 'hist' => (string) ($a['hist'] ?? 'atualizou a tarefa')];
                }
            } elseif ($type === 'delete' && in_array($a['id'] ?? null, $ids, true)) {
                $clean[] = ['type' => 'delete', 'id' => $a['id']];
            }
        }

        return [
            'reply'     => (string) $data['reply'],
            'replyCard' => $replyCard,
            'actions'   => $clean,
        ];
    }

    private function sanitizePatch(array $patch): array
    {
        $out = [];
        if (isset($patch['status']) && in_array($patch['status'], self::VALID_STATUS, true)) {
            $out['status'] = $patch['status'];
        }
        if (isset($patch['priority']) && in_array($patch['priority'], self::VALID_PRIORITY, true)) {
            $out['priority'] = $patch['priority'];
        }
        if (array_key_exists('due', $patch)) {
            $out['due'] = $this->validDate($patch['due']);
        }
        foreach (['title', 'description'] as $k) {
            if (isset($patch[$k]) && is_string($patch[$k])) {
                $out[$k] = $patch[$k];
            }
        }
        if (isset($patch['project']) && is_string($patch['project']) && $patch['project'] !== '') {
            $out['projectMatch'] = $patch['project'];
        }
        if (! empty($patch['completedAt'])) {
            $out['completedAt'] = true;
            $out['status'] = 'concluido';
        }

        return $out;
    }

    private function validDate($d): ?string
    {
        if (! is_string($d) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return null;
        }

        return $d;
    }
}
