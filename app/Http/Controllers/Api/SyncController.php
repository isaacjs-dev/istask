<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Sincronização por polling (Fase 3 da revisão geral). O front chama
 * GET /api/sync?since=<ISO8601> periodicamente; quando há mudança em conteúdo
 * ACESSÍVEL desde `since`, devolve as listas atualizadas (mescladas via
 * window.App.applyPayload no front). Respeita estritamente as permissões — só
 * retorna o que o usuário pode ver.
 */
class SyncController extends Controller
{
    public function __construct(private TaskRepository $repo)
    {
    }

    public function index(Request $request)
    {
        $user = Workspace::user();
        $now = now()->toIso8601String();
        $since = $request->query('since');

        if (! $since) {
            return response()->json(['changed' => false, 'now' => $now]); // estabelece a linha de base
        }
        try {
            $sinceDt = Carbon::parse($since);
        } catch (\Throwable $e) {
            return response()->json(['changed' => false, 'now' => $now]);
        }

        if (! $this->repo->hasChangesSince($user, $sinceDt)) {
            return response()->json(['changed' => false, 'now' => $now]);
        }

        return response()->json([
            'changed'    => true,
            'now'        => $now,
            'tasks'      => $this->repo->tasksFor($user),
            'notes'      => $this->repo->notesPayload($user),
            'projects'   => $this->repo->projectsPayload($user),
            'notebooks'  => $this->repo->notebooksPayload($user),
            'workspaces' => $this->repo->workspacesPayload($user),
            'labels'     => $user->labels()->orderBy('name')->get()->map->toApiArray()->all(),
        ]);
    }
}
