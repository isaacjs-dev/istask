<?php

namespace App\Services\Ai;

use App\Models\DiaryEntry;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Commands\ActionRecorder;
use App\Services\Diary\DiaryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Executor determinístico das ações (vindas do interpretador local OU do JSON
 * do Gemini — a IA nunca executa). Aplica no banco, registra na pilha de
 * desfazer (ActionRecorder) e monta o "echo" de confirmação para o chat.
 */
class AiActionApplier
{
    public ?int $createdTaskId = null;
    public ?string $replyOverride = null;   // resposta construída pelo executor (ex.: consultas, echo)
    public ?array $echo = null;             // payload de echo (para o botão [desfazer])
    public bool $projectsChanged = false;
    public bool $notesChanged = false;
    public bool $diaryChanged = false;

    private const STATUS_LABELS = [
        'pendente' => 'Pendente', 'andamento' => 'Em andamento',
        'aguardando' => 'Aguardando terceiros', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado',
    ];
    private const PRIO_LABELS = ['urgente' => 'Urgente', 'alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa'];

    public function __construct(private ActionRecorder $recorder, private DiaryService $diary)
    {
    }

    public function apply(array $actions, Project $project, User $user): void
    {
        DB::transaction(function () use ($actions, $project, $user) {
            foreach ($actions as $a) {
                match ($a['type']) {
                    'create'         => $this->createTask($a['task'], $project, $user),
                    'update'         => $this->updateTask($a, $user),
                    'delete'         => $this->deleteTask($a['id'], $user),
                    'create_project' => $this->createProject($a['name'], $user),
                    'rename_project' => $this->renameProject($a, $user),
                    'delete_project' => $this->deleteProject($a, $user),
                    'create_note'    => $this->createNote($a, $user),
                    'update_note'    => $this->updateNote($a, $user),
                    'delete_note'    => $this->deleteNote($a, $user),
                    'query_note'     => $this->queryNote($a, $user),
                    'diary_start'    => $this->diaryStart($a, $user),
                    'diary_end'      => $this->diaryEnd($a, $user),
                    'update_diary'   => $this->updateDiary($a, $user),
                    'delete_diary'   => $this->deleteDiary($a, $user),
                    'query_diary'    => $this->queryDiary($a, $user),
                    default          => null,
                };
            }
        });
    }

    // ============================================================ TAREFAS
    private function createTask(array $data, Project $project, User $user): void
    {
        $targetProject = $project;
        if (! empty($data['project'])) {
            $found = $user->projects()->where('slug', $data['project'])->first();
            if ($found) {
                $targetProject = $found;
            }
        }
        $task = $targetProject->tasks()->create([
            'title'       => $data['title'],
            'description' => $data['description'] ?? '',
            'status'      => $data['status'] ?? 'pendente',
            'priority'    => $data['priority'] ?? 'media',
            'section'     => null,
            'due_date'    => $data['due'] ?? null,
            'responsible' => $data['responsible'] ?? $user->name,
            'position'    => 0,
        ]);
        foreach ($data['checklist'] ?? [] as $i => $c) {
            $task->steps()->create(['title' => $c['text'], 'status' => ($c['done'] ?? false) ? 'done' : 'pending', 'position' => $i]);
        }
        $task->logHistory('criou a tarefa', 'IA');
        $this->createdTaskId = $task->id;

        $echo = $this->cardEcho($task, null, null, null, 'Tarefa criada');
        $this->recorder->record($user, 'create', 'task', $task->id, null, $this->taskSnapshot($task), 'Tarefa criada: ' . $task->title, $echo);
        $this->emit("✅ <b>Tarefa criada</b><br>" . $this->cardLine($task), $echo);
    }

