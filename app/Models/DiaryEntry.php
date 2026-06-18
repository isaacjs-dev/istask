<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiaryEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'task_id', 'project_id', 'title', 'activity_type',
        'status_from', 'status_to', 'source', 'movement_key', 'moved_by',
        'started_at', 'ended_at', 'description', 'observations', 'results',
        'difficulties', 'next_steps', 'progress', 'duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'started_at'       => 'datetime',
            'ended_at'         => 'datetime',
            'progress'         => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DiaryEntryHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /** Duração efetiva em minutos: ajuste manual quando definido, senão start→end. */
    public function computedDurationMinutes(): ?int
    {
        if ($this->duration_minutes !== null) {
            return $this->duration_minutes;
        }
        if (! $this->started_at || ! $this->ended_at) {
            return null;
        }

        return (int) $this->started_at->diffInMinutes($this->ended_at);
    }

    /** Registra (append-only) uma entrada na auditoria desta atividade. */
    public function logHistory(string $action, ?string $description = null, ?array $meta = null, string $actor = 'Sistema', ?int $userId = null): DiaryEntryHistory
    {
        return $this->histories()->create([
            'user_id'     => $userId,
            'actor'       => $actor,
            'action'      => $action,
            'description' => $description,
            'meta'        => $meta,
            'created_at'  => now(),
        ]);
    }

    public function toApiArray(): array
    {
        return [
            'id'           => (string) $this->id,
            'taskId'       => $this->task_id ? (string) $this->task_id : null,
            'taskTitle'    => $this->task?->title,
            'projectId'    => $this->project_id ? (string) $this->project_id : null,
            'projectName'  => $this->project?->name,
            'title'        => $this->title ?: ($this->task?->title),
            'activityType' => $this->activity_type,
            'statusFrom'   => $this->status_from,
            'statusTo'     => $this->status_to,
            'source'       => $this->source,
            'movedBy'      => $this->moved_by,
            'startedAt'    => optional($this->started_at)->toIso8601String(),
            'endedAt'      => optional($this->ended_at)->toIso8601String(),
            'description'  => $this->description,
            'observations' => $this->observations,
            'results'      => $this->results,
            'difficulties' => $this->difficulties,
            'nextSteps'    => $this->next_steps,
            'progress'     => $this->progress,
            'durationMinutes' => $this->computedDurationMinutes(),
            'open'         => $this->isOpen(),
            'attachments'  => $this->relationLoaded('attachments')
                ? $this->attachments->map->toApiArray()->values()->all()
                : [],
            'history'      => $this->relationLoaded('histories')
                ? $this->histories->map->toApiArray()->values()->all()
                : [],
        ];
    }
}
