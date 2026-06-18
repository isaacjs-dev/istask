<?php

namespace App\Models;

use App\Support\Access;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'title', 'description', 'status', 'priority',
        'section', 'due_date', 'responsible', 'position',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date'     => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TaskStep::class)->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at');
    }

    public function history(): HasMany
    {
        return $this->hasMany(TaskHistory::class)->orderBy('created_at');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest('id');
    }

    /**
     * Registra uma entrada no histórico desta tarefa.
     */
    public function logHistory(string $action, string $actor = 'Você', ?int $userId = null, ?string $old = null, ?string $new = null): TaskHistory
    {
        return $this->history()->create([
            'user_id'    => $userId,
            'actor'      => $actor,
            'action'     => $action,
            'old_value'  => $old,
            'new_value'  => $new,
            'created_at' => now(),
        ]);
    }

    /**
     * Serializa a tarefa exatamente no formato que o frontend (protótipo) espera.
     */
    public function toApiArray(): array
    {
        // IDs serializados como string — o frontend (protótipo) compara IDs com
        // igualdade estrita contra valores vindos do dataset (sempre string).
        return [
            'id'          => (string) $this->id,
            'section'     => $this->section,
            '_section'    => $this->section,
            'title'       => $this->title,
            'description' => $this->description ?? '',
            'status'      => $this->status,
            'priority'    => $this->priority,
            'project'     => $this->project?->slug ?? 'geral',
            'projectName' => $this->project?->name ?? 'Geral',
            'workspaceId' => optional($this->project)->workspace_id ? (string) $this->project->workspace_id : null,
            'permission'  => ($me = auth()->user()) ? Access::taskPermission($me, $this) : null,
            'due'         => $this->due_date?->format('Y-m-d'),
            'responsible' => $this->responsible,
            'position'    => $this->position,
            'checklist'   => $this->steps->map(fn (TaskStep $s) => [
                'id'   => (string) $s->id,
                'text' => $s->title,
                'done' => $s->status === 'done',
            ])->values()->all(),
            'comments'    => $this->comments->map(fn (TaskComment $c) => [
                'id'       => (string) $c->id,
                'author'   => $c->author,
                'initials' => $c->initials,
                'color'    => $c->color,
                'ai'       => (bool) $c->is_ai,
                'text'     => $c->comment,
                'at'       => optional($c->created_at)->toIso8601String(),
            ])->values()->all(),
            'history'     => $this->history->map(fn (TaskHistory $h) => [
                'id'     => (string) $h->id,
                'action' => $h->action,
                'by'     => $h->actor,
                'at'     => optional($h->created_at)->toIso8601String(),
            ])->values()->all(),
            'attachments' => $this->relationLoaded('attachments')
                ? $this->attachments->map->toApiArray()->values()->all()
                : [],
            'createdAt'   => optional($this->created_at)->toIso8601String(),
            'completedAt' => optional($this->completed_at)->toIso8601String(),
        ];
    }
}