    private function updateTask(array $a, User $user): void
    {
        $task = Task::find($a['id']);
        if (! $task) {
            return;
        }
        $patch = $a['patch'] ?? [];
        $cols = ['status', 'priority', 'title', 'description', 'section', 'responsible'];
        $before = [];
        $after = [];
        $projectChange = null;

        foreach ($cols as $col) {
            if (array_key_exists($col, $patch)) {
                $before[$col] = $task->{$col};
                $task->{$col} = $patch[$col];
                $after[$col] = $patch[$col];
            }
        }
        if (array_key_exists('projectMatch', $patch)) {
            $found = $this->matchProject($user, $patch['projectMatch']);
            if ($found) {
                $before['project_id'] = $task->project_id;
                $oldName = optional($task->project)->name ?? 'Geral';
                $task->project_id = $found->id;
                $after['project_id'] = $found->id;
                $projectChange = [$oldName, $found->name];
            }
        }
        if (array_key_exists('due', $patch)) {
            $before['due_date'] = optional($task->due_date)->format('Y-m-d');
            $task->due_date = $patch['due'];
            $after['due_date'] = $patch['due'];
        }
        if (array_key_exists('completedAt', $patch)) {
            $before['completed_at'] = optional($task->completed_at)->toDateTimeString();
            $task->completed_at = $patch['completedAt'] ? now() : null;
            $after['completed_at'] = optional($task->completed_at)->toDateTimeString();
        }
        if ($task->status === 'concluido' && ! $task->completed_at) {
            $task->completed_at = now();
        }
        $statusChanged = array_key_exists('status', $patch) && ($before['status'] ?? null) !== $patch['status'];
        $task->save();

        if ($statusChanged) {
            // O DiaryService cria/fecha o período de trabalho conforme a movimentação.
            $this->diary->onStatusChange($task->fresh(), $before['status'] ?? null, $patch['status'], $user);
            $this->diaryChanged = true;
        }

        if (array_key_exists('checklist', $patch)) {
            $task->steps()->delete();
            foreach ($patch['checklist'] as $i => $c) {
                $task->steps()->create(['title' => $c['text'], 'status' => ($c['done'] ?? false) ? 'done' : 'pending', 'position' => $i]);
            }
        }
        if (! empty($a['hist'])) {
            $task->logHistory($a['hist'], 'IA');
        } elseif ($projectChange) {
            $task->logHistory("alterou o projeto para <b>{$projectChange[1]}</b>", 'IA');
        }

        [$fieldLabel, $beforeTxt, $afterTxt] = $this->describeChange($patch, $before, $projectChange);
        $echo = $this->cardEcho($task->fresh(), $fieldLabel, $beforeTxt, $afterTxt, 'Alteração aplicada');
        $summary = $fieldLabel ? "{$fieldLabel}: {$beforeTxt} → {$afterTxt}" : 'Tarefa atualizada';
        $this->recorder->record($user, 'update', 'task', $task->id, $before, $after, $summary, $echo);

        $reply = "✅ <b>Alteração aplicada</b><br>" . $this->cardLine($task->fresh());
        if ($fieldLabel) {
            $reply .= "<br><b>Campo:</b> {$fieldLabel}<br><b>Antes:</b> " . $this->esc($beforeTxt) . " → <b>Depois:</b> " . $this->esc($afterTxt);
        }
        $this->emit($reply, $echo);
    }

    private function deleteTask(int $id, User $user): void
    {
        $task = Task::find($id);
        if (! $task) {
            return;
        }
        $snapshot = $this->taskSnapshot($task);
        $line = $this->cardLine($task);
        $task->delete();
        $echo = $this->cardEcho($task, null, null, null, 'Tarefa excluída');
        $this->recorder->record($user, 'delete', 'task', $id, $snapshot, null, 'Tarefa excluída: ' . $task->title, $echo);
        $this->emit("🗑️ <b>Tarefa excluída</b><br>{$line}", $echo);
    }

    // ============================================================ PROJETOS
    private function createProject(string $name, User $user): void
    {
        $project = $user->projects()->create([
            'workspace_id' => optional($user->ownedWorkspaces()->orderBy('position')->first())->id,
            'slug'     => $this->uniqueSlug($user, $name),
            'name'     => $name,
            'icon'     => 'Folder',
            'position' => ($user->projects()->max('position') ?? 0) + 1,
        ]);
        $this->projectsChanged = true;
        $echo = ['kind' => 'project', 'canUndo' => true, 'summary' => 'Projeto criado: ' . $name];
        $this->recorder->record($user, 'create', 'project', $project->id, null, ['name' => $name, 'slug' => $project->slug], 'Projeto criado: ' . $name, $echo);
        $this->emit("📁 <b>Projeto criado:</b> " . $this->esc($name), $echo);
    }

