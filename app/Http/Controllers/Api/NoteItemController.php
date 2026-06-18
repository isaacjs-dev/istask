<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\NoteItem;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteItemController extends Controller
{
    public function store(Request $request, Note $note)
    {
        Gate::forUser(Workspace::user())->authorize('update', $note);
        $data = $request->validate(['text' => 'required|string|max:1000']);
        $max = $note->items()->max('position');
        $note->items()->create([
            'text'     => $data['text'],
            'position' => $max === null ? 0 : (int) $max + 1,
        ]);

        return response()->json(['note' => $note->fresh()->toApiArray()]);
    }

    public function update(Request $request, Note $note, NoteItem $item)
    {
        Gate::forUser(Workspace::user())->authorize('update', $note);
        abort_unless($item->note_id === $note->id, 404);
        $data = $request->validate([
            'text' => 'sometimes|string|max:1000',
            'done' => 'sometimes|boolean',
        ]);
        $item->fill($data)->save();

        return response()->json(['note' => $note->fresh()->toApiArray()]);
    }

    public function destroy(Note $note, NoteItem $item)
    {
        Gate::forUser(Workspace::user())->authorize('update', $note);
        abort_unless($item->note_id === $note->id, 404);
        $item->delete();

        return response()->json(['note' => $note->fresh()->toApiArray()]);
    }

    /** Reordena os itens conforme a lista de ids recebida (ignora ids de outras notas). */
    public function reorder(Request $request, Note $note)
    {
        Gate::forUser(Workspace::user())->authorize('update', $note);
        $data = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer',
        ]);
        foreach (array_values($data['ids']) as $position => $id) {
            $note->items()->where('id', $id)->update(['position' => $position]);
        }

        return response()->json(['note' => $note->fresh()->toApiArray()]);
    }
}
