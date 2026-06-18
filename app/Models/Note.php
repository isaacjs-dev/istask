<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Support\Access;
use App\Support\Initials;
use App\Support\Workspace;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Note extends Model
{
    use SoftDeletes;

    /** Cores aceitas pelo seletor de cor das notas (front-end espelha esta lista em notes-page.js). */
    public const COLORS = [
        'yellow', 'pink', 'mint', 'blue', 'lilac', 'peach',
        'gray', 'teal', 'coral', 'sand', 'sage', 'rose',
    ];

    /** Padrões de fundo aceitos (overlay decorativo sobre --note-bg). */
    public const PATTERNS = ['dots', 'lines', 'grid'];

    protected $fillable = [
        'user_id', 'notebook_id', 'title', 'body', 'tags', 'color',
        'pinned', 'archived_at', 'type', 'pattern',
        'remind_at', 'remind_recurrence', 'remind_last_fired_at',
    ];

    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'archived_at' => 'datetime',
            'remind_at' => 'datetime',
            'remind_last_fired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::forceDeleting(function (Note $note) {
            $note->labels()->detach();
            $note->collaborators()->detach();
            $note->items()->delete();
            // Remove os arquivos do disco e apaga definitivamente os registros de anexo
            // (relação polimórfica não tem cascade de FK; cobre também os já em soft delete).
            $note->attachments()->withTrashed()->get()->each(function ($attachment) {
                Storage::disk($attachment->disk ?: 'public')->delete($attachment->path);
                $attachment->forceDelete();
            });
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'label_note')->withTimestamps();
    }

    public function notebook(): BelongsTo
    {
        return $this->belongsTo(Notebook::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(NoteItem::class)->orderBy('position')->orderBy('id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'note_collaborators')
            ->withPivot(['permission', 'invited_by'])
            ->withTimestamps();
    }

    /** Notas não arquivadas (grid principal). */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    /** Notas arquivadas. */
    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function toApiArray(): array
    {
        return [
            'id'         => (string) $this->id,
            'title'      => $this->title,
            'body'       => $this->body,
            'tags'       => $this->tags,
            'color'      => $this->color,
            'pinned'     => (bool) $this->pinned,
            'archivedAt' => optional($this->archived_at)->toIso8601String(),
            'type'       => $this->type,
            'pattern'    => $this->pattern,
            'notebookId' => $this->notebook_id ? (string) $this->notebook_id : null,
            'remindAt'         => optional($this->remind_at)->toIso8601String(),
            'remindRecurrence' => $this->remind_recurrence,
            'labels'     => $this->labels->map->toApiArray()->all(),
            'items'      => $this->items->map->toApiArray()->all(),
            'attachments' => $this->attachments->map->toApiArray()->all(),
            'collaborators' => $this->collaborators->map(fn (User $u) => [
                'id'         => (string) $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'avatarUrl'  => $u->avatarUrl,
                'initials'   => Initials::of($u->name),
                'permission' => $u->pivot->permission,
            ])->all(),
            'isOwner'    => $this->user_id === optional(Workspace::user())->id,
            'ownerName'  => optional($this->user)->name,
            'permission' => ($me = auth()->user()) ? Access::notePermission($me, $this) : null,
            'updatedAt'  => optional($this->updated_at)->toIso8601String(),
            'createdAt'  => optional($this->created_at)->toIso8601String(),
        ];
    }
}
