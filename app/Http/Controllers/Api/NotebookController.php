<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\Notebook;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Http\Request;

/**
 * Cadernos (CRUD + reordenar). Escopo por dono nesta fase; um caderno pertence a
 * uma Área de Trabalho do usuário.
 */
class NotebookController extends Controller
{
    public function __construct(private TaskRepository $repo)
    {
    }

    private function guard(Notebook $notebook, $user): void
    {
        abort_unless($notebook->workspace && $notebook->workspace->owner_id === $user->id, 404);
    }

    private function ownsWorkspace($user, int $workspaceId): bool
    {
        return $user->ownedWorkspaces()->whereKey($workspaceId)->exists();
    }

    public function store(Request $request)
    {
        $user = Workspace::user();
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'workspace_id' => 'required|integer',
            'color'        => 'nullable|string|max:16',
        ]);
        abort_unless($this->ownsWorkspace($user, (int) $data['workspace_id']), 404);

        $notebook = Notebook::create([
            'workspace_id' => $data['workspace_id'],
            'name'         => trim($data['name']),
            'color'        => $data['color'] ?? null,
            'position'     => (Notebook::where('workspace_id', $data['workspace_id'])->max('position') ?? -1) + 1,
        ]);

        return response()->json([
            'notebook'  => $notebook->toApiArray(),
            'notebooks' => $this->repo->notebooksPayload($user),
        ], 201);
    }

    public function update(Request $request, Notebook $notebook)
    {
        $user = Workspace::user();
        $this->guard($notebook, $user);
        $data = $request->validate([
            'name'  => 'sometimes|string|max:120',
            'color' => 'sometimes|nullable|string|max:16',
        ]);
        if (isset($data['name'])) {
            $notebook->name = trim($data['name']);
        }
        if (array_key_exists('color', $data)) {
            $notebook->color = $data['color'];
        }
        $notebook->save();

        return response()->json(['notebooks' => $this->repo->notebooksPayload($user)]);
    }

    /** Exclui o caderno; as notas migram para outro caderno da mesma área. */
    public function destroy(Notebook $notebook)
    {
        $user = Workspace::user();
        $this->guard($notebook, $user);
        $fallback = Notebook::where('workspace_id', $notebook->workspace_id)
            ->whereKeyNot($notebook->id)->orderBy('position')->first();
        abort_if(! $fallback, 422, 'A área precisa de pelo menos um caderno.');

        Note::withTrashed()->where('notebook_id', $notebook->id)->update(['notebook_id' => $fallback->id]);
        $notebook->delete();

        return response()->json([
            'notebooks' => $this->repo->notebooksPayload($user),
            'notes'     => $this->repo->notesPayload($user),
            'fallbackId' => (string) $fallback->id,
        ]);
    }

    public function reorder(Request $request)
    {
        $user = Workspace::user();
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $ownIds = Notebook::whereIn('workspace_id', $user->ownedWorkspaces()->select('id'))->pluck('id')->all();
        foreach (array_values($data['ids']) as $position => $id) {
            if (in_array((int) $id, $ownIds, true)) {
                Notebook::whereKey($id)->update(['position' => $position]);
            }
        }

        return response()->json(['notebooks' => $this->repo->notebooksPayload($user)]);
    }
}
