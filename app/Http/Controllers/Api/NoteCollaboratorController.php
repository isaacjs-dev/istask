<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteCollaboratorController extends Controller
{
    /** Adiciona um colaborador por e-mail (apenas o dono gerencia o compartilhamento). */
    public function store(Request $request, Note $note)
    {
        Gate::forUser(Workspace::user())->authorize('manageSharing', $note);
        $data = $request->validate([
            'email'      => 'required|email',
            'permission' => 'nullable|in:edit,view',
        ]);

        $invitee = User::where('email', $data['email'])->first();
        // Sem convite para novos usuários: o colaborador precisa já ter conta (limitação documentada).
        abort_unless($invitee !== null, 404, 'Nenhum usuário com esse e-mail.');
        abort_if($invitee->id === $note->user_id, 422, 'O dono da nota já tem acesso total.');
        abort_if($note->collaborators()->where('users.id', $invitee->id)->exists(), 422, 'Esse colaborador já foi adicionado.');

        $note->collaborators()->attach($invitee->id, [
            'permission' => $data['permission'] ?? 'edit',
            'invited_by' => Workspace::user()->id,
        ]);

        return response()->json(['note' => $note->fresh()->toApiArray()]);
    }

    /** Remove um colaborador: o dono remove qualquer um; o colaborador pode remover a si mesmo. */
    public function destroy(Note $note, User $user)
    {
        $me = Workspace::user();
        abort_unless($me->id === $note->user_id || $me->id === $user->id, 403);
        $note->collaborators()->detach($user->id);

        return response()->json(['note' => $note->fresh()->toApiArray()]);
    }
}