    private function renameProject(array $a, User $user): void
    {
        $project = $this->matchProject($user, $a['match'] ?? '');
        if (! $project) {
            $this->emit('Não encontrei o projeto que você quer renomear.');

            return;
        }
        $old = $project->name;
        $project->name = $a['name'];
        $project->save();
        $this->projectsChanged = true;
        $echo = ['kind' => 'project', 'canUndo' => true, 'field' => 'Nome', 'before' => $old, 'after' => $a['name'], 'summary' => "Projeto: {$old} → {$a['name']}"];
        $this->recorder->record($user, 'update', 'project', $project->id, ['name' => $old], ['name' => $a['name']], "Projeto renomeado: {$old} → {$a['name']}", $echo);
        $this->emit("✏️ <b>Projeto renomeado</b><br><b>Antes:</b> " . $this->esc($old) . " → <b>Depois:</b> " . $this->esc($a['name']), $echo);
    }

    private function deleteProject(array $a, User $user): void
    {
        $project = $this->matchProject($user, $a['match'] ?? '');
        if (! $project) {
            $this->emit('Não encontrei o projeto que você quer excluir.');

            return;
        }
        $name = $project->name;
        $project->delete();
        $this->projectsChanged = true;
        $echo = ['kind' => 'project', 'canUndo' => true, 'summary' => 'Projeto excluído: ' . $name];
        $this->recorder->record($user, 'delete', 'project', $project->id, ['name' => $name, 'slug' => $project->slug], null, 'Projeto excluído: ' . $name, $echo);
        $this->emit("🗑️ <b>Projeto excluído:</b> " . $this->esc($name) . " <span style=\"color:var(--ink-3)\">(as tarefas continuam)</span>", $echo);
    }

    // ============================================================ NOTAS
    private function createNote(array $a, User $user): void
    {
        $note = $user->notes()->create([
            'notebook_id' => \App\Models\Notebook::whereIn('workspace_id', $user->ownedWorkspaces()->select('id'))
                ->orderBy('workspace_id')->orderBy('position')->value('id'),
            'title' => $a['title'] ?? null,
            'body'  => $a['body'],
            'tags'  => $a['tags'] ?? null,
        ]);
        $this->notesChanged = true;
        $echo = ['kind' => 'note', 'canUndo' => true, 'summary' => 'Nota salva'];
        $this->recorder->record($user, 'create', 'note', $note->id, null, ['title' => $note->title, 'body' => $note->body], 'Nota salva', $echo);
        $this->emit("📝 <b>Nota salva.</b><br>" . $this->esc(Str::limit($note->body, 140)), $echo);
    }

    private function updateNote(array $a, User $user): void
    {
        $note = $this->matchNote($user, $a['q'] ?? '');
        if (! $note) {
            $this->emit('Não encontrei essa nota para editar.');

            return;
        }
        $before = $note->only(['title', 'body', 'tags']);
        foreach ($a['patch'] ?? [] as $col => $val) {
            $note->{$col} = $val;
        }
        $note->save();
        $after = $note->only(['title', 'body', 'tags']);
        $this->notesChanged = true;

        $field = array_key_first($a['patch'] ?? []) ?? 'body';
        [$label, $beforeTxt, $afterTxt] = $this->describeNoteChange($field, $before[$field] ?? null, $after[$field] ?? null);

        $summary = "Nota atualizada — {$label}: {$beforeTxt} → {$afterTxt}";
        $echo = ['kind' => 'note', 'canUndo' => true, 'summary' => $summary];
        $this->recorder->record($user, 'update', 'note', $note->id, $before, $after, $summary, $echo);
        $this->emit("✏️ <b>Nota atualizada</b><br><b>{$label}:</b> " . $this->esc($beforeTxt) . ' → ' . $this->esc($afterTxt), $echo);
    }

    private function deleteNote(array $a, User $user): void
    {
        $note = $this->matchNote($user, $a['q'] ?? '');
        if (! $note) {
            $this->emit('Não encontrei essa nota para excluir.');

            return;
        }
        $note->delete();
        $this->notesChanged = true;
        $echo = ['kind' => 'note', 'canUndo' => true, 'summary' => 'Nota excluída'];
        $this->recorder->record($user, 'delete', 'note', $note->id, ['title' => $note->title, 'body' => $note->body], null, 'Nota excluída', $echo);
        $this->emit("🗑️ <b>Nota excluída.</b><br>" . $this->esc(Str::limit($note->body, 120)), $echo);
    }

