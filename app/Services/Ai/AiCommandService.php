<?php

namespace App\Services\Ai;

use App\Models\AiMessage;
use App\Models\ComandoLog;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\User;
use App\Services\Commands\ActionRecorder;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Orquestra o chat (porta de entrada única). Fluxo:
 *   1) Interpretador LOCAL (AiEngine) classifica — SEM IA.
 *   2) Se não entendeu (Gatilho A) → Gemini devolve JSON → validador → executor.
 *   3) Ao criar texto livre (Gatilho B) → Gemini corrige só ortografia.
 * A IA nunca executa: quem aplica é sempre o executor determinístico (AiActionApplier).
 */
class AiCommandService
{
    public function __construct(
        private AiEngine $engine,
        private GeminiService $gemini,
        private AiActionApplier $applier,
        private ActionRecorder $recorder,
        private TaskRepository $tasks,
    ) {
    }

    public function handle(string $text, ?int $conversationId = null): array
    {
        $user = Workspace::user();
        $project = Workspace::defaultProject();
        $conversation = $this->resolveConversation($user, $conversationId);
        $tasksArr = $this->tasks->tasksFor($user);

        $this->persist($user, $project, $conversation, 'user', $text);

        // 0) confirmação pendente?
        if ($pending = Session::get('pending_command')) {
            $out = $this->resolvePending($text, $pending, $user, $project, $tasksArr);
            if ($out !== null) {
                return $this->finish($user, $project, $conversation, $out);
            }
        }

        // 1) interpretador LOCAL
        $context = [
            'hasOpenDiary' => $user->diaryEntries()->whereNull('ended_at')->exists(),
        ];
        $result = $this->engine->process($text, $tasksArr, $context);

        // desfazer / refazer
        if (($result['type'] ?? null) === 'undo' || ($result['type'] ?? null) === 'redo') {
            return $this->finish($user, $project, $conversation, $this->doUndoRedo($result['type'], $user));
        }

        // 2) Gatilho A — não entendido localmente → Gemini
        if (! empty($result['unresolved'])) {
            $result = $this->viaGemini($text, $user, $tasksArr, $result);
        }

        // confirmação necessária? guarda e pergunta (não executa)
        if (! empty($result['requer_confirmacao']) && ! empty($result['actions'])) {
            Session::put('pending_command', ['actions' => $result['actions'], 'text' => $text]);
            $msg = $result['mensagem_confirmacao'] ?? 'Confirmar esta ação?';

            return $this->finish($user, $project, $conversation, [
                'reply' => '⚠️ ' . $msg . '<br><span style="color:var(--ink-4)">Responda <b>sim</b> para confirmar ou <b>não</b> para cancelar.</span>',
            ]);
        }

        // 3) Gatilho B — corrige ortografia de texto livre antes de salvar
        $result['actions'] = $this->correctFreeText($result['actions'] ?? []);

        return $this->finish($user, $project, $conversation, $this->execute($result, $project, $user));
    }

    // -------- execução determinística --------
    private function execute(array $result, $project, User $user): array
    {
        $this->applier->createdTaskId = null;
        $this->applier->replyOverride = null;
        $this->applier->echo = null;
        $this->applier->projectsChanged = false;
        $this->applier->notesChanged = false;
        $this->applier->diaryChanged = false;

        $actions = $result['actions'] ?? [];
        if ($actions) {
            $this->applier->apply($actions, $project, $user);
        }

        $card = $result['replyCard'] ?? null;
        if ($card && $this->applier->createdTaskId) {
            $card['id'] = (string) $this->applier->createdTaskId;
            $card['due'] = $card['due'] ?? null;
        }

        return [
            'reply'           => $this->applier->replyOverride ?? ($result['reply'] ?? ''),
            'card'            => $card,
            'echo'            => $this->applier->echo,
            'open'            => isset($result['open']) ? (string) $result['open'] : null,
            'changed'         => count($actions) > 0,
            'projectsChanged' => $this->applier->projectsChanged,
            'notesChanged'    => $this->applier->notesChanged,
            'diaryChanged'    => $this->applier->diaryChanged,
        ];
    }

    private function doUndoRedo(string $type, User $user): array
    {
        $res = $type === 'undo' ? $this->recorder->undo($user) : $this->recorder->redo($user);
        if (! $res) {
            return ['reply' => $type === 'undo' ? 'Não há nada para desfazer.' : 'Não há nada para refazer.'];
        }

        return [
            'reply'   => $res['reply'],
            'echo'    => $type === 'undo' ? ['canRedo' => true] : ['canUndo' => true],
            'changed' => true,
            'projectsChanged' => true, // pode ter mexido em projeto; recarrega por segurança
            'notesChanged'    => true, // idem para notas
            'diaryChanged'    => true, // idem para diário
        ];
    }

