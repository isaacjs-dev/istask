<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Workspace as WorkspaceModel;
use App\Services\Commands\ActionRecorder;
use App\Support\Access;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function __construct(private ActionRecorder $recorder)
    {
    }

    /** Cria um projeto (botão "+" da sidebar; o chat usa o mesmo executor). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'workspace_id' => 'sometimes|integer',
        ]);
        $name = trim($data['name']);
        $user = Workspace::user();

        if ($user->projects()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            return response()->json(['message' => 'Já existe um projeto com esse nome.'], 422);
        }

        $workspaceId = $data['workspace_id'] ?? null;
        if ($workspaceId) {
            // permite criar em área própria OU compartilhada comigo com permissão de edição
            $ws = WorkspaceModel::find($workspaceId);
            abort_unless($ws && Access::can(Access::workspacePermission($user, $ws), 'edit'), 404);
        } else {
            $workspaceId = optional($user->ownedWorkspaces()->orderBy('position')->first())->id;
        }

        $project = $user->projects()->create([
            'workspace_id' => $workspaceId,
            'slug'         => $this->uniqueSlug($user, $name),
            'name'         => $name,
            'icon'         => 'Folder',
            'position'     => ($user->projects()->max('position') ?? 0) + 1,
        ]);

        $this->recorder->record($user, 'create', 'project', $project->id, null, ['name' => $name, 'slug' => $project->slug], 'Projeto criado: ' . $name, ['kind' => 'project']);

        return response()->json([
            'project'  => ['id' => $project->id, 'slug' => $project->slug, 'name' => $project->name, 'icon' => $project->icon, 'workspaceId' => $workspaceId ? (string) $workspaceId : null],
            'projects' => app(TaskRepository::class)->projectsPayload($user),
        ], 201);
    }

    /** Move o projeto para outra Área de Trabalho do usuário. */
    public function move(Request $request, Project $project)
    {
        $user = Workspace::user();
        abort_unless($project->user_id === $user->id, 404);
        $data = $request->validate(['workspace_id' => 'required|integer']);
        abort_unless($user->ownedWorkspaces()->whereKey($data['workspace_id'])->exists(), 404);

        $project->workspace_id = $data['workspace_id'];
        $project->save();

        return response()->json(['projects' => app(TaskRepository::class)->projectsPayload($user)]);
    }

    /** Transfere a propriedade do projeto para um membro (do projeto ou da área). */
    public function transfer(Request $request, Project $project)
    {
        $user = Workspace::user();
        abort_unless($project->user_id === $user->id, 403);
        $newOwnerId = (int) $request->validate(['user_id' => 'required|integer'])['user_id'];
        abort_if($newOwnerId === $project->user_id, 422, 'Essa pessoa já é a proprietária.');
        $hasAccess = $project->members()->where('users.id', $newOwnerId)->exists()
            || ($project->workspace && $project->workspace->members()->where('users.id', $newOwnerId)->exists());
        abort_unless($hasAccess, 422, 'O novo proprietário precisa ter acesso ao projeto.');

        $oldOwnerId = $project->user_id;
        $project->user_id = $newOwnerId;
        $project->save();
        $project->members()->detach($newOwnerId);
        $project->members()->syncWithoutDetaching([$oldOwnerId => ['permission' => 'edit', 'invited_by' => $newOwnerId]]);

        return response()->json(['projects' => app(TaskRepository::class)->projectsPayload($user)]);
    }

    /** Renomeia um projeto. */
    public function update(Request $request, Project $project)
    {
        $user = Workspace::user();
        abort_unless($project->user_id === $user->id, 404);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'sometimes|nullable|string|max:2000',
            'startDate'   => 'sometimes|nullable|date',
            'dueDate'     => 'sometimes|nullable|date',
            'completedAt' => 'sometimes|nullable|date',
            'status'      => 'sometimes|nullable|in:nao_iniciado,em_andamento,concluido,pausado',
            'priority'    => 'sometimes|nullable|in:urgente,alta,media,baixa',
        ]);

        if (array_key_exists('name', $data)) {
            $name = trim($data['name']);
            if ($user->projects()->whereKeyNot($project->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                return response()->json(['message' => 'Já existe um projeto com esse nome.'], 422);
            }
            $project->name = $name;
        }
        if (array_key_exists('description', $data)) {
            $project->description = $data['description'];
        }
        if (array_key_exists('startDate', $data)) {
            $project->start_date = $data['startDate'] ?: null;
        }
        if (array_key_exists('dueDate', $data)) {
            $project->due_date = $data['dueDate'] ?: null;
        }
        if (array_key_exists('completedAt', $data)) {
            $project->completed_at = $data['completedAt'] ?: null;
        }
        if (! empty($data['status'])) {
            $project->status = $data['status'];
            if ($data['status'] === 'concluido' && ! $project->completed_at) {
                $project->completed_at = now();
            }
        }
        if (! empty($data['priority'])) {
            $project->priority = $data['priority'];
        }
        $project->save();

        $this->recorder->record($user, 'update', 'project', $project->id, null, ['name' => $project->name], "Projeto atualizado: {$project->name}", ['kind' => 'project']);

        return response()->json([
            'projects' => app(TaskRepository::class)->projectsPayload($user),
        ]);
    }

    /** Exclui um projeto (não permitido para "Geral"); tarefas voltam para "Geral". */
    public function destroy(Project $project)
    {
        $user = Workspace::user();
        abort_unless($project->user_id === $user->id, 404);
        abort_if($project->slug === 'geral', 422, 'O projeto Geral não pode ser excluído.');

        $geral = Workspace::defaultProject();
        $project->tasks()->update(['project_id' => $geral->id]);
        $project->delete();

        return response()->json([
            'projects' => app(TaskRepository::class)->projectsPayload($user),
            'tasks'    => app(TaskRepository::class)->tasksFor($user),
        ]);
    }

    private function uniqueSlug($user, string $name): string
    {
        $base = Str::slug($name) ?: 'projeto';
        $slug = $base;
        $i = 1;
        while ($user->projects()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }

        return $slug;
    }
}
