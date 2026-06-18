<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'user_id', 'title', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function displayTitle(): string
    {
        return $this->title ?: 'Nova conversa';
    }

    public function toApiArray(): array
    {
        return [
            'id'        => (string) $this->id,
            'title'     => $this->displayTitle(),
            'archived'  => $this->archived_at !== null,
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
            'count'     => $this->messages_count ?? $this->messages()->count(),
        ];
    }
}
