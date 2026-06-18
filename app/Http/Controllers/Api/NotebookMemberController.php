<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notebook;
use App\Models\User;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Http\Request;

/** Compartilhamento de Cadernos (dono da área adiciona/remove; membro pode sair). */
class NotebookMemberController extends Controller
{
    public function __construct(private TaskRepository $repo)
    {
    }

    private function isOwner(Notebook $notebook, User $me): bool
    {
        return $notebook->workspace && $notebook->workspace->owner_id === $me->id;
    }

    public function store(Request $request, Notebook $notebook)
    {
        $me = Workspace::user();
        abort_unless($this->isOwner($notebook, $me), 403);
        $data = $request->validate([
            'email'      => 'required|email',
            'permission' => 'nullable|in:edit,view',
        ]);
        $invitee = User::where('email', $data['email'])->first();
        abort_unless($invitee !== null, 404, 'Nenhum usuário com esse e-mail.');
        abort_if($invitee->id === optional($notebook->workspace)->owner_id, 422, 'O dono já tem acesso total.');

        $perm = $data['permission'] ?? 'edit';
        if ($notebook->members()->where('users.id', $invitee->id)->exists()) {
            $notebook->members()->updateExistingPivot($invitee->id, ['permission' => $perm]);
        } else {
            $notebook->members()->attach($invitee->id, ['permission' => $perm, 'invited_by' => $me->id]);
        }

        return response()->json(['notebooks' => $this->repo->notebooksPayload($me)]);
    }

    public function destroy(Notebook $notebook, User $user)
    {
        $me = Workspace::user();
        abort_unless($this->isOwner($notebook, $me) || $me->id === $user->id, 403);
        $notebook->members()->detach($user->id);

        return response()->json(['notebooks' => $this->repo->notebooksPayload($me)]);
    }
}
