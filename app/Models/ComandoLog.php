<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComandoLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'frase_original', 'intent_resolvido', 'parametros', 'confianca', 'executado', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'parametros' => 'array',
            'confianca'  => 'float',
            'executado'  => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
