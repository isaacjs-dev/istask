<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 2 do módulo de Notas (Tipos de conteúdo): checklist (itens + reordenar),
 * conversão texto↔checklist e anexos de nota (imagem/desenho/áudio) reaproveitando
 * o AttachmentController polimórfico, com a limpeza no forceDelete.
 */
class NotesContentTest extends TestCase
{
    use RefreshDatabase;

    private function note(User $user, array $attrs = []): Note
    {
        return Note::create(array_merge(['user_id' => $user->id, 'body' => 'corpo'], $attrs));
    }

    // ---------- Itens de checklist ----------

    public function test_item_crud_and_reorder(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['type' => 'checklist', 'body' => '']);

        $res = $this->postJson("/api/notes/{$note->id}/items", ['text' => 'Primeiro']);
        $res->assertOk();
        $this->assertCount(1, $res->json('note.items'));
        $this->assertSame('Primeiro', $res->json('note.items.0.text'));
        $this->assertFalse($res->json('note.items.0.done'));

        $this->postJson("/api/notes/{$note->id}/items", ['text' => 'Segundo'])->assertOk();
        $note->refresh();
        [$first, $second] = $note->items->all();
        $this->assertSame(0, $first->position);
        $this->assertSame(1, $second->position);

        // marcar concluído
        $res = $this->patchJson("/api/notes/{$note->id}/items/{$first->id}", ['done' => true]);
        $res->assertOk();
        $this->assertTrue(collect($res->json('note.items'))->firstWhere('id', (string) $first->id)['done']);

        // editar texto
        $this->patchJson("/api/notes/{$note->id}/items/{$first->id}", ['text' => 'Primeiro (editado)'])->assertOk();
        $this->assertSame('Primeiro (editado)', $first->fresh()->text);

        // reordenar (inverte)
        $res = $this->postJson("/api/notes/{$note->id}/items/reorder", ['ids' => [$second->id, $first->id]]);
        $res->assertOk();
        $this->assertSame(0, $second->fresh()->position);
        $this->assertSame(1, $first->fresh()->position);