    private function resolvePending(string $text, array $pending, User $user, $project, array $tasksArr): ?array
    {
        $t = mb_strtolower(Str::ascii($text));
        if (preg_match('/^\s*(sim|isso|confirmo|confirmar|pode|pode ser|ok|claro|com certeza|positivo|aham|isso mesmo)\b/u', $t)) {
            Session::forget('pending_command');

            return $this->execute(['actions' => $pending['actions']], $project, $user);
        }
        if (preg_match('/^\s*(nao|cancela\w*|deixa|esquece|negativo|para)\b/u', $t)) {
            Session::forget('pending_command');

            return ['reply' => 'Ok, <b>cancelado</b>. Nada foi alterado.'];
        }

        return null; // não é resposta de confirmação → segue o fluxo normal
    }

    // -------- Gatilho A: Gemini interpreta --------
    private function viaGemini(string $text, User $user, array $tasksArr, array $localResult): array
    {
        $intent = null;
        $result = ['reply' => $localResult['reply'] ?? 'Não entendi o comando.'];

        if ($this->gemini->enabled()) {
            $intent = $this->gemini->interpret($text, $this->geminiContext($user, $tasksArr));
            if ($intent && ($intent['confianca'] ?? 0) >= 0.45) {
                $mapped = $this->mapGeminiIntent($intent, $user, $tasksArr);
                if ($mapped !== null) {
                    $result = $mapped;
                }
            }
        }

        // Gatilho A SEMPRE registra para evoluir o interpretador local
        ComandoLog::create([
            'user_id'          => $user->id,
            'frase_original'   => $text,
            'intent_resolvido' => $intent['acao'] ?? null,
            'parametros'       => $intent['parametros'] ?? null,
            'confianca'        => $intent['confianca'] ?? null,
            'executado'        => ! empty($result['actions']) || isset($result['open']) || ($result['type'] ?? null),
            'created_at'       => now(),
        ]);

        return $result;
    }

    private function geminiContext(User $user, array $tasksArr): array
    {
        return [
            'today'    => now()->format('Y-m-d'),
            'projects' => $user->projects()->get(['id', 'name'])->all(),
            'tasks'    => array_map(fn ($t) => [
                'id' => $t['id'], 'titulo' => $t['title'], 'status' => $t['status'],
                'entrega' => $t['due'], 'projeto' => $t['projectName'],
            ], array_slice($tasksArr, 0, 60)),
        ];
    }

