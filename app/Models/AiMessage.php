<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'project_id', 'conversation_id', 'role', 'message', 'card', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'card'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function toApiArray(): array
    {
        return [
            'id'   => $this->id,
            'role' => $this->role,
            'text' => $this->message,
            'card' => $this->card,
        ];
    }
}
