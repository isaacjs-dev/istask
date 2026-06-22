<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Workspace;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    /** Atualiza as preferências de UI do usuário (posição/tamanho da barra de comandos). */
    public function update(Request $request)
    {
        $data = $request->validate([
            'theme'           => 'sometimes|in:claro,sepia,escuro,escuro-suave',
            'chatPosition'    => 'sometimes|in:side,bottom',
            'chatWidth'       => 'sometimes|integer|min:300|max:640',
            'chatHeight'      => 'sometimes|integer|min:200|max:720',
            'chatCollapsed'   => 'sometimes|boolean',
            'assistantName'   => 'sometimes|string|max:40',
            'assistantAvatar' => 'sometimes|string|in:default,robot,assistant,person1,person2,person3,comet,owl,bolt',
            'workdayStart'    => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'workdayEnd'      => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'notesViewMode'   => 'sometimes|in:grid,list',
            'activeWorkspaceId' => 'sometimes|nullable|integer',
            'workspaceGrouping' => 'sometimes|in:merged,separated',
            'notebookGrouping'  => 'sometimes|in:merged,separated',
            'activityRange'     => 'sometimes|in:day,week,month',
            'teamActivityEnabled' => 'sometimes|boolean',
            'aiActivityLog'     => 'sometimes|boolean',
        ]);

        $user = Workspace::user();
        $prefs = array_merge($user->prefs(), $data);
        $user->preferences = $prefs;
        $user->save();

        return response()->json(['prefs' => $user->prefs()]);
    }
}