    /** Converte o JSON do Gemini no formato de resultado do engine (a IA não executa). */
    private function mapGeminiIntent(array $intent, User $user, array $tasksArr): ?array
    {
        $p = $intent['parametros'] ?? [];
        $alvo = $intent['alvo'] ?? [];
        $taskId = $this->resolveTaskId($alvo, $tasksArr);
        $valor = $p['valor'] ?? ($p['texto'] ?? ($p['nome'] ?? null));

        return match ($intent['acao']) {
            'criar_tarefa'   => ['reply' => '', 'replyCard' => null, 'actions' => [['type' => 'create', 'task' => ['title' => $valor ?: 'Nova tarefa', 'description' => '', 'status' => 'pendente', 'priority' => $p['prioridade'] ?? 'media', 'project' => $this->resolveProjectSlug($p['projeto'] ?? null, $user), 'due' => $p['data_entrega'] ?? null, 'checklist' => []]]]],
            'editar_campo'   => $taskId ? ['reply' => '', 'actions' => [['type' => 'update', 'id' => $taskId, 'patch' => $this->mapField($p), 'hist' => 'editou ' . ($p['campo'] ?? 'campo') . ' (via assistente)']]] : null,
            'mover_tarefa'   => $taskId ? ['reply' => '', 'actions' => [['type' => 'update', 'id' => $taskId, 'patch' => ['status' => $this->mapStatus($p['valor'] ?? $p['status'] ?? '')], 'hist' => 'alterou status (via assistente)']]] : null,
            'concluir_tarefa' => $taskId ? ['reply' => '', 'actions' => [['type' => 'update', 'id' => $taskId, 'patch' => ['status' => 'concluido', 'completedAt' => true], 'hist' => 'marcou como <b>Concluído</b>']]] : null,
            'excluir_tarefa' => $taskId ? ['reply' => '', 'actions' => [['type' => 'delete', 'id' => $taskId]], 'requer_confirmacao' => true, 'mensagem_confirmacao' => $intent['mensagem_confirmacao'] ?? 'Excluir esta tarefa?'] : null,
            'abrir_tarefa'   => $taskId ? ['reply' => 'Abrindo a tarefa…', 'open' => $taskId, 'actions' => []] : null,
            'buscar_tarefa'  => ['reply' => $this->buscaReply($alvo['match_por'] ?? $valor ?? '', $tasksArr), 'actions' => []],
            'criar_projeto'  => ['reply' => '', 'actions' => [['type' => 'create_project', 'name' => $valor ?: ($alvo['match_por'] ?? '')]]],
            'renomear_projeto' => ['reply' => '', 'actions' => [['type' => 'rename_project', 'match' => $alvo['match_por'] ?? '', 'name' => $valor ?: '']]],
            'excluir_projeto' => ['reply' => '', 'actions' => [['type' => 'delete_project', 'match' => $alvo['match_por'] ?? ($valor ?? '')]], 'requer_confirmacao' => true, 'mensagem_confirmacao' => 'Excluir esse projeto?'],
            'criar_nota'     => ['reply' => '', 'actions' => [['type' => 'create_note', 'title' => Str::limit($valor ?? '', 50, ''), 'body' => $valor ?? '', 'tags' => null]]],
            'buscar_nota'    => ['reply' => '', 'actions' => [['type' => 'query_note', 'q' => $alvo['match_por'] ?? ($valor ?? '')]]],
            'excluir_nota'   => ['reply' => '', 'actions' => [['type' => 'delete_note', 'q' => $alvo['match_por'] ?? ($valor ?? '')]], 'requer_confirmacao' => true, 'mensagem_confirmacao' => 'Excluir essa nota?'],
            'editar_nota'    => ['reply' => '', 'actions' => [['type' => 'update_note', 'q' => $alvo['match_por'] ?? '', 'patch' => ['body' => $p['valor'] ?? ($valor ?? '')]]]],
            'diario_iniciar' => ['reply' => '', 'actions' => [['type' => 'diary_start', 'description' => $valor ?? '']]],
            'diario_finalizar' => ['reply' => '', 'actions' => [['type' => 'diary_end', 'description' => $valor ?? '']]],
            'editar_diario'  => ['reply' => '', 'actions' => [['type' => 'update_diary', 'q' => $alvo['match_por'] ?? '', 'patch' => ['description' => $p['valor'] ?? ($valor ?? '')]]]],
            'excluir_diario' => ['reply' => '', 'actions' => [['type' => 'delete_diary', 'q' => $alvo['match_por'] ?? ($valor ?? '')]], 'requer_confirmacao' => true, 'mensagem_confirmacao' => $intent['mensagem_confirmacao'] ?? 'Excluir esta entrada do diário?'],
            'consultar_diario' => ['reply' => '', 'actions' => [['type' => 'query_diary', 'period' => $p['periodo'] ?? 'hoje']]],
            'desfazer'       => ['type' => 'undo'],
            'refazer'        => ['type' => 'redo'],
            default          => null,
        };
    }

    private function mapField(array $p): array
    {
        return match ($p['campo'] ?? '') {
            'data_entrega', 'data', 'prazo' => ['due' => $p['valor'] ?? null],
            'prioridade'                    => ['priority' => $this->mapPriority($p['valor'] ?? '')],
            'status'                        => ['status' => $this->mapStatus($p['valor'] ?? '')],
            'categoria', 'projeto'          => ['projectMatch' => $p['valor'] ?? ''],
            'responsavel'                   => ['responsible' => $p['valor'] ?? ''],
            'descricao'                     => ['description' => $p['valor'] ?? ''],
            default                         => ['title' => $p['valor'] ?? ''],
        };
    }

    /** Resolve o nome de projeto vindo do Gemini para um slug existente do usuário (fuzzy match). */
    private function resolveProjectSlug(?string $name, User $user): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $qn = mb_strtolower(Str::ascii($name));
        foreach ($user->projects()->get(['slug', 'name']) as $p) {
            $pn = mb_strtolower(Str::ascii($p->name));
            if (str_contains($pn, $qn) || str_contains($qn, $pn)) {
                return $p->slug;
            }
        }