    private function queryNote(array $a, User $user): void
    {
        $q = trim($a['q'] ?? '');
        $matches = $this->searchNotes($user, $q);
        if (! count($matches)) {
            $this->emit("Não encontrei nenhuma nota sobre <b>" . $this->esc($q) . "</b>.");

            return;
        }
        $best = $matches->first();
        $reply = "📒 <b>Nota encontrada</b>" . ($best->title ? " — " . $this->esc($best->title) : '') . "<br>" . nl2br($this->esc($best->body));
        if ($matches->count() > 1) {
            $others = $matches->slice(1, 4)->map(fn ($n) => "• " . $this->esc(Str::limit($n->title ?: $n->body, 50)))->implode('<br>');
            $reply .= "<br><br><span style=\"color:var(--ink-4)\">Outras notas relacionadas:</span><br>{$others}";
        }
        $this->emit($reply);
    }

    // ============================================================ DIÁRIO
    private function diaryStart(array $a, User $user): void
    {
        $desc = trim($a['description'] ?? '');
        $taskId = $this->guessTaskId($user, $desc);
        $entry = $user->diaryEntries()->create([
            'task_id'     => $taskId,
            'started_at'  => now(),
            'description' => $desc,
        ]);
        $this->diaryChanged = true;
        $echo = ['kind' => 'diary', 'canUndo' => true, 'summary' => 'Diário iniciado'];
        $this->recorder->record($user, 'create', 'diary', $entry->id, null, ['description' => $desc], 'Diário iniciado: ' . $desc, $echo);
        $this->emit("▶️ <b>Diário iniciado</b> — " . $this->esc($desc ?: 'atividade') . " <span style=\"color:var(--ink-3)\">(início " . $entry->started_at->format('H:i') . ")</span>", $echo);
    }

    private function diaryEnd(array $a, User $user): void
    {
        $entry = $user->diaryEntries()->whereNull('ended_at')->latest('started_at')->first();
        if (! $entry) {
            $this->emit('Não há nenhuma atividade em aberto no diário. Comece com <i>“comecei a …”</i>.');

            return;
        }
        $before = ['ended_at' => null, 'description' => $entry->description];
        $entry->ended_at = now();
        $desc = trim($a['description'] ?? '');
        if ($desc !== '') {
            $entry->description = $entry->description ? $entry->description . ' — ' . $desc : $desc;
        }
        $entry->save();
        $this->diaryChanged = true;
        $echo = ['kind' => 'diary', 'canUndo' => true, 'summary' => 'Diário finalizado'];
        $this->recorder->record($user, 'update', 'diary', $entry->id, $before, ['ended_at' => $entry->ended_at->toDateTimeString(), 'description' => $entry->description], 'Diário finalizado', $echo);
        $dur = $entry->started_at->diff($entry->ended_at)->format('%hh%Im');
        $this->emit("⏹️ <b>Diário finalizado</b> — " . $this->esc(Str::limit($entry->description, 160)) . "<br><span style=\"color:var(--ink-3)\">" . $entry->started_at->format('H:i') . "–" . $entry->ended_at->format('H:i') . " · {$dur}</span>", $echo);
    }

    private function updateDiary(array $a, User $user): void
    {
        $entry = $this->matchDiaryEntry($user, $a['q'] ?? '');
        if (! $entry) {
            $this->emit('Não encontrei essa entrada do diário para editar.');

            return;
        }
        $before = $entry->only(['task_id', 'started_at', 'ended_at', 'description']);
        foreach ($a['patch'] ?? [] as $col => $val) {
            $entry->{$col} = $val;
        }
        $entry->save();
        $after = $entry->only(['task_id', 'started_at', 'ended_at', 'description']);
        $this->diaryChanged = true;

        $beforeTxt = Str::limit((string) ($before['description'] ?? ''), 60) ?: '—';
        $afterTxt = Str::limit((string) ($after['description'] ?? ''), 60) ?: '—';
        $summary = "Entrada de diário atualizada — Descrição: {$beforeTxt} → {$afterTxt}";
        $echo = ['kind' => 'diary', 'canUndo' => true, 'summary' => $summary];
        $this->recorder->record($user, 'update', 'diary', $entry->id, $before, $after, $summary, $echo);
        $this->emit("✏️ <b>Entrada do diário atualizada</b><br><b>Descrição:</b> " . $this->esc($beforeTxt) . ' → ' . $this->esc($afterTxt), $echo);
    }