        // remover
        $res = $this->deleteJson("/api/notes/{$note->id}/items/{$first->id}");
        $res->assertOk();
        $this->assertDatabaseMissing('note_items', ['id' => $first->id]);
        $this->assertCount(1, $res->json('note.items'));
    }

    public function test_item_must_belong_to_note(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $noteA = $this->note($user);
        $noteB = $this->note($user);
        $item = $noteA->items()->create(['text' => 'x', 'position' => 0]);

        $this->patchJson("/api/notes/{$noteB->id}/items/{$item->id}", ['done' => true])->assertNotFound();
        $this->deleteJson("/api/notes/{$noteB->id}/items/{$item->id}")->assertNotFound();
    }

    public function test_non_owner_cannot_touch_items(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $note = $this->note($owner);
        $item = $note->items()->create(['text' => 'x', 'position' => 0]);

        $this->actingAs($intruder);
        $this->postJson("/api/notes/{$note->id}/items", ['text' => 'y'])->assertForbidden();
        $this->patchJson("/api/notes/{$note->id}/items/{$item->id}", ['done' => true])->assertForbidden();
        $this->deleteJson("/api/notes/{$note->id}/items/{$item->id}")->assertForbidden();
        $this->postJson("/api/notes/{$note->id}/items/reorder", ['ids' => [$item->id]])->assertForbidden();
    }

    // ---------- Conversão texto <-> checklist ----------

    public function test_convert_text_to_checklist_splits_lines(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['type' => 'text', 'body' => "Comprar pão\nLeite\n\nOvos"]);

        $res = $this->postJson("/api/notes/{$note->id}/convert", ['type' => 'checklist']);
        $res->assertOk();
        $this->assertSame('checklist', $res->json('note.type'));
        $this->assertSame('', $res->json('note.body'));
        $items = $res->json('note.items');
        $this->assertSame(['Comprar pão', 'Leite', 'Ovos'], array_column($items, 'text'));
        $this->assertSame([0, 1, 2], array_column($items, 'position'));
    }

    public function test_convert_checklist_to_text_joins_with_checkboxes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['type' => 'checklist', 'body' => '']);
        $note->items()->create(['text' => 'Feito', 'done' => true, 'position' => 0]);
        $note->items()->create(['text' => 'A fazer', 'done' => false, 'position' => 1]);

        $res = $this->postJson("/api/notes/{$note->id}/convert", ['type' => 'text']);
        $res->assertOk();
        $this->assertSame('text', $res->json('note.type'));
        $this->assertSame("[x] Feito\n[ ] A fazer", $res->json('note.body'));
        $this->assertCount(0, $res->json('note.items'));
        $this->assertDatabaseMissing('note_items', ['note_id' => $note->id]);
    }

    public function test_convert_rejects_invalid_type_and_is_noop_when_same(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['type' => 'text', 'body' => 'oi']);

        $this->postJson("/api/notes/{$note->id}/convert", ['type' => 'banana'])->assertStatus(422);

        // já é texto -> não cria itens nem altera o corpo
        $res = $this->postJson("/api/notes/{$note->id}/convert", ['type' => 'text']);
        $res->assertOk();
        $this->assertSame('oi', $res->json('note.body'));
        $this->assertCount(0, $res->json('note.items'));
    }

    // ---------- Anexos de nota ----------

    public function test_upload_image_and_drawing_and_delete_for_note(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user);

        $res = $this->postJson('/api/attachments', [
            'attachable_type' => 'note',
            'attachable_id'   => $note->id,
            'file'            => UploadedFile::fake()->image('foto.png'),
        ]);
        $res->assertCreated();
        $this->assertSame('own', $res->json('attachment.origin'));
        $attId = $res->json('attachment.id');

        $res = $this->postJson('/api/attachments', [
            'attachable_type' => 'note',
            'attachable_id'   => $note->id,
            'origin'          => 'drawing',
            'file'            => UploadedFile::fake()->image('desenho.png'),
        ]);
        $res->assertCreated();
        $this->assertSame('drawing', $res->json('attachment.origin'));

        $this->assertCount(2, $note->fresh()->attachments);

        $this->deleteJson("/api/attachments/{$attId}")->assertOk();
        $this->assertCount(1, $note->fresh()->attachments);
    }

    public function test_audio_mime_allowed_for_note_but_not_for_task(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'T']);
        $note = $this->note($user);
        $this->actingAs($user);

        $audio = fn () => UploadedFile::fake()->create('voz.webm', 80, 'audio/webm');

        $this->postJson('/api/attachments', [
            'attachable_type' => 'note', 'attachable_id' => $note->id, 'file' => $audio(),
        ])->assertCreated();

        // o mesmo áudio não é permitido em tarefa (allowlist de áudio é exclusiva de notas)
        $this->postJson('/api/attachments', [
            'attachable_type' => 'task', 'attachable_id' => $task->id, 'file' => $audio(),
        ])->assertStatus(422);
    }

    public function test_non_owner_cannot_upload_to_note(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $note = $this->note($owner);

        $this->actingAs($intruder);
        $this->postJson('/api/attachments', [
            'attachable_type' => 'note',
            'attachable_id'   => $note->id,
            'file'            => UploadedFile::fake()->image('x.png'),
        ])->assertForbidden();
    }

    public function test_force_delete_note_removes_items_and_attachment_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user);
        $note->items()->create(['text' => 'item', 'position' => 0]);
        $res = $this->postJson('/api/attachments', [
            'attachable_type' => 'note',
            'attachable_id'   => $note->id,
            'file'            => UploadedFile::fake()->image('foto.png'),
        ]);
        $path = $note->fresh()->attachments->first()->path;
        Storage::disk('public')->assertExists($path);

        $this->deleteJson("/api/notes/{$note->id}"); // soft delete
        $this->deleteJson("/api/notes/{$note->id}/force")->assertOk();

        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
        $this->assertDatabaseMissing('note_items', ['note_id' => $note->id]);
        $this->assertDatabaseMissing('attachments', ['attachable_id' => $note->id, 'attachable_type' => Note::class]);
        Storage::disk('public')->assertMissing($path);
    }

    // ---------- Serialização ----------

    public function test_api_array_exposes_items_and_attachments(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['type' => 'checklist', 'body' => '']);
        $note->items()->create(['text' => 'A', 'position' => 0]);

        $res = $this->getJson("/api/notes/{$note->id}");
        $res->assertOk();
        $this->assertSame('checklist', $res->json('note.type'));
        $this->assertIsArray($res->json('note.items'));
        $this->assertIsArray($res->json('note.attachments'));
        $this->assertSame('A', $res->json('note.items.0.text'));
    }

    public function test_shell_wires_phase2_modules(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $res = $this->get('/');
        $res->assertOk();
        $res->assertSee('app/notes-canvas.js');
        $res->assertSee('app/notes-audio.js');
        $res->assertSee('app/notes-ocr.js');
        $res->assertSee('tesseract.js@5', false); // OCR via CDN (sem build)
    }
}