        return null;
    }

    private function mapStatus(string $v): string
    {
        $v = mb_strtolower(Str::ascii($v));
        foreach (['andamento' => 'andamento', 'aguard' => 'aguardando', 'conclu' => 'concluido', 'cancel' => 'cancelado', 'pendent' => 'pendente'] as $k => $st) {
            if (str_contains($v, $k)) return $st;
        }

        return 'pendente';
    }

    private function mapPriority(string $v): string
    {
        $v = mb_strtolower(Str::ascii($v));
        foreach (['urgent' => 'urgente', 'alta' => 'alta', 'media' => 'media', 'baixa' => 'baixa'] as $k => $pr) {
            if (str_contains($v, $k)) return $pr;
        }

        return 'media';
    }

    private function resolveTaskId(array $alvo, array $tasksArr): ?int
    {
        if (! empty($alvo['id'])) {
            foreach ($tasksArr as $t) {
                if ((string) $t['id'] === (string) $alvo['id']) return (int) $t['id'];
            }
        }
        $q = mb_strtolower(Str::ascii($alvo['match_por'] ?? ''));
        if ($q === '') {
            return null;
        }
        foreach ($tasksArr as $t) {
            if (str_contains(mb_strtolower(Str::ascii($t['title'])), $q)) return (int) $t['id'];
        }

        return null;
    }

    private function buscaReply(string $q, array $tasksArr): string
    {
        $qn = mb_strtolower(Str::ascii($q));
        $hits = array_filter($tasksArr, fn ($t) => $qn !== '' && str_contains(mb_strtolower(Str::ascii($t['title'])), $qn));
        if (! count($hits)) {
            return 'Não encontrei tarefas relacionadas a <b>' . htmlspecialchars($q) . '</b>.';
        }
        $list = implode('<br>', array_map(fn ($t) => '• <b>' . htmlspecialchars($t['title']) . '</b>', array_slice($hits, 0, 6)));

        return 'Encontrei:<br><br>' . $list;
    }

    // -------- Gatilho B: correção ortográfica de texto livre --------
    private function correctFreeText(array $actions): array
    {
        if (! $this->gemini->enabled()) {
            return $actions;
        }

        return array_map(function ($a) {
            if (($a['type'] ?? '') === 'create' && isset($a['task']['title'])) {
                $a['task']['title'] = $this->gemini->correctSpelling($a['task']['title']);
            } elseif (($a['type'] ?? '') === 'create_note') {
                $a['body'] = $this->gemini->correctSpelling($a['body'] ?? '');
            } elseif (($a['type'] ?? '') === 'update_note' && isset($a['patch']['body'])) {
                $a['patch']['body'] = $this->gemini->correctSpelling($a['patch']['body']);
            } elseif (in_array($a['type'] ?? '', ['diary_start', 'diary_end'], true)) {
                $a['description'] = $this->gemini->correctSpelling($a['description'] ?? '');
            } elseif (($a['type'] ?? '') === 'update_diary' && isset($a['patch']['description'])) {
                $a['patch']['description'] = $this->gemini->correctSpelling($a['patch']['description']);
            }

            return $a;
        }, $actions);
    }

    // -------- persistência / resposta --------
    private function finish(User $user, $project, Conversation $conversation, array $out): array
    {
        $reply = $out['reply'] ?? '';
        $card = $out['card'] ?? null;

        $aiMessage = $this->persist($user, $project, $conversation, 'ai', $reply, $card);

        if (! $conversation->title) {
            $conversation->title = Str::limit('Conversa', 42);
        }
        $conversation->touch();
        $conversation->loadCount('messages');

        $payload = [
            'userMessage'    => ['id' => null, 'role' => 'user', 'text' => null], // já adicionado no front
            'aiMessage'      => $aiMessage->toApiArray(),
            'echo'           => $out['echo'] ?? null,
            'open'           => $out['open'] ?? null,
            'changed'        => $out['changed'] ?? false,
            'tasks'          => $this->tasks->tasksFor($user),
            'conversationId' => (string) $conversation->id,
            'conversation'   => $conversation->toApiArray(),
        ];
        if (! empty($out['projectsChanged'])) {
            $payload['projects'] = $user->projects()->orderBy('position')->get(['id', 'slug', 'name', 'icon'])->all();
        }
        if (! empty($out['notesChanged'])) {
            $payload['notes'] = $user->notes()->latest('updated_at')->get()->map->toApiArray()->all();
        }
        if (! empty($out['diaryChanged'])) {
            $payload['diaryEntries'] = $user->diaryEntries()->with('task')->latest('started_at')->limit(120)->get()->map->toApiArray()->all();
        }

        return $payload;
    }

    private function persist(User $user, $project, Conversation $conversation, string $role, string $message, ?array $card = null): AiMessage
    {
        return AiMessage::create([
            'user_id'         => $user->id,
            'project_id'      => $project->id,
            'conversation_id' => $conversation->id,
            'role'            => $role,
            'message'         => $message,
            'card'            => $card,
            'created_at'      => now(),
        ]);
    }

    private function resolveConversation(User $user, ?int $conversationId): Conversation
    {
        if ($conversationId) {
            $c = $user->conversations()->find($conversationId);
            if ($c) {
                if ($c->archived_at) {
                    $c->archived_at = null;
                    $c->save();
                }

                return $c;
            }
        }

        return $user->conversations()->active()->latest('updated_at')->first()
            ?? $user->conversations()->create(['title' => null]);
    }
}
