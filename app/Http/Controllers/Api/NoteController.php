<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Models\Note;
use App\Models\Notebook;
use App\Notifications\NoteReminderDue;
use App\Services\Commands\ActionRecorder;
use App\Support\Access;
use App\Support\HtmlSanitizer;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    public function __construct(private ActionRecorder $recorder)
    {
    }

    private function colorRule(): string
    {
        return 'nullable|string|in:' . implode(',', Note::COLORS);
    }

    private function patternRule(): string
    {
        return 'nullable|string|in:' . implode(',', Note::PATTERNS);
    }

    public function store(Request $request)
    {
        $user = Workspace::user();
        $data = $request->validate([
            'title'       => 'nullable|string|max:255',
            'body'        => 'nullable|string',
            'tags'        => 'nullable|string|max:255',
            'color'       => $this->colorRule(),
            'pattern'     => $this->patternRule(),
            'notebook_id' => 'sometimes|integer',
        ]);
        $data['body'] = HtmlSanitizer::clean($data['body'] ?? '');

        $notebookId = $data['notebook_id'] ?? null;
        if ($notebookId) {
            // permite caderno próprio ou compartilhado com permissão de edição (cascata)
            $notebook = Notebook::find($notebookId);
            abort_unless($notebook && Access::can(Access::notebookPermission($user, $notebook), 'edit'), 404);
        } else {
            // padrão: primeiro caderno da primeira área do usuário
            $notebookId = Notebook::whereIn('workspace_id', $user->ownedWorkspaces()->select('id'))
                ->orderBy('workspace_id')->orderBy('position')->value('id');
        }
        $data['notebook_id'] = $notebookId;

        $note = $user->notes()->create($data);
        $this->recorder->record(
            $user, 'create', 'note', $note->id, null,
            $note->only(['title', 'body', 'tags', 'color']),
            'Nota criada' . ($note->title ? ": \"{$note->title}\"" : ''),
            ['kind' => 'note', 'canUndo' => true]
        );

        return response()->json([
            'note'  => $note->toApiArray(),
            'notes' => $user->notes()->with(['labels', 'items', 'attachments', 'collaborators', 'user'])->latest('updated_at')->get()->map->toApiArray()->all(),
        ], 201);
    }

    /**
     * Importa notas em lote (assistente de Importação nas Configurações).
     * Cada item é criado isoladamente — uma falha não derruba as demais. O front já
     * resolveu caderno (id) e etiquetas (ids).
     */
    public function import(Request $request)
    {
        $user = Workspace::user();
        $data = $request->validate([
            'notes'                => 'required|array|min:1|max:500',
            'notes.*.title'        => 'required|string|max:255',
            'notes.*.body'         => 'nullable|string',
            'notes.*.tags'         => 'nullable|string|max:255',
            'notes.*.type'         => 'nullable|in:text,checklist',
            'notes.*.color'        => $this->colorRule(),
            'notes.*.pattern'      => $this->patternRule(),
            'notes.*.notebook_id'  => 'nullable|integer',
            'notes.*.labelIds'     => 'nullable|array',
            'notes.*.labelIds.*'   => 'integer',
            'notes.*.items'        => 'nullable|array',
            'notes.*.items.*.text' => 'required|string',
            'notes.*.items.*.done' => 'boolean',
        ]);

        $defaultNotebookId = Notebook::whereIn('workspace_id', $user->ownedWorkspaces()->select('id'))
            ->orderBy('workspace_id')->orderBy('position')->value('id');

        $results = [];
        foreach ($data['notes'] as $i => $n) {
            try {
                $id = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $n, $defaultNotebookId) {
                    $nbId = $n['notebook_id'] ?? null;
                    if ($nbId) {
                        $nb = Notebook::find($nbId);
                        if (! $nb || ! Access::can(Access::notebookPermission($user, $nb), 'edit')) {
                            $nbId = null;
                        }
                    }
                    $nbId = $nbId ?: $defaultNotebookId;

                    $type = $n['type'] ?? 'text';
                    $note = $user->notes()->create([
                        'notebook_id' => $nbId,
                        'title'       => $n['title'],
                        'body'        => $type === 'checklist' ? '' : HtmlSanitizer::clean($n['body'] ?? ''),
                        'tags'        => $n['tags'] ?? null,
                        'color'       => $n['color'] ?? null,
                        'pattern'     => $n['pattern'] ?? null,
                        'type'        => $type,
                    ]);
                    if ($type === 'checklist' && ! empty($n['items'])) {
                        $pos = 0;
                        foreach ($n['items'] as $it) {
                            $note->items()->create(['text' => $it['text'], 'done' => ! empty($it['done']), 'position' => $pos++]);
                        }
                    }
                    if (! empty($n['labelIds'])) {
                        $valid = Label::where('user_id', $user->id)->whereIn('id', $n['labelIds'])->pluck('id')->all();
                        $note->labels()->sync($valid);
                    }

                    return (string) $note->id;
                });
                $results[$i] = ['index' => $i, 'ok' => true, 'id' => $id];
            } catch (\Throwable $e) {
                $results[$i] = ['index' => $i, 'ok' => false, 'error' => 'Não foi possível importar esta nota.'];
            }
        }
        ksort($results);

        return response()->json([
            'results' => array_values($results),
            'notes'   => $user->notes()->with(['labels', 'items', 'attachments', 'collaborators', 'user'])->latest('updated_at')->get()->map->toApiArray()->all(),
        ]);
    }

    public function show(Note $note)
    {
        Gate::forUser(Workspace::user())->authorize('view', $note);

        return response()->json(['note' => $note->toApiArray()]);
    }

    public function update(Request $request, Note $note)
    {
        $user = Workspace::user();
        Gate::forUser($user)->authorize('update', $note);
        $data = $request->validate([
            'title'   => 'nullable|string|max:255',
            'body'    => 'nullable|string',
            'tags'    => 'nullable|string|max:255',
            'color'   => $this->colorRule(),
            'pattern' => $this->patternRule(),
        ]);
        if (array_key_exists('body', $data)) {
            $data['body'] = HtmlSanitizer::clean($data['body']);
        }
        $before = $note->only(['title', 'body', 'tags', 'color']);
        $note->fill($data)->save();
        $this->recorder->record(
            $user, 'update', 'note', $note->id, $before,
            $note->only(['title', 'body', 'tags', 'color']),
            'Nota atualizada', ['kind' => 'note', 'canUndo' => true]
        );

        return response()->json(['note' => $note->toApiArray()]);
    }

    public function destroy(Note $note)
    {
        $user = Workspace::user();
        Gate::forUser($user)->authorize('delete', $note);
        $before = $note->only(['title', 'body', 'tags', 'color']);
        $note->delete();
        $this->recorder->record(
            $user, 'delete', 'note', $note->id, $before, null,
            'Nota excluída', ['kind' => 'note', 'canUndo' => true]
        );

        return response()->json(['deleted' => true]);
    }

    /** Fixar/desfixar (seção "Fixadas"). */
    public function pin(Note $note)
    {
        Gate::forUser(Workspace::user())->authorize('update', $note);
        $note->pinned = ! $note->pinned;
        $note->save();

        return response()->json(['note' => $note->toApiArray()]);
    }

    /** Arquivar/desarquivar. Arquivar remove a nota da seção "Fixadas". */
    public function archive(Note $note)
    {
        Gate::forUser(Workspace::user())->authorize('update', $note);
        if ($note->archived_at) {
            $note->archived_at = null;
        } else {
            $note->archived_at = now();
            $note->pinned = false;
        }
        $note->save();

        return response()->json(['note' => $note->toApiArray()]);
    }

    /** Lista as notas na lixeira (exclusão soft, dentro do prazo de retenção). */
    public function trash()
    {
        $user = Workspace::user();
        $notes = Note::onlyTrashed()
            ->where('user_id', $user->id)
            ->with(['labels', 'items', 'attachments', 'collaborators', 'user'])
            ->orderByDesc('deleted_at')
            ->get()
            ->map(function (Note $note) {
                $data = $note->toApiArray();
                $data['deletedAt'] = optional($note->deleted_at)->toIso8601String();

                return $data;
            });

        return response()->json(['notes' => $notes->values()]);
    }

    /** Restaura uma nota da lixeira. */
    public function restore(Note $note)
    {
        Gate::forUser(Workspace::user())->authorize('delete', $note);
        $note->restore();

        return response()->json(['note' => $note->toApiArray()]);
    }

    /** Exclusão definitiva (a partir da lixeira). */
    public function forceDestroy(Note $note)
    {
        Gate::forUser(Workspace::user())->authorize('delete', $note);
        $note->forceDelete();

        return response()->json(['deleted' => true]);
    }

    /** Converte a nota entre texto livre e checklist (e vice-versa). */
    public function convert(Request $request, Note $note)
    {
        Gate::forUser(Workspace::user())->authorize('update', $note);
        $data = $request->validate(['type' => 'required|in:text,checklist']);

        if ($data['type'] === 'checklist' && $note->type !== 'checklist') {
            // Converte o corpo HTML em linhas de texto limpas (sem tags) p/ os itens.
            $text = preg_replace('#</(p|div|li|h[1-6]|blockquote|tr)\s*>#i', "\n", (string) $note->body);
            $text = preg_replace('#<br\s*/?>#i', "\n", (string) $text);
            $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $lines = preg_split('/\r\n|\r|\n/', $text);
            $position = 0;
            foreach ($lines as $line) {
                $itemText = trim($line);
                if ($itemText === '') {
                    continue;
                }
                $note->items()->create(['text' => $itemText, 'position' => $position++]);
            }
            $note->type = 'checklist';
            $note->body = '';
            $note->save();
        } elseif ($data['type'] === 'text' && $note->type === 'checklist') {
            // Cada item vira um parágrafo HTML (texto escapado); item concluído fica tachado.
            $body = $note->items->map(function ($i) {
                $text = e($i->text);

                return '<p>' . ($i->done ? "<s>{$text}</s>" : $text) . '</p>';
            })->implode('');
            $note->items()->delete();
            $note->type = 'text';
            $note->body = $body;
            $note->save();
        }

        return response()->json(['note' => $note->fresh()->toApiArray()]);
    }

    /** Define/edita/remove o lembrete da nota (data/hora + recorrência opcional). */
    public function setReminder(Request $request, Note $note)
    {
        Gate::forUser(Workspace::user())->authorize('update', $note);
        $data = $request->validate([
            'remind_at'         => 'nullable|date',
            'remind_recurrence' => 'nullable|in:daily,weekly,monthly,yearly',
        ]);
        $note->remind_at = $data['remind_at'] ?? null;
        $note->remind_recurrence = ($note->remind_at && ! empty($data['remind_recurrence'])) ? $data['remind_recurrence'] : null;
        $note->remind_last_fired_at = null; // permite o disparo do novo horário
        $note->save();

        return response()->json(['note' => $note->fresh()->toApiArray()]);
    }

    /** Notas do usuário com lembrete definido (não arquivadas), ordenadas por horário. */
    public function reminders()
    {
        $user = Workspace::user();
        $notes = Note::where('user_id', $user->id)
            ->active()
            ->whereNotNull('remind_at')
            ->with(['labels', 'items', 'attachments', 'collaborators', 'user'])
            ->orderBy('remind_at')
            ->get()
            ->map->toApiArray();

        return response()->json(['notes' => $notes->values()]);
    }

    /** Dispara (on-demand, via polling) os lembretes vencidos ainda não notificados. */
    public function remindersDue()
    {
        $user = Workspace::user();
        $now = now();
        $due = Note::where('user_id', $user->id)
            ->active()
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', $now)
            ->where(function ($q) {
                $q->whereNull('remind_last_fired_at')->orWhereColumn('remind_last_fired_at', '<', 'remind_at');
            })
            ->get();

        $fired = [];
        foreach ($due as $note) {
            $user->notify(new NoteReminderDue($note));
            $note->remind_last_fired_at = $now;
            if ($note->remind_recurrence) {
                $next = $note->remind_at->copy();
                do {
                    $next = $this->advance($next, $note->remind_recurrence);
                } while ($next->lte($now));
                $note->remind_at = $next;
            }
            $note->save();
            $fired[] = $note->fresh()->toApiArray();
        }

        return response()->json(['fired' => $fired]);
    }

    /** Avança um instante conforme a recorrência. */
    private function advance(\Illuminate\Support\Carbon $date, string $recurrence): \Illuminate\Support\Carbon
    {
        return match ($recurrence) {
            'daily'   => $date->copy()->addDay(),
            'weekly'  => $date->copy()->addWeek(),
            'monthly' => $date->copy()->addMonthNoOverflow(),
            'yearly'  => $date->copy()->addYear(),
            default   => $date->copy()->addDay(),
        };
    }

    /** Move a nota para outro caderno (de uma área do usuário). */
    public function move(Request $request, Note $note)
    {
        $user = Workspace::user();
        Gate::forUser($user)->authorize('update', $note);
        $data = $request->validate(['notebook_id' => 'required|integer']);
        $notebook = Notebook::find($data['notebook_id']);
        abort_unless($notebook && Access::can(Access::notebookPermission($user, $notebook), 'edit'), 404);

        $note->notebook_id = $notebook->id;
        $note->save();

        return response()->json(['note' => $note->fresh()->toApiArray()]);
    }

    /** Associa/desassocia etiquetas da nota (sincroniza a lista completa). */
    public function syncLabels(Request $request, Note $note)
    {
        $user = Workspace::user();
        Gate::forUser($user)->authorize('update', $note);
        $data = $request->validate([
            'label_ids'   => 'array',
            'label_ids.*' => 'integer',
        ]);
        $ids = Label::where('user_id', $user->id)
            ->whereIn('id', $data['label_ids'] ?? [])
            ->pluck('id')
            ->all();
        $note->labels()->sync($ids);

        return response()->json(['note' => $note->fresh('labels')->toApiArray()]);
    }
}
