<?php

namespace App\Support;

use App\Models\Note;
use App\Models\Notebook;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;

/**
 * Resolve a permissão efetiva (owner|edit|view|null) de um usuário sobre os
 * recursos, com herança em cascata: a permissão numa Área de Trabalho vale para
 * seus projetos/cadernos e, por consequência, para suas tarefas/notas. Permissões
 * diretas (no projeto, caderno ou nota) também valem; vence sempre o maior nível.
 *
 * Usa as relações `members`/`collaborators` (carregue-as no bootstrap para evitar
 * N+1; em checagens unitárias o Eloquent faz lazy-load).
 */
class Access
{
    private const RANK = ['view' => 1, 'edit' => 2, 'owner' => 3];

    /** @param array<int,?string> $perms */
    private static function best(array $perms): ?string
    {
        $best = null;
        $bestRank = 0;
        foreach ($perms as $p) {
            $r = $p ? (self::RANK[$p] ?? 0) : 0;
            if ($r > $bestRank) {
                $bestRank = $r;
                $best = $p;
            }
        }

        return $best;
    }

    public static function can(?string $permission, string $needed): bool
    {
        if ($permission === null) {
            return false;
        }

        return self::RANK[$permission] >= self::RANK[$needed];
    }

    public static function workspacePermission(User $user, ?Workspace $workspace): ?string
    {
        if (! $workspace) {
            return null;
        }
        if ($workspace->owner_id === $user->id) {
            return 'owner';
        }
        $m = $workspace->members->firstWhere('id', $user->id);

        return $m ? $m->pivot->permission : null;
    }

    public static function projectPermission(User $user, ?Project $project): ?string
    {
        if (! $project) {
            return null;
        }
        if ($project->user_id === $user->id) {
            return 'owner';
        }
        $perms = [];
        $m = $project->members->firstWhere('id', $user->id);
        if ($m) {
            $perms[] = $m->pivot->permission;
        }
        $perms[] = self::inherit(self::workspacePermission($user, $project->workspace));

        return self::best($perms);
    }

    public static function notebookPermission(User $user, ?Notebook $notebook): ?string
    {
        if (! $notebook) {
            return null;
        }
        if ($notebook->workspace && $notebook->workspace->owner_id === $user->id) {
            return 'owner';
        }
        $perms = [];
        $m = $notebook->members->firstWhere('id', $user->id);
        if ($m) {
            $perms[] = $m->pivot->permission;
        }
        $perms[] = self::inherit(self::workspacePermission($user, $notebook->workspace));

        return self::best($perms);
    }

    public static function taskPermission(User $user, Task $task): ?string
    {
        return self::projectPermission($user, $task->project);
    }

    public static function notePermission(User $user, Note $note): ?string
    {
        if ($note->user_id === $user->id) {
            return 'owner';
        }
        $perms = [];
        $c = $note->collaborators->firstWhere('id', $user->id);
        if ($c) {
            $perms[] = $c->pivot->permission;
        }
        $perms[] = self::inherit(self::notebookPermission($user, $note->notebook));

        return self::best($perms);
    }

    /** Permissão herdada de um container: o dono do container vira "edit" no filho (não dono). */
    private static function inherit(?string $parentPermission): ?string
    {
        if ($parentPermission === 'owner') {
            return 'edit';
        }

        return $parentPermission; // edit|view|null passam direto
    }
}
