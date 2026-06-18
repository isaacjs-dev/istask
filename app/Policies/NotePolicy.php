<?php

namespace App\Policies;

use App\Models\Note;
use App\Models\User;
use App\Support\Access;

class NotePolicy
{
    public function view(User $user, Note $note): bool
    {
        return Access::notePermission($user, $note) !== null;
    }

    public function update(User $user, Note $note): bool
    {
        return Access::can(Access::notePermission($user, $note), 'edit');
    }

    public function delete(User $user, Note $note): bool
    {
        return $note->user_id === $user->id;
    }

    public function manageSharing(User $user, Note $note): bool
    {
        return $note->user_id === $user->id;
    }
}
