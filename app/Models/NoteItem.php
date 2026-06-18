<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteItem extends Model
{
    protected $fillable = ['note_id', 'text', 'done', 'position'];

    protected function casts(): array
    {
        return ['done' => 'boolean'];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    public function toApiArray(): array
    {
        return [
            'id'       => (string) $this->id,
            'text'     => $this->text,
            'done'     => (bool) $this->done,
            'position' => (int) $this->position,
        ];
    }
}
