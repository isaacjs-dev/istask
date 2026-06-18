<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Proxy de backend para a API do Gemini (Google AI Studio). É a "Camada 2",
 * acionada APENAS por exceção:
 *   A) interpret()      → quando o interpretador local não entende o comando;
 *   B) correctSpelling()→ para corrigir ortografia/acentuação de texto livre.
 *
 * A chave fica só no servidor (config/services.php ← .env). A IA NUNCA executa
 * nada: interpret() apenas devolve um JSON de intenção que o executor
 * determinístico do sistema valida e aplica.
 */
class GeminiService
{
    /** Intents que o modelo pode devolver (espelha o catálogo local). */
    private const INTENTS = [
        'abrir_tarefa', 'buscar_tarefa', 'criar_projeto', 'renomear_projeto', 'excluir_projeto',
        'criar_tarefa', 'editar_campo', 'mover_tarefa', 'concluir_tarefa', 'excluir_tarefa',
        'criar_nota', 'buscar_nota', 'excluir_nota',
        'diario_iniciar', 'diario_finalizar', 'consultar_diario',
        'desfazer', 'refazer', 'desconhecido',
    ];

    public function enabled(): bool
    {
        return filled(config('services.gemini.key'));
    }

    /**
     * Gatilho A — interpreta uma frase não reconhecida localmente e devolve a
     * intenção estruturada (ou null se indisponível/falha).
     *
     * @return array{acao:string,alvo:array,parametros:array,confianca:float,requer_confirmacao:bool,mensagem_confirmacao:?string}|null
     */
    public function interpret(string $text, array $context): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $system = $this->interpretSystemPrompt();
        $user = "DATA DE HOJE: " . ($context['today'] ?? date('Y-m-d')) . "\n\n"
            . "PROJETOS:\n" . json_encode($context['projects'] ?? [], JSON_UNESCAPED_UNICODE) . "\n\n"
            . "TAREFAS (resumo):\n" . json_encode($context['tasks'] ?? [], JSON_UNESCAPED_UNICODE) . "\n\n"
            . "COMANDO DO USUÁRIO:\n" . $text;

        $json = $this->generate($system, $user, true);
        if (! is_array($json) || ! isset($json['acao'])) {
            return null;
        }

        return [
            'acao'                 => (string) $json['acao'],
            'alvo'                 => is_array($json['alvo'] ?? null) ? $json['alvo'] : [],
            'parametros'           => is_array($json['parametros'] ?? null) ? $json['parametros'] : [],
            'confianca'            => (float) ($json['confianca'] ?? 0),
            'requer_confirmacao'   => (bool) ($json['requer_confirmacao'] ?? false),
            'mensagem_confirmacao' => $json['mensagem_confirmacao'] ?? null,
        ];
    }

    /**
     * Gatilho B — corrige apenas ortografia/acentuação de um texto livre,
     * sem reescrever estilo nem mudar o sentido. Em falha, devolve o original.
     */
    public function correctSpelling(?string $text): ?string
    {
        $text = (string) $text;
        if (! $this->enabled() || trim($text) === '') {
            return $text;
        }
        $system = 'Você corrige APENAS ortografia e acentuação de textos em português do Brasil. '
            . 'NÃO reescreva, NÃO mude o estilo, NÃO altere o sentido, NÃO adicione nem remova informação. '
            . 'Responda somente com o texto corrigido, sem aspas e sem comentários.';
        $out = $this->generate($system, $text, false);

        return is_string($out) && trim($out) !== '' ? trim($out) : $text;
    }

    private function interpretSystemPrompt(): string
    {
        $intents = implode(', ', self::INTENTS);

        return <<<PROMPT
        Você interpreta comandos de um gerenciador de tarefas em português do Brasil. NÃO execute nada:
        apenas devolva um ÚNICO objeto JSON (sem texto fora dele) com a intenção do usuário.

        Intents válidos (campo "acao"): {$intents}.

        Formato:
        {
          "acao": "<um dos intents>",
          "alvo": { "tipo": "tarefa|projeto|nota|diario", "id": "<id ou null>", "match_por": "<texto de referência>" },
          "parametros": { "campo": "...", "valor": "...", "nome": "...", "texto": "...", "periodo": "hoje|ontem|semana" },
          "confianca": 0.0,
          "requer_confirmacao": false,
          "mensagem_confirmacao": null
        }

        Regras:
        - Use os ids reais das listas fornecidas ao referenciar uma tarefa/projeto existente.
        - Resolva datas relativas ("sexta", "amanhã", "20/06") para o formato YYYY-MM-DD com base na DATA DE HOJE.
        - Em "editar_campo", "parametros.campo" ∈ (titulo, descricao, status, prioridade, categoria, responsavel, data_entrega) e "parametros.valor" é o novo valor.
        - Marque "requer_confirmacao": true para exclusões.
        - Se não tiver certeza, use "acao": "desconhecido" com "confianca" baixa.
        - Responda APENAS com o JSON.
        PROMPT;
    }

    /** Chamada genérica ao generateContent. $json=true força saída JSON. */
    private function generate(string $system, string $user, bool $json): mixed
    {
        try {
            $cfg = config('services.gemini');
            $url = rtrim($cfg['base_url'], '/') . '/models/' . $cfg['model'] . ':generateContent';

            $generationConfig = ['temperature' => 0];
            if ($json) {
                $generationConfig['responseMimeType'] = 'application/json';
            }

            $resp = Http::timeout(30)
                ->withHeaders(['x-goog-api-key' => $cfg['key'], 'content-type' => 'application/json'])
                ->post($url, [
                    'system_instruction' => ['parts' => [['text' => $system]]],
                    'contents'           => [['role' => 'user', 'parts' => [['text' => $user]]]],
                    'generationConfig'   => $generationConfig,
                ]);

            if (! $resp->successful()) {
                Log::warning('Gemini API erro', ['status' => $resp->status(), 'body' => $resp->body()]);

                return null;
            }

            $text = $resp->json('candidates.0.content.parts.0.text');
            if (! is_string($text)) {
                return null;
            }
            if (! $json) {
                return $text;
            }

            $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));

            return json_decode($text, true);
        } catch (Throwable $e) {
            Log::warning('Gemini falhou: ' . $e->getMessage());

            return null;
        }
    }
}
