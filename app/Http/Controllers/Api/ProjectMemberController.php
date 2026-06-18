<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Http\Request;

/** Compartilhamento de Projetos (dono adiciona/remove; membro pode sair). */
class ProjectMemberController extends Controller
{
    public function __construct(private TaskRepository $repo)
    {
    }

    public function store(Request $request, Project $project)
    {
        $me = Workspace::user();
        abort_unless($project->user_id === $me->id, 403);
        $data = $request->validate([
            'email'      => 'required|email',
            'permission' => 'nullable|in:edit,view',
        ]);
        $invitee = User::where('email', $data['email'])->first();
        abort_unless($invitee !== null, 404, 'Nenhum usuário com esse e-mail.');
        abort_if($invitee->id === $project->user_id, 422, 'O dono já tem acesso total.');

        $perm = $data['permission'] ?? 'edit';
        if ($project->members()->where('users.id', $invitee->id)->exists()) {
            $project->members()->updateExistingPivot($invitee->id, ['permission' => $perm]);
        } else {
            $project->members()->attach($invitee->id, ['permission' => $perm, 'invited_by' => $me->id]);
        }

        return response()->json(['projects' => $this->repo->projectsPayload($me)]);
    }

    public function destroy(Project $project, User $user)
    {
        $me = Workspace::user();
        abort_unless($me->id === $project->user_id || $me->id === $user->id, 403);
        $project->members()->detach($user->id);

        return response()->json(['projects' => $this->repo->projectsPayload($me)]);
    }
}