    private function deleteDiary(array $a, User $user): void
    {
        $entry = $this->matchDiaryEntry($user, $a['q'] ?? '');
        if (! $entry) {
            $this->emit('Não encontrei essa entrada do diário para excluir.');

            return;
        }
        $before = $entry->only(['task_id', 'started_at', 'ended_at', 'description']);
        $desc = $entry->description;
        $entry->delete();
        $this->diaryChanged = true;
        $echo = ['kind' => 'diary', 'canUndo' => true, 'summary' => 'Entrada de diário excluída'];
        $this->recorder->record($user, 'delete', 'diary', $entry->id, $before, null, 'Entrada de diário excluída', $echo);
        $this->emit("🗑️ <b>Entrada do diário excluída.</b><br>" . $this->esc(Str::limit($desc ?: 'atividade', 120)), $echo);
    }

    private function queryDiary(array $a, User $user): void
    {
        $period = $a['period'] ?? 'hoje';
        [$from, $to, $label] = match ($period) {
            'ontem'  => [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay(), 'ontem'],
            'semana' => [Carbon::now()->subDays(7)->startOfDay(), Carbon::now()->endOfDay(), 'nos últimos 7 dias'],
            default  => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay(), 'hoje'],
        };
        $entries = $user->diaryEntries()->whereBetween('started_at', [$from, $to])->orderBy('started_at')->get();
        if ($entries->isEmpty()) {
            $this->emit("Nenhum registro no diário {$label}.");

            return;
        }
        $rows = $entries->map(function (DiaryEntry $e) {
            $time = $e->started_at->format('H:i') . ($e->ended_at ? '–' . $e->ended_at->format('H:i') : '–…');
            $open = $e->ended_at ? '' : ' <span style="color:var(--p-alta)">(em andamento)</span>';

            return "<b>{$time}</b>{$open} — " . $this->esc(Str::limit($e->description ?: 'atividade', 120));
        })->implode('<br>');
        $this->emit("🗓️ <b>Diário ({$label})</b><br>{$rows}");
    }

    // ============================================================ HELPERS
    private function emit(string $reply, ?array $echo = null): void
    {
        $this->replyOverride = $reply;
        if ($echo) {
            $this->echo = $echo;
        }
    }

    private function describeChange(array $patch, array $before, ?array $projectChange = null): array
    {
        if (array_key_exists('status', $patch)) {
            return ['Status', self::STATUS_LABELS[$before['status']] ?? '—', self::STATUS_LABELS[$patch['status']] ?? $patch['status']];
        }
        if (array_key_exists('priority', $patch)) {
            return ['Prioridade', self::PRIO_LABELS[$before['priority']] ?? '—', self::PRIO_LABELS[$patch['priority']] ?? $patch['priority']];
        }
        if (array_key_exists('due', $patch)) {
            return ['Data de entrega', $this->fmtDue($before['due_date'] ?? null), $this->fmtDue($patch['due'])];
        }
        if (array_key_exists('title', $patch)) {
            return ['Título', $before['title'] ?? '—', $patch['title']];
        }
        if ($projectChange) {
            return ['Projeto', $projectChange[0], $projectChange[1]];
        }
        if (array_key_exists('responsible', $patch)) {
            return ['Responsável', $before['responsible'] ?? '—', $patch['responsible']];
        }
        if (array_key_exists('description', $patch)) {
            return ['Descrição', Str::limit(strip_tags((string) ($before['description'] ?? '')), 40), Str::limit(strip_tags((string) $patch['description']), 40)];
        }
        if (array_key_exists('completedAt', $patch)) {
            return ['Status', 'Pendente', 'Concluído'];
        }

        return [null, null, null];
    }

    private function describeNoteChange(string $field, $before, $after): array
    {
        return match ($field) {
            'title' => ['Título', $before ?: '—', $after ?: '—'],
            'tags'  => ['Tags', $before ?: '—', $after ?: '—'],
            default => ['Conteúdo', Str::limit((string) $before, 60), Str::limit((string) $after, 60)],
        };
    }

    private function cardLine(Task $task): string
    {
        $proj = optional($task->project)->name ?? 'Geral';

        return "<b>Card:</b> [#{$task->id}] " . $this->esc($task->title) . " <span style=\"color:var(--ink-3)\">(projeto: " . $this->esc($proj) . ")</span>";
    }

    private function cardEcho(Task $task, ?string $field, ?string $before, ?string $after, string $summary): array
    {
        return [
            'kind'    => 'task',
            'canUndo' => true,
            'card'    => [
                'id'          => (string) $task->id,
                'title'       => $task->title,
                'project'     => optional($task->project)->name ?? 'Geral',
                'projectSlug' => optional($task->project)->slug ?? 'geral',
            ],
            'field'   => $field,
            'before'  => $before,
            'after'   => $after,
            'summary' => $summary,
        ];
    }

    private function taskSnapshot(Task $task): array
    {
        return [
            'title' => $task->title, 'description' => $task->description, 'status' => $task->status,
            'priority' => $task->priority, 'project_id' => $task->project_id, 'section' => $task->section,
            'due_date' => optional($task->due_date)->format('Y-m-d'), 'responsible' => $task->responsible,
            'completed_at' => optional($task->completed_at)->toDateTimeString(),
        ];
    }

    private function uniqueSlug(User $user, string $name): string
    {
        $base = Str::slug($name) ?: 'projeto';
        $slug = $base;
        $i = 1;
        while ($user->projects()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }

        return $slug;
    }

    private function matchProject(User $user, string $q): ?Project
    {
        $qn = $this->norm($q);
        if ($qn === '') {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($user->projects()->get() as $p) {
            $n = $this->norm($p->name);
            $score = 0;
            if (str_contains($n, $qn) || str_contains($qn, $n)) $score += 10;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $p;
            }
        }

        return $bestScore >= 3 ? $best : null;
    }

    private function matchNote(User $user, string $q)
    {
        return $this->searchNotes($user, $q)->first();
    }

    private function searchNotes(User $user, string $q)
    {
        $qn = $this->norm($q);
        $words = array_values(array_filter(preg_split('/\s+/', $qn), fn ($w) => mb_strlen($w) > 2));

        return $user->notes()->latest('updated_at')->get()->map(function (Note $n) use ($qn, $words) {
            $hay = $this->norm(($n->title ?? '') . ' ' . $n->body . ' ' . ($n->tags ?? ''));
            $score = ($qn !== '' && str_contains($hay, $qn)) ? 10 : 0;
            foreach ($words as $w) {
                if (str_contains($hay, $w)) $score += mb_strlen($w);
            }

            return ['note' => $n, 'score' => $score];
        })->filter(fn ($row) => $row['score'] > 0)->sortByDesc('score')->pluck('note')->values();
    }

    private function matchDiaryEntry(User $user, string $q): ?DiaryEntry
    {
        $qn = $this->norm($q);
        $words = array_values(array_filter(preg_split('/\s+/', $qn), fn ($w) => mb_strlen($w) > 2));

        return $user->diaryEntries()->with('task')->latest('started_at')->get()->map(function (DiaryEntry $e) use ($qn, $words) {
            $hay = $this->norm(($e->description ?? '') . ' ' . (optional($e->task)->title ?? ''));
            $score = ($qn !== '' && str_contains($hay, $qn)) ? 10 : 0;
            foreach ($words as $w) {
                if (str_contains($hay, $w)) $score += mb_strlen($w);
            }

            return ['entry' => $e, 'score' => $score];
        })->filter(fn ($row) => $row['score'] > 0)->sortByDesc('score')->pluck('entry')->values()->first();
    }

    private function guessTaskId(User $user, string $desc): ?int
    {
        $qn = $this->norm($desc);
        if ($qn === '') {
            return null;
        }
        $ids = $user->projects()->pluck('id');
        $task = Task::whereIn('project_id', $ids)->get()->first(function (Task $t) use ($qn) {
            $title = $this->norm($t->title);
            foreach (array_filter(preg_split('/\s+/', $qn), fn ($w) => mb_strlen($w) > 3) as $w) {
                if (str_contains($title, $w)) return true;
            }

            return false;
        });

        return $task?->id;
    }

    private function fmtDue(?string $d): string
    {
        return $d ? date('d/m/Y', strtotime($d)) : 'Sem prazo';
    }

    private function norm(?string $s): string
    {
        return mb_strtolower(Str::ascii((string) $s));
    }

    private function esc(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
