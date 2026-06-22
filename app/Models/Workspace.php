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
 * Área de Trabalho: agrupa projetos (listas de tarefas) e cadernos (notas).
 * Pertence a um dono (owner). Compartilhamento entra na Fase 2.
 * Obs.: não confundir com o helper App\Support\Workspace (usuário atual).
 */
class Workspace extends Model
{
    use SoftDeletes;

    protected $fillable = ['owner_id', 'name', 'icon', 'position'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function notebooks(): HasMany
    {
        return $this->hasMany(Notebook::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot(['permission', 'invited_by'])->withTimestamps();
    }

    public function toApiArray(): array
    {
        $me = auth()->user();

        return [
            'id'         => (string) $this->id,
            'name'       => $this->name,
            'icon'       => $this->icon,
            'position'   => (int) $this->position,
            'isOwner'    => $this->owner_id === optional($me)->id,
            'ownerName'  => optional($this->owner)->name,
            'ownerAvatarUrl' => optional($this->owner)->avatarUrl,
            'permission' => $me ? Access::workspacePermission($me, $this) : null,
            'members'    => $this->members->map(fn (User $u) => [
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
