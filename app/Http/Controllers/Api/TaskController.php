<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiaryEntry;
use App\Models\Task;
use App\Models\User;
use App\Services\Diary\DiaryService;
use App\Support\Access;
use App\Support\ActivityNarrator;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    private const STATUS_LABELS = [
        'pendente' => 'Pendente', 'andamento' => 'Em andamento',
        'aguardando' => 'Aguardando terceiros', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado',
    ];
    private const PRIO_LABELS = [
        'urgente' => 'Urgente', 'alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa',
    ];

    public function __construct(private DiaryService $diary, private \App\Services\Ai\GeminiService $gemini)
    {
    }

    /**
     * Texto do lançamento de atividade: tenta a redação natural via LLM (Gemini)
     * quando o usuário mantém o log com IA ligado; em falha/timeout/desligado,
     * usa o template determinístico do ActivityNarrator. O texto da LLM é PLANO
     * e escapado antes de persistir (o histórico é renderizado como HTML).
     *
     * @param array{title?:string,duration?:string} $ctx
     */
    private function naturalize(string $event, string $deterministic, array $ctx): string
    {
        $user = Workspace::user();
        if (! ($user->prefs()['aiActivityLog'] ?? true) || ! $this->gemini->enabled()) {
            return $deterministic;
        }
        $natural = $this->gemini->narrate($event, $ctx);

        return $natural ? e($natural) : $deterministic;
    }

    /** Cria uma tarefa em branco (botão "Nova tarefa" / "+"). */
    public function store(Request $request)
    {
        $user = Workspace::user();
        $project = Workspace::defaultProject();
        $task = $project->tasks()->create([
            'title'       => $request->input('title', 'Nova tarefa'),
            'description' => '',
            'status'      => 'pendente',
            'priority'    => 'media',
            'responsible' => $user->name,
            'position'    => 0,
        ]);
        $task->logHistory($this->naturalize('created', ActivityNarrator::created($task->title), ['title' => $task->title]), $user->name, $user->id);

        return response()->json($this->present($task), 201);
    }

    /** Alterna concluída/pendente (checkbox do card). */
    public function toggle(Task $task)
    {
        $user = $this->guard($task);
        $from = $task->status;
        $this->diary->reconcile($user);   // fecha períodos de dias anteriores antes de movimentar (sem atravessar dias)
        $done = $from === 'concluido';
        $task->status = $done ? 'pendente' : 'concluido';
        $task->completed_at = $done ? null : now();
        $task->save();
        $this->diary->onStatusChange($task, $from, $task->status, $user);
        $spawned = null;
        if ($done) {
            $task->logHistory($this->naturalize('reopened', ActivityNarrator::reopened($task->title), ['title' => $task->title]), $user->name, $user->id);
        } else {
            $this->logCompletion($task, $user);
            $spawned = $this->spawnRecurrence($task, $user);
        }

        return response()->json($this->presentWithDiary($task, $user, $spawned));
    }

    /** Move para outro status (drag and drop do Kanban). */
    public function move(Request $request, Task $task)
    {
        $user = $this->guard($task);
        $status = $request->validate(['status' => 'required|in:pendente,andamento,aguardando,concluido,cancelado'])['status'];
        $from = $task->status;
        $this->diary->reconcile($user);   // fecha períodos de dias anteriores antes de movimentar (sem atravessar dias)
        $task->status = $status;
        $task->completed_at = $status === 'concluido' ? now() : null;
        $task->save();
        $this->diary->onStatusChange($task, $from, $status, $user);
        $spawned = null;
        if ($status === 'concluido') {
            $this->logCompletion($task, $user);
            if ($from !== 'concluido') {
                $spawned = $this->spawnRecurrence($task, $user);
            }
        } else {
            $deterministic = $this->moveNarration($task, $from, $status);
            $text = $status === 'andamento' ? $this->naturalize('started', $deterministic, ['title' => $task->title]) : $deterministic;
            $task->logHistory($text, $user->name, $user->id);
        }

        return response()->json($this->presentWithDiary($task, $user, $spawned));
    }

    /** Salvamento completo do modal (campos + checklist + comentários). */
    public function sync(Request $request, Task $task)
    {
        $user = $this->guard($task);
        $statusFrom = $task->status;
        $this->diary->reconcile($user);   // fecha períodos de dias anteriores antes de movimentar (sem atravessar dias)
        $data = $request->validate([
            'title'                => 'required|string|max:255',
            'description'          => 'nullable|string',
            'status'               => 'required|in:pendente,andamento,aguardando,concluido,cancelado',
            'priority'             => 'required|in:urgente,alta,media,baixa',
            'project'              => 'nullable|string|max:60',
            'due'                  => 'nullable|date',
            'responsible'          => 'nullable|string|max:120',
            'section'              => 'nullable|string|max:60',
            'startDate'            => 'sometimes|nullable|date',
            'estimatedMinutes'     => 'sometimes|nullable|integer|min:0|max:100000',
            'recurrence'           => 'sometimes|in:none,daily,weekly,monthly',
            'remindAt'             => 'sometimes|nullable|date',
            'labelIds'             => 'sometimes|array',
            'labelIds.*'           => 'integer',
            'checklist'            => 'array',
            'checklist.*.id'       => 'nullable',
            'checklist.*.text'     => 'required|string',
            'checklist.*.done'     => 'boolean',
            'checklist.*.assignee' => 'nullable|string|max:120',
            'checklist.*.priority' => 'nullable|in:urgente,alta,media,baixa',
            'checklist.*.due'      => 'nullable|date',
            'comments'             => 'array',
            'comments.*.id'        => 'nullable',
            'comments.*.text'      => 'required|string',
            'comments.*.author'    => 'nullable|string',
            'comments.*.initials'  => 'nullable|string',
            'comments.*.color'     => 'nullable|string',
            'comments.*.ai'        => 'boolean',
        ]);

        DB::transaction(function () use ($task, $data) {
            $user = Workspace::user();
            $actor = $user->name;
            $userId = $user->id;

            // --- diffs de histórico (status é narrado após a movimentação do diário, abaixo) ---
            if ($task->status !== $data['status'] && ! in_array($data['status'], ['concluido', 'andamento'], true)) {
                $task->logHistory(ActivityNarrator::statusChanged(self::STATUS_LABELS[$data['status']]), $actor, $userId);
            }
            if ($task->priority !== $data['priority']) {
                $task->logHistory(ActivityNarrator::priorityChanged(self::PRIO_LABELS[$data['priority']]), $actor, $userId);
            }
            $newDue = $data['due'] ?? null;
            if (optional($task->due_date)->format('Y-m-d') !== $newDue) {
                $label = $newDue ? Carbon::parse($newDue)->format('d/m/Y') : 'Sem prazo';
                $task->logHistory(ActivityNarrator::dueChanged($label), $actor, $userId);
            }
            if (strip_tags((string) $task->description) !== strip_tags((string) ($data['description'] ?? ''))) {
                $task->logHistory(ActivityNarrator::descriptionEdited(), $actor, $userId);
            }
            if (! empty($data['project']) && $data['project'] !== $task->project?->slug) {
                $found = $user->projects()->where('slug', $data['project'])->first();
                if ($found) {
                    $task->project_id = $found->id;
                    $task->logHistory(ActivityNarrator::projectChanged($found->name), $actor, $userId);
                }
            }

            // --- campos ---
            $task->fill([
                'title'       => $data['title'],
                'description' => $data['description'] ?? '',
                'status'      => $data['status'],
                'priority'    => $data['priority'],
                'due_date'    => $newDue,
                'responsible' => $data['responsible'] ?? $user->name,
                'section'     => $data['section'] ?? $task->section,
            ]);
            // --- paridade B2 (só toca quando o campo é enviado) ---
            if (array_key_exists('startDate', $data)) {
                $task->start_date = $data['startDate'] ?: null;
            }
            if (array_key_exists('estimatedMinutes', $data)) {
                $task->estimated_minutes = $data['estimatedMinutes'];
            }
            if (array_key_exists('recurrence', $data)) {
                $task->recurrence = $data['recurrence'];
            }
            if (array_key_exists('remindAt', $data)) {
                $newRemind = $data['remindAt'] ?: null;
                if (optional($task->remind_at)->toIso8601String() !== ($newRemind ? \Illuminate\Support\Carbon::parse($newRemind)->toIso8601String() : null)) {
                    $task->remind_fired_at = null; // reagenda o disparo
                }
                $task->remind_at = $newRemind;
            }
            $task->completed_at = $data['status'] === 'concluido' ? ($task->completed_at ?? now()) : null;
            $task->save();

            if (array_key_exists('labelIds', $data)) {
                $valid = $user->labels()->whereIn('id', $data['labelIds'])->pluck('id')->all();
                $task->labels()->sync($valid);
            }
            $this->syncChecklist($task, $data['checklist'] ?? []);
            $this->syncComments($task, $data['comments'] ?? [], $user);
        });

        $spawned = null;
        if ($statusFrom !== $data['status']) {
            $fresh = $task->fresh();
            $this->diary->onStatusChange($fresh, $statusFrom, $data['status'], $user);
            if ($data['status'] === 'concluido') {
                $this->logCompletion($fresh, $user);
                $spawned = $this->spawnRecurrence($fresh, $user);
            } elseif ($data['status'] === 'andamento') {
                $fresh->logHistory($this->naturalize('started', ActivityNarrator::started($fresh->title), ['title' => $fresh->title]), $user->name, $user->id);
            }
        }

        return response()->json($this->presentWithDiary($task, $user, $spawned));
    }

    /** Arquiva ou restaura a tarefa (alterna archived_at). */
    public function archive(Task $task)
    {
        $user = $this->guard($task);
        $task->archived_at = $task->archived_at ? null : now();
        $task->save();
        $task->logHistory(
            $task->archived_at ? ActivityNarrator::archived($task->title) : ActivityNarrator::restored($task->title),
            $user->name,
            $user->id
        );

        return response()->json($this->present($task));
    }

    /** Duplica a tarefa (campos + checklist + etiquetas), como nova tarefa pendente. */
    public function duplicate(Task $task)
    {
        $user = $this->guard($task);
        $task->loadMissing(['steps', 'labels']);
        $copy = $task->project->tasks()->create([
            'title'             => $task->title . ' (cópia)',
            'description'       => $task->description,
            'status'            => 'pendente',
            'priority'          => $task->priority,
            'section'           => $task->section,
            'responsible'       => $task->responsible,
            'due_date'          => $task->due_date,
            'start_date'        => $task->start_date,
            'estimated_minutes' => $task->estimated_minutes,
            'recurrence'        => $task->recurrence,
            'position'          => 0,
        ]);
        foreach ($task->steps as $s) {
            $copy->steps()->create(['title' => $s->title, 'status' => $s->status, 'position' => $s->position]);
        }
        $copy->labels()->sync($task->labels->pluck('id')->all());
        $copy->logHistory(ActivityNarrator::created($copy->title), $user->name, $user->id);

        return response()->json($this->present($copy), 201);
    }

    /**
     * Dispara lembretes de tarefa vencidos (chamado pelo polling do front).
     * Registra uma notificação database (aparece no sino) e carimba remind_fired_at.
     */
    public function remindersDue()
    {
        $user = Workspace::user();
        $now = now();
        $due = Task::query()
            ->whereIn('project_id', $this->ownedProjectIds($user))
            ->whereNull('archived_at')
            ->where('status', '!=', 'concluido')
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', $now)
            ->whereNull('remind_fired_at')
            ->get();

        $fired = [];
        foreach ($due as $task) {
            $user->notify(new \App\Notifications\TaskReminderDue($task));
            $task->remind_fired_at = $now;
            $task->save();
            $fired[] = (string) $task->id;
        }

        return response()->json(['fired' => $fired]);
    }

    /** Projetos cujas tarefas pertencem ao próprio usuário (lembretes só do dono). */
    private function ownedProjectIds(User $user)
    {
        return $user->projects()->pluck('id');
    }

    /** Cria a próxima ocorrência de uma tarefa recorrente recém-concluída. */
    private function spawnRecurrence(Task $task, User $user): ?Task
    {
        $rec = $task->recurrence ?? 'none';
        if ($rec === 'none' || $rec === '' || ! $task->project) {
            return null;
        }
        $task->loadMissing(['steps', 'labels']);
        $base = $task->due_date ?? Carbon::today();
        $next = $task->project->tasks()->create([
            'title'             => $task->title,
            'description'       => $task->description,
            'status'            => 'pendente',
            'priority'          => $task->priority,
            'section'           => $task->section,
            'responsible'       => $task->responsible,
            'due_date'          => $this->advanceDate($base, $rec),
            'start_date'        => $task->start_date ? $this->advanceDate($task->start_date, $rec) : null,
            'estimated_minutes' => $task->estimated_minutes,
            'recurrence'        => $rec,
            'remind_at'         => $task->remind_at ? $this->advanceDate($task->remind_at, $rec) : null,
            'position'          => 0,
        ]);
        foreach ($task->steps as $s) {
            $next->steps()->create(['title' => $s->title, 'status' => 'pending', 'position' => $s->position]);
        }
        $next->labels()->sync($task->labels->pluck('id')->all());
        $next->logHistory(ActivityNarrator::recurred($next->title), $user->name, $user->id);

        return $next;
    }

    private function advanceDate(\DateTimeInterface $date, string $recurrence): Carbon
    {
        $d = Carbon::parse($date);

        return match ($recurrence) {
            'daily'   => $d->addDay(),
            'weekly'  => $d->addWeek(),
            'monthly' => $d->addMonthNoOverflow(),
            default   => $d,
        };
    }

    public function destroy(Task $task)
    {
        $this->guard($task);
        $task->delete();

        return response()->json(['deleted' => true]);
    }

    /** Adiciona um link externo à tarefa. */
    public function addLink(Request $request, Task $task)
    {
        $this->guard($task);
        $data = $request->validate([
            'url'   => 'required|string|max:2048',
            'label' => 'nullable|string|max:160',
        ]);
        $url = preg_match('#^https?://#i', $data['url']) ? $data['url'] : 'https://' . ltrim($data['url']);
        $task->links()->create(['url' => $url, 'label' => $data['label'] ?? null]);

        return response()->json($this->present($task), 201);
    }

    public function removeLink(Task $task, \App\Models\TaskLink $link)
    {
        $this->guard($task);
        abort_unless($link->task_id === $task->id, 404);
        $link->delete();

        return response()->json($this->present($task));
    }

    /** Relaciona esta tarefa a outra (ambas precisam estar acessíveis ao usuário). */
    public function addRelation(Request $request, Task $task)
    {
        $user = $this->guard($task);
        $data = $request->validate([
            'related_id' => 'required|integer',
            'type'       => 'required|in:relacionada,bloqueia,depende',
        ]);
        $related = Task::find($data['related_id']);
        abort_if(! $related || (int) $data['related_id'] === $task->id, 422, 'Tarefa relacionada inválida.');
        abort_if(Access::taskPermission($user, $related) === null, 404); // não vaza tarefas sem acesso
        $task->taskRelations()->firstOrCreate(['related_task_id' => $related->id, 'type' => $data['type']]);

        return response()->json($this->present($task), 201);
    }

    public function removeRelation(Task $task, \App\Models\TaskRelation $relation)
    {
        $this->guard($task);
        abort_unless($relation->task_id === $task->id, 404);
        $relation->delete();

        return response()->json($this->present($task));
    }

    /** Edita um comentário — só o próprio autor (não-IA). */
    public function updateComment(Request $request, Task $task, \App\Models\TaskComment $comment)
    {
        $user = $this->guard($task);
        abort_unless($comment->task_id === $task->id, 404);
        abort_unless(! $comment->is_ai && $comment->user_id === $user->id, 403);
        $data = $request->validate(['text' => 'required|string|max:5000']);
        $comment->update(['comment' => $data['text']]);

        return response()->json($this->present($task));
    }

    /** Exclui um comentário — só o próprio autor (não-IA). */
    public function destroyComment(Task $task, \App\Models\TaskComment $comment)
    {
        $user = $this->guard($task);
        abort_unless($comment->task_id === $task->id, 404);
        abort_unless(! $comment->is_ai && $comment->user_id === $user->id, 403);
        $comment->delete();

        return response()->json($this->present($task));
    }

    /** Texto da movimentação (exceto conclusão, tratada à parte): início, cancelamento ou mudança de status. */
    private function moveNarration(Task $task, string $from, string $to): string
    {
        if ($to === 'andamento') {
            return ActivityNarrator::started($task->title);
        }
        if ($to === 'cancelado') {
            return ActivityNarrator::cancelled($task->title);
        }

        return ActivityNarrator::statusChanged(self::STATUS_LABELS[$to] ?? $to);
    }

    /** Registra a conclusão (com o tempo trabalhado) e carimba o tempo total na 1ª entrada da tarefa. */
    private function logCompletion(Task $task, User $user): void
    {
        $minutes = $this->workedMinutes($task);
        $text = $this->naturalize('completed', ActivityNarrator::completed($task->title, $minutes), ['title' => $task->title, 'duration' => ActivityNarrator::duration($minutes)]);
        $task->logHistory($text, $user->name, $user->id, null, $minutes !== null ? (string) $minutes : null);
        $this->stampStartDuration($task, $minutes);
    }

    /** Soma o tempo trabalhado na tarefa a partir dos períodos do Diário de Atividades. */
    private function workedMinutes(Task $task): ?int
    {
        $total = DiaryEntry::where('task_id', $task->id)->get()
            ->sum(fn (DiaryEntry $e) => $e->computedDurationMinutes() ?? 0);

        return $total > 0 ? (int) $total : null;
    }

    /** Anexa o tempo total trabalhado à 1ª entrada do histórico da tarefa (criação/início). */
    private function stampStartDuration(Task $task, ?int $minutes): void
    {
        $first = $task->history()->orderBy('created_at')->orderBy('id')->first();
        if (! $first) {
            return;
        }
        // remove um sufixo anterior (idempotente) e anexa o novo
        $base = preg_replace('/ · concluída em .*$/u', '', (string) $first->action);
        $first->action = $base . ActivityNarrator::completionSuffix($minutes);
        $first->new_value = $minutes !== null ? (string) $minutes : null;
        $first->save();
    }

    /**
     * Garante que a tarefa pertence ao usuário atual (evita IDOR) e devolve o usuário.
     */
    private function guard(Task $task): \App\Models\User
    {
        $user = Workspace::user();
        $perm = Access::taskPermission($user, $task);
        abort_if($perm === null, 404);                       // sem acesso: não vaza existência
        abort_unless(Access::can($perm, 'edit'), 403);       // somente-visualização não edita

        return $user;
    }

    private function syncChecklist(Task $task, array $items): void
    {
        $keepIds = [];
        foreach ($items as $i => $item) {
            $id = is_numeric($item['id'] ?? null) ? (int) $item['id'] : null;
            $existing = $id ? $task->steps()->whereKey($id)->first() : null;
            $payload = [
                'title'    => $item['text'],
                'status'   => ! empty($item['done']) ? 'done' : 'pending',
                'assignee' => $item['assignee'] ?? null,
                'priority' => $item['priority'] ?? null,
                'due_date' => $item['due'] ?? null,
                'position' => $i,
            ];
            if ($existing) {
                $existing->update($payload);
                $keepIds[] = $existing->id;
            } else {
                $keepIds[] = $task->steps()->create($payload)->id;
            }
        }
        $task->steps()->whereNotIn('id', $keepIds ?: [0])->delete();
    }

    private function syncComments(Task $task, array $items, \App\Models\User $user): void
    {
        $existingIds = $task->comments()->pluck('id')->all();
        foreach ($items as $item) {
            $id = is_numeric($item['id'] ?? null) ? (int) $item['id'] : null;
            if ($id && in_array($id, $existingIds, true)) {
                continue; // comentários existentes não são editados no modal
            }
            $task->comments()->create([
                'user_id'    => empty($item['ai']) ? $user->id : null,
                'comment'    => $item['text'],
                'author'     => $item['author'] ?? $user->name,
                'initials'   => $item['initials'] ?? \App\Support\Initials::of($user->name),
                'color'      => $item['color'] ?? 'linear-gradient(135deg,#f59e0b,#ef4444)',
                'is_ai'      => ! empty($item['ai']),
            ]);
        }
    }

    private function present(Task $task): array
    {
        return $task->fresh(['steps', 'comments', 'history', 'project', 'attachments', 'labels', 'links', 'taskRelations.relatedTask'])->toApiArray();
    }

    /** Resposta com a tarefa + snapshot do diário (atualizado pela movimentação). */
    private function presentWithDiary(Task $task, User $user, ?Task $spawned = null): array
    {
        $payload = [
            'task'         => $this->present($task),
            'diaryEntries' => $this->diaryFor($user),
        ];
        if ($spawned) {
            $payload['spawnedTask'] = $this->present($spawned);
        }

        return $payload;
    }

    /** @return array<int,array> */
    private function diaryFor(User $user): array
    {
        return $user->diaryEntries()
            ->with(['task', 'project', 'attachments', 'histories'])
            ->latest('started_at')
            ->limit(120)
            ->get()
            ->map->toApiArray()
            ->all();
    }
}
