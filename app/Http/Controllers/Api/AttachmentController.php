<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\DiaryEntry;
use App\Models\Note;
use App\Models\Task;
use App\Support\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentController extends Controller
{
    private const MIMES = 'jpg,jpeg,png,webp,gif,pdf,xlsx,xls,docx,doc,txt,csv,zip,pptx,ppt';

    /**
     * Extensões de áudio extras, permitidas apenas em anexos de Notas (gravação de voz).
     * Inclui as variantes que o detector de MIME do Laravel infere do conteúdo
     * (audio/webm→weba, audio/ogg→oga, audio/mpeg→mpga) — a regra `mimes` compara
     * a extensão deduzida do conteúdo, não a do nome do arquivo.
     */
    private const NOTE_AUDIO_MIMES = 'webm,weba,ogg,oga,mp3,mpga,wav,m4a,mp4,aac';

    /** Upload de um anexo próprio para uma tarefa, entrada do diário ou nota. */
    public function store(Request $request)
    {
        $isNote = $request->input('attachable_type') === 'note';
        $mimes = self::MIMES . ($isNote ? ',' . self::NOTE_AUDIO_MIMES : '');

        $data = $request->validate([
            'attachable_type' => 'required|in:task,diary,note',
            'attachable_id'   => 'required|integer',
            'origin'          => 'nullable|in:own,drawing',
            'file'            => 'required|file|mimes:' . $mimes . '|max:10240',
        ]);

        $user = Workspace::user();
        $owner = $this->resolveOwner($data['attachable_type'], (int) $data['attachable_id'], $user);

        $file = $request->file('file');
        $dir = 'attachments/' . $data['attachable_type'] . '/' . $owner->id;
        $path = $file->store($dir, 'public');

        $attachment = $owner->attachments()->create([
            'user_id'       => $user->id,
            'disk'          => 'public',
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'origin'        => $data['origin'] ?? 'own',
        ]);

        if ($owner instanceof DiaryEntry) {
            $owner->logHistory('attachment_added', 'Anexo adicionado: ' . $attachment->original_name, null, $user->name, $user->id);
        }

        return response()->json(['attachment' => $attachment->toApiArray()], 201);
    }

    /** Importa anexos da tarefa vinculada para a entrada do diário (copia o arquivo, origem rastreável). */
    public function importFromTask(Request $request, DiaryEntry $entry)
    {
        $user = Workspace::user();
        abort_unless($entry->user_id === $user->id, 404);
        abort_unless($entry->task_id, 422, 'A entrada não está vinculada a uma tarefa.');

        $data = $request->validate([
            'attachment_ids'   => 'required|array|min:1',
            'attachment_ids.*' => 'integer',
        ]);

        $sources = Attachment::query()
            ->where('attachable_type', Task::class)
            ->where('attachable_id', $entry->task_id)
            ->whereIn('id', $data['attachment_ids'])
            ->get();

        $created = [];
        foreach ($sources as $src) {
            $ext = pathinfo($src->path, PATHINFO_EXTENSION);
            $newPath = "attachments/diary/{$entry->id}/" . Str::uuid() . ($ext ? ".{$ext}" : '');
            $disk = Storage::disk($src->disk ?: 'public');
            if (! $disk->exists($src->path) || ! $disk->copy($src->path, $newPath)) {
                continue;
            }
            $copy = $entry->attachments()->create([
                'user_id'              => $user->id,
                'disk'                 => $src->disk ?: 'public',
                'path'                 => $newPath,
                'original_name'        => $src->original_name,
                'mime'                 => $src->mime,
                'size'                 => $src->size,
                'origin'               => 'task',
                'source_attachment_id' => $src->id,
            ]);
            $created[] = $copy->toApiArray();
        }

        if ($created) {
            $entry->logHistory('attachment_added', count($created) . ' anexo(s) importado(s) da tarefa', null, $user->name, $user->id);
        }

        return response()->json(['attachments' => $created], 201);
    }

    public function destroy(Attachment $attachment)
    {
        $user = Workspace::user();
        $this->guardAttachment($attachment, $user);

        Storage::disk($attachment->disk ?: 'public')->delete($attachment->path);
        $owner = $attachment->attachable;
        $name = $attachment->original_name;
        $attachment->delete();

        if ($owner instanceof DiaryEntry) {
            $owner->logHistory('attachment_removed', 'Anexo removido: ' . $name, null, $user->name, $user->id);
        }

        return response()->json(['deleted' => true]);
    }

    private function resolveOwner(string $type, int $id, $user): Model
    {
        if ($type === 'task') {
            $task = Task::find($id);
            abort_unless($task && $task->project && $task->project->user_id === $user->id, 404);

            return $task;
        }
        if ($type === 'note') {
            $note = Note::find($id);
            abort_unless($note !== null, 404);
            Gate::forUser($user)->authorize('update', $note);

            return $note;
        }
        $entry = DiaryEntry::find($id);
        abort_unless($entry && $entry->user_id === $user->id, 404);

        return $entry;
    }

    private function guardAttachment(Attachment $attachment, $user): void
    {
        $owner = $attachment->attachable;
        if ($owner instanceof Task) {
            abort_unless($owner->project && $owner->project->user_id === $user->id, 404);
        } elseif ($owner instanceof DiaryEntry) {
            abort_unless($owner->user_id === $user->id, 404);
        } elseif ($owner instanceof Note) {
            Gate::forUser($user)->authorize('update', $owner);
        } else {
            abort(404);
        }
    }
}
