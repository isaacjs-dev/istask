<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Services\Diary\DiaryService;
use App\Support\Access;
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

    public function __construct(private DiaryService $diary)
    {
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
        $task->logHistory('criou a tarefa', $user->name, $user->id);

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
        $task->logHistory($done ? 'reabriu a tarefa' : 'marcou como <b>Concluído</b>', $user->name, $user->id);
        $this->diary->onStatusChange($task, $from, $task->status, $user);

        return response()->json($this->presentWithDiary($task, $user));
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
        $task->logHistory('moveu para <b>' . self::STATUS_LABELS[$status] . '</b>', $user->name, $user->id);
        $this->diary->onStatusChange($task, $from, $status, $user);

        return response()->json($this->presentWithDiary($task, $user));
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
            'checklist'            => 'array',
            'checklist.*.id'       => 'nullable',
            'checklist.*.text'     => 'required|string',
            'checklist.*.done'     => 'boolean',
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

            // --- diffs de histórico ---
            if ($task->status !== $data['status']) {
                $task->logHistory('alterou status para <b>' . self::STATUS_LABELS[$data['status']] . '</b>', $actor, $userId);
            }
            if ($task->priority !== $data['priority']) {
                $task->logHistory('alterou prioridade para <b>' . self::PRIO_LABELS[$data['priority']] . '</b>', $actor, $userId);
            }
            $newDue = $data['due'] ?? null;
            if (optional($task->due_date)->format('Y-m-d') !== $newDue) {
                $label = $newDue ? Carbon::parse($newDue)->format('d/m/Y') : 'Sem prazo';
                $task->logHistory('alterou o prazo para <b>' . $label . '</b>', $actor, $userId);
            }
            if (strip_tags((string) $task->description) !== strip_tags((string) ($data['description'] ?? ''))) {
                $task->logHistory('editou a descrição', $actor, $userId);
            }
            if (! empty($data['project']) && $data['project'] !== $task->project?->slug) {
                $found = $user->projects()->where('slug', $data['project'])->first();
                if ($found) {
                    $task->project_id = $found->id;
                    $task->logHistory("alterou o projeto para <b>{$found->name}</b>", $actor, $userId);
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
            $task->completed_at = $data['status'] === 'concluido' ? ($task->completed_at ?? now()) : null;
            $task->save();

            $this->syncChecklist($task, $data['checklist'] ?? []);
            $this->syncComments($task, $data['comments'] ?? [], $user);
        });

        if ($statusFrom !== $data['status']) {
            $this->diary->onStatusChange($task->fresh(), $statusFrom, $data['status'], $user);
        }

        return response()->json($this->presentWithDiary($task, $user));
    }

    public function destroy(Task $task)
    {
        $this->guard($task);
        $task->delete();

        return response()->json(['deleted' => true]);
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
        return $task->fresh(['steps', 'comments', 'history', 'project', 'attachments'])->toApiArray();
    }

    /** Resposta com a tarefa + snapshot do diário (atualizado pela movimentação). */
    private function presentWithDiary(Task $task, User $user): array
    {
        return [
            'task'         => $this->present($task),
            'diaryEntries' => $this->diaryFor($user),
        ];
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
