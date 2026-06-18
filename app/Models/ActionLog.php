<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionLog extends Model
{
    protected $fillable = [
        'user_id', 'kind', 'entity_type', 'entity_id', 'before', 'after', 'summary', 'echo', 'undone',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after'  => 'array',
            'echo'   => 'array',
            'undone' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
