<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notebook;
use App\Models\Project;
use App\Models\Workspace as WorkspaceModel;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Http\Request;

/**
 * Áreas de Trabalho (CRUD + reordenar). Escopo por dono nesta fase;
 * compartilhamento entra na Fase 2.
 */
class WorkspaceController extends Controller
{
    public function __construct(private TaskRepository $repo)
    {
    }

    /** Listas que o front sincroniza após cada operação. */
    private function payload($user): array
    {
        return [
            'workspaces' => $this->repo->workspacesPayload($user),
            'projects'   => $this->repo->projectsPayload($user),
            'notebooks'  => $this->repo->notebooksPayload($user),
        ];
    }

    public function store(Request $request)
    {
        $user = Workspace::user();
        $name = trim($request->validate(['name' => 'required|string|max:120'])['name']);

        $workspace = WorkspaceModel::create([
            'owner_id' => $user->id,
            'name'     => $name,
            'position' => ($user->ownedWorkspaces()->max('position') ?? -1) + 1,
        ]);
        // toda área nasce utilizável para notas com um caderno padrão
        Notebook::create(['workspace_id' => $workspace->id, 'name' => 'Geral', 'position' => 0]);

        return response()->json($this->payload($user) + ['workspace' => $workspace->toApiArray()], 201);
    }

    public function update(Request $request, WorkspaceModel $workspace)
    {
        $user = Workspace::user();
        abort_unless($workspace->owner_id === $user->id, 404);
        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'icon'        => 'sometimes|nullable|string|max:32',
            'description' => 'sometimes|nullable|string|max:2000',
            'startDate'   => 'sometimes|nullable|date',
            'endDate'     => 'sometimes|nullable|date',
            'status'      => 'sometimes|nullable|in:ativo,pausado,concluido,arquivado',
        ]);
        if (isset($data['name'])) {
            $workspace->name = trim($data['name']);
        }
        if (array_key_exists('icon', $data)) {
            $workspace->icon = $data['icon'];
        }
        if (array_key_exists('description', $data)) {
            $workspace->description = $data['description'];
        }
        if (array_key_exists('startDate', $data)) {
            $workspace->start_date = $data['startDate'] ?: null;
        }
        if (array_key_exists('endDate', $data)) {
            $workspace->end_date = $data['endDate'] ?: null;
        }
        if (! empty($data['status'])) {
            $workspace->status = $data['status'];
        }
        $workspace->save();

        return response()->json($this->payload($user));
    }

    /** Exclui a área; o conteúdo (projetos e cadernos) migra para outra área do usuário. */
    public function destroy(WorkspaceModel $workspace)
    {
        $user = Workspace::user();
        abort_unless($workspace->owner_id === $user->id, 404);
        abort_if($user->ownedWorkspaces()->count() <= 1, 422, 'Você precisa de pelo menos uma área de trabalho.');

        $fallback = $user->ownedWorkspaces()->whereKeyNot($workspace->id)->orderBy('position')->first();
        Project::where('workspace_id', $workspace->id)->update(['workspace_id' => $fallback->id]);
        Notebook::where('workspace_id', $workspace->id)->update(['workspace_id' => $fallback->id]);
        $workspace->delete();

        return response()->json($this->payload($user) + ['fallbackId' => (string) $fallback->id]);
    }

    public function reorder(Request $request)
    {
        $user = Workspace::user();
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        foreach (array_values($data['ids']) as $position => $id) {
            $user->ownedWorkspaces()->whereKey($id)->update(['position' => $position]);
        }

        return response()->json($this->payload($user));
    }

    /** Transfere a propriedade da área (e dos projetos dentro dela) para um membro. */
    public function transfer(Request $request, WorkspaceModel $workspace)
    {
        $user = Workspace::user();
        abort_unless($workspace->owner_id === $user->id, 403);
        $newOwnerId = (int) $request->validate(['user_id' => 'required|integer'])['user_id'];
        abort_if($newOwnerId === $workspace->owner_id, 422, 'Essa pessoa já é a proprietária.');
        abort_unless($workspace->members()->where('users.id', $newOwnerId)->exists(), 422, 'O novo proprietário precisa ser um membro da área.');

        $oldOwnerId = $workspace->owner_id;
        $workspace->owner_id = $newOwnerId;
        $workspace->save();
        Project::where('workspace_id', $workspace->id)->update(['user_id' => $newOwnerId]);
        $workspace->members()->detach($newOwnerId);                                   // novo dono deixa de ser membro
        $workspace->members()->syncWithoutDetaching([$oldOwnerId => ['permission' => 'edit', 'invited_by' => $newOwnerId]]); // antigo dono vira editor

        return response()->json($this->payload($user));
    }
}
