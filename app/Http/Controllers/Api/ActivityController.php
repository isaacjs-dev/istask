<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace as WorkspaceModel;
use App\Support\Access;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Http\Request;

/** Registro de atividades / timeline (TaskHistory agregado) e visão de time. */
class ActivityController extends Controller
{
    public function __construct(private TaskRepository $repo)
    {
    }

    public function index(Request $request)
    {
        $user = Workspace::user();
        $opts = [];

        if ($request->filled('from')) {
            $opts['from'] = $request->date('from');
        }
        if ($request->filled('to')) {
            $opts['to'] = $request->date('to');
        }
        // Visão de time: exige que o usuário tenha acesso à área (dono ou membro).
        if ($request->filled('workspace')) {
            $wid = (int) $request->input('workspace');
            $ws = WorkspaceModel::find($wid);
            abort_unless($ws && Access::workspacePermission($user, $ws) !== null, 403);
            $opts['workspaceId'] = $wid;
        }

        return response()->json(['activities' => $this->repo->activitiesPayload($user, $opts)]);
    }
}
