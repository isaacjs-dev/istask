<?php

namespace App\Services\Commands;

use App\Models\ActionLog;
use App\Models\DiaryEntry;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Pilha de desfazer/refazer. Cada ação que muda estado é registrada com o
 * snapshot "antes"/"depois" da entidade. O undo restaura o status quo; o redo
 * reaplica. Usa soft delete para criação/exclusão.
 *
 *  - undo  → pega a ação aplicada mais recente (undone=false, maior id) e reverte.
 *  - redo  → pega a ação desfeita mais antiga (undone=true, menor id) e reaplica.
 *  - novo registro → limpa a pilha de redo (ações undone=true).
 */
class ActionRecorder
{
    private const MODELS = [
        'task'    => Task::class,
        'project' => Project::class,
        'note'    => Note::class,
        'diary'   => DiaryEntry::class,
    ];

    /** Registra uma ação que mudou estado. */
    public function record(User $user, string $kind, string $entityType, ?int $entityId, ?array $before, ?array $after, string $summary, ?array $echo = null): ActionLog
    {
        // qualquer ação nova invalida a pilha de "refazer"
        ActionLog::where('user_id', $user->id)->where('undone', true)->delete();

        return ActionLog::create([
            'user_id'     => $user->id,
            'kind'        => $kind,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'before'      => $before,
            'after'       => $after,
            'summary'     => $summary,
            'echo'        => $echo,
            'undone'      => false,
        ]);
    }

    public function canUndo(User $user): bool
    {
        return ActionLog::where('user_id', $user->id)->where('undone', false)->exists();
    }

    /** Desfaz a última ação. @return array{reply:string,echo:?array}|null */
    public function undo(User $user): ?array
    {
        $log = ActionLog::where('user_id', $user->id)->where('undone', false)->orderByDesc('id')->first();
        if (! $log) {
            return null;
        }
        $this->revert($log);
        $log->undone = true;
        $log->save();

        return [
            'reply' => '↩️ <b>Desfeito.</b> ' . $log->summary,
            'echo'  => $this->invertEcho($log->echo),
        ];
    }

    /** Refaz a última ação desfeita. @return array{reply:string,echo:?array}|null */
    public function redo(User $user): ?array
    {
        $log = ActionLog::where('user_id', $user->id)->where('undone', true)->orderBy('id')->first();
        if (! $log) {
            return null;
        }
        $this->reapply($log);
        $log->undone = false;
        $log->save();

        return [
            'reply' => '↪️ <b>Refeito.</b> ' . $log->summary,
            'echo'  => $log->echo,
        ];
    }

    private function revert(ActionLog $log): void
    {
        $model = $this->model($log->entity_type, $log->entity_id);
        if (! $model) {
            return;
        }
        match ($log->kind) {
            'create' => $model->delete(),                 // criar → desfazer = soft delete
            'delete' => $model->restore(),                // excluir → desfazer = restaurar
            'update' => $this->applySnapshot($model, $log->before ?? []),
            default  => null,
        };
    }

    private function reapply(ActionLog $log): void
    {
        $model = $this->model($log->entity_type, $log->entity_id);
        if (! $model) {
            return;
        }
        match ($log->kind) {
            'create' => $model->restore(),
            'delete' => $model->delete(),
            'update' => $this->applySnapshot($model, $log->after ?? []),
            default  => null,
        };
    }

    private function applySnapshot(Model $model, array $snapshot): void
    {
        foreach ($snapshot as $column => $value) {
            $model->{$column} = $value;
        }
        $model->save();
    }

    private function model(string $type, ?int $id): ?Model
    {
        $class = self::MODELS[$type] ?? null;
        if (! $class || ! $id) {
            return null;
        }

        return $class::withTrashed()->find($id);
    }

    private function invertEcho(?array $echo): ?array
    {
        if (! $echo) {
            return null;
        }
        if (isset($echo['before']) || isset($echo['after'])) {
            [$echo['before'], $echo['after']] = [$echo['after'] ?? null, $echo['before'] ?? null];
        }

        return $echo;
    }
}
