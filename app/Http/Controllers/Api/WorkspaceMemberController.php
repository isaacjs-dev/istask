<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace as WorkspaceModel;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Http\Request;

/** Compartilhamento de Áreas de Trabalho (dono adiciona/remove; membro pode sair). */
class WorkspaceMemberController extends Controller
{
    public function __construct(private TaskRepository $repo)
    {
    }

    private function payload(User $me): array
    {
        return [
            'workspaces' => $this->repo->workspacesPayload($me),
            'projects'   => $this->repo->projectsPayload($me),
            'notebooks'  => $this->repo->notebooksPayload($me),
        ];
    }

    public function store(Request $request, WorkspaceModel $workspace)
    {
        $me = Workspace::user();
        abort_unless($workspace->owner_id === $me->id, 403);
        $data = $request->validate([
            'email'      => 'required|email',
            'permission' => 'nullable|in:edit,view',
        ]);
        $invitee = User::where('email', $data['email'])->first();
        abort_unless($invitee !== null, 404, 'Nenhum usuário com esse e-mail.');
        abort_if($invitee->id === $workspace->owner_id, 422, 'O dono já tem acesso total.');

        $perm = $data['permission'] ?? 'edit';
        if ($workspace->members()->where('users.id', $invitee->id)->exists()) {
            $workspace->members()->updateExistingPivot($invitee->id, ['permission' => $perm]);
        } else {
            $workspace->members()->attach($invitee->id, ['permission' => $perm, 'invited_by' => $me->id]);
        }

        return response()->json($this->payload($me));
    }

    public function destroy(WorkspaceModel $workspace, User $user)
    {
        $me = Workspace::user();
        abort_unless($me->id === $workspace->owner_id || $me->id === $user->id, 403);
        $workspace->members()->detach($user->id);

        return response()->json($this->payload($me));
    }
}
