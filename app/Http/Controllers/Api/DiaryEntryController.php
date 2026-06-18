<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiaryEntry;
use App\Models\Task;
use App\Models\User;
use App\Services\Commands\ActionRecorder;
use App\Services\Diary\DiaryService;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DiaryEntryController extends Controller
{
    public function __construct(private ActionRecorder $recorder, private DiaryService $diary)
    {
    }

    /** Lista do diário do usuário (para o front recarregar após movimentações). */
    public function index()
    {
        $user = Workspace::user();

        return response()->json([
            'diaryEntries' => $user->diaryEntries()
                ->with(['task', 'project', 'attachments', 'histories'])
                ->latest('started_at')
                ->limit(120)
                ->get()
                ->map->toApiArray()
                ->all(),
        ]);
    }

    public function store(Request $request)
    {
        $user = Workspace::user();
        $data = $request->validate($this->rules());
        $this->guardTask($data['task_id'] ?? null, $user);

        $task = ! empty($data['task_id']) ? Task::find($data['task_id']) : null;
        $entry = $user->diaryEntries()->create([
            'task_id'       => $data['task_id'] ?? null,
            'project_id'    => $task?->project_id,
            'title'         => $data['title'] ?? $task?->title,
            'activity_type' => $data['activity_type'] ?? ($task ? $this->diary->suggestType($task) : 'O'),
            'source'        => 'manual',
            'moved_by'      => $user->name,
            'started_at'    => $data['started_at'] ?? now(),
            'ended_at'      => $data['ended_at'] ?? null,
            'description'   => $data['description'] ?? null,
            'observations'  => $data['observations'] ?? null,
            'results'       => $data['results'] ?? null,
            'difficulties'  => $data['difficulties'] ?? null,
            'next_steps'    => $data['next_steps'] ?? null,
            'progress'      => $data['progress'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
        ]);
        $entry->logHistory('created', 'Atividade criada manualmente', null, $user->name, $user->id);

        $this->recorder->record(
            $user, 'create', 'diary', $entry->id, null,
            $entry->only(['task_id', 'started_at', 'ended_at', 'description']),
            'Entrada de diário criada', ['kind' => 'diary', 'canUndo' => true]
        );

        return response()->json(['entry' => $this->present($entry)], 201);
    }

    public function update(Request $request, DiaryEntry $entry)
    {
        $user = Workspace::user();
        abort_unless($entry->user_id === $user->id, 404);

        $data = $request->validate($this->rules(update: true));
        if (array_key_exists('task_id', $data)) {
            $this->guardTask($data['task_id'], $user);
            if ($data['task_id']) {
                $entry->project_id = Task::find($data['task_id'])?->project_id;
            }
        }

        // Campos de movimentação (status_from/to, source, movement_key, moved_by) são protegidos:
        // o complemento manual nunca os apaga (item 7). $data só traz os campos enviados.
        $before = $entry->only(['task_id', 'started_at', 'ended_at', 'description']);
        $timeBefore = $this->timeSnapshot($entry);

        $entry->fill($data);
        $this->validatePeriod($entry);
        $entry->save();

        $timeAfter = $this->timeSnapshot($entry);
        if ($timeBefore !== $timeAfter) {
            $entry->logHistory('time_adjusted', 'Tempo ajustado manualmente', ['before' => $timeBefore, 'after' => $timeAfter], $user->name, $user->id);
        } else {
            $entry->logHistory('edited', 'Registro complementado', null, $user->name, $user->id);
        }

        $this->recorder->record(
            $user, 'update', 'diary', $entry->id, $before,
            $entry->only(['task_id', 'started_at', 'ended_at', 'description']),
            'Entrada de diário atualizada', ['kind' => 'diary', 'canUndo' => true]
        );

        return response()->json(['entry' => $this->present($entry)]);
    }

    public function destroy(DiaryEntry $entry)
    {
        $user = Workspace::user();
        abort_unless($entry->user_id === $user->id, 404);
        $before = $entry->only(['task_id', 'started_at', 'ended_at', 'description']);
        $entry->delete();
        $this->recorder->record(
            $user, 'delete', 'diary', $entry->id, $before, null,
            'Entrada de diário excluída', ['kind' => 'diary', 'canUndo' => true]
        );

        return response()->json(['deleted' => true]);
    }

    /** @return array<string,mixed> */
    private function rules(bool $update = false): array
    {
        $when = $update ? 'sometimes' : 'nullable';

        return [
            'title'            => 'nullable|string|max:255',
            'description'      => 'nullable|string',
            'activity_type'    => 'nullable|string|size:1',
            'task_id'          => 'nullable|integer|exists:tasks,id',
            'started_at'       => "$when|date",
            'ended_at'         => 'nullable|date',
            'observations'     => 'nullable|string',
            'results'          => 'nullable|string',
            'difficulties'     => 'nullable|string',
            'next_steps'       => 'nullable|string',
            'progress'         => 'nullable|integer|min:0|max:100',
            'duration_minutes' => 'nullable|integer|min:0',
        ];
    }

    /** Validações de período (item 11): término ≥ início e mesmo dia (sem atravessar dias). */
    private function validatePeriod(DiaryEntry $entry): void
    {
        if (! $entry->started_at || ! $entry->ended_at) {
            return;
        }
        if ($entry->ended_at->lessThan($entry->started_at)) {
            throw ValidationException::withMessages(['ended_at' => 'O término não pode ser anterior ao início.']);
        }
        if (! $entry->started_at->isSameDay($entry->ended_at)) {
            throw ValidationException::withMessages(['ended_at' => 'Uma atividade não pode atravessar dias; divida em registros diários.']);
        }
    }

    /** @return array<string,mixed> */
    private function timeSnapshot(DiaryEntry $entry): array
    {
        return [
            'started_at'       => optional($entry->started_at)->toDateTimeString(),
            'ended_at'         => optional($entry->ended_at)->toDateTimeString(),
            'duration_minutes' => $entry->duration_minutes,
        ];
    }

    private function present(DiaryEntry $entry): array
    {
        return $entry->fresh(['task', 'project', 'attachments', 'histories'])->toApiArray();
    }

    private function guardTask(?int $taskId, User $user): void
    {
        if (! $taskId) {
            return;
        }
        $task = Task::find($taskId);
        abort_unless($task && $task->project && $task->project->user_id === $user->id, 422, 'Tarefa inválida.');
    }
}
