<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabelController extends Controller
{
    public function store(Request $request)
    {
        $user = Workspace::user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('labels')->where('user_id', $user->id)],
        ]);
        $label = $user->labels()->create($data);

        return response()->json([
            'label'  => $label->toApiArray(),
            'labels' => $user->labels()->orderBy('name')->get()->map->toApiArray()->all(),
        ], 201);
    }

    public function update(Request $request, Label $label)
    {
        $user = Workspace::user();
        abort_unless($label->user_id === $user->id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('labels')->where('user_id', $user->id)->ignore($label->id)],
        ]);
        $label->fill($data)->save();

        return response()->json([
            'label'  => $label->toApiArray(),
            'labels' => $user->labels()->orderBy('name')->get()->map->toApiArray()->all(),
        ]);
    }

    public function destroy(Label $label)
    {
        $user = Workspace::user();
        abort_unless($label->user_id === $user->id, 404);
        $label->delete();

        return response()->json([
            'deleted' => true,
            'labels'  => $user->labels()->orderBy('name')->get()->map->toApiArray()->all(),
        ]);
    }
}
