<?php

namespace App\Models;

use App\Support\Access;
use App\Support\Initials;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Caderno: agrupa notas dentro de uma Área de Trabalho (estilo Zoho Notebook).
 * Cada nota pertence a um caderno; etiquetas continuam como tags transversais.
 */
class Notebook extends Model
{
    use SoftDeletes;

    protected $fillable = ['workspace_id', 'name', 'color', 'position'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notebook_members')
            ->withPivot(['permission', 'invited_by'])->withTimestamps();
    }

    public function toApiArray(): array
    {
        $me = auth()->user();
        $owner = optional($this->workspace)->owner;

        return [
            'id'          => (string) $this->id,
            'workspaceId' => (string) $this->workspace_id,
            'name'        => $this->name,
            'color'       => $this->color,
            'position'    => (int) $this->position,
            'isOwner'     => optional($this->workspace)->owner_id === optional($me)->id,
            'ownerName'   => optional($owner)->name,
            'permission'  => $me ? Access::notebookPermission($me, $this) : null,
            'members'     => $this->members->map(fn (User $u) => [
                'id'         => (string) $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'avatarUrl'  => $u->avatarUrl,
                'initials'   => Initials::of($u->name),
                'permission' => $u->pivot->permission,
            ])->all(),
        ];
    }
}
