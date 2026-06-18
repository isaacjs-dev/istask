<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'attachable_type', 'attachable_id', 'user_id', 'disk', 'path',
        'original_name', 'mime', 'size', 'origin', 'source_attachment_id',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'source_attachment_id');
    }

    public function url(): string
    {
        return Storage::disk($this->disk ?: 'public')->url($this->path);
    }

    public function toApiArray(): array
    {
        return [
            'id'       => (string) $this->id,
            'name'     => $this->original_name,
            'url'      => $this->url(),
            'mime'     => $this->mime,
            'size'     => $this->size,
            'origin'   => $this->origin,        // own | task
            'sourceId' => $this->source_attachment_id ? (string) $this->source_attachment_id : null,
        ];
    }
}
