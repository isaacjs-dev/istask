<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiaryEntryHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'diary_entry_id', 'user_id', 'actor', 'action', 'description', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function diaryEntry(): BelongsTo
    {
        return $this->belongsTo(DiaryEntry::class);
    }

    public function toApiArray(): array
    {
        return [
            'id'          => (string) $this->id,
            'action'      => $this->action,
            'description' => $this->description,
            'by'          => $this->actor,
            'at'          => optional($this->created_at)->toIso8601String(),
        ];
    }
}
