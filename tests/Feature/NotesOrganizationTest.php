<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cobre a Fase 1 do módulo de Notas (Organização): cores/padrão, fixar,
 * arquivar, etiquetas, lixeira com expurgo, autorização (NotePolicy) e a
 * persistência do modo de visualização (grid/lista) nas preferências.
 */
class NotesOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private function note(User $user, array $attrs = []): Note
    {
        return Note::create(array_merge(['user_id' => $user->id, 'body' => 'corpo'], $attrs));
    }

    // ---------- Cores e padrão ----------

    public function test_create_note_accepts_expanded_palette_and_pattern(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $res = $this->postJson('/api/notes', ['title' => 'Nota', 'body' => 'x', 'color' => 'teal', 'pattern' => 'dots']);
        $res->assertCreated();
        $this->assertSame('teal', $res->json('note.color'));
        $this->assertSame('dots', $res->json('note.pattern'));
        $this->assertFalse($res->json('note.pinned'));
        $this->assertNull($res->json('note.archivedAt'));
        $this->assertSame([], $res->json('note.labels'));
    }

    public function test_store_and_update_reject_invalid_color_and_pattern(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson('/api/notes', ['body' => 'x', 'color' => 'rainbow'])->assertStatus(422);
        $this->postJson('/api/notes', ['body' => 'x', 'pattern' => 'zigzag'])->assertStatus(422);

        $note = $this->note($user, ['color' => 'mint']);
        $this->patchJson("/api/notes/{$note->id}", ['color' => 'not-a-color'])->assertStatus(422);
    }

    public function test_pattern_can_be_cleared_with_null(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['pattern' => 'grid']);

        $res = $this->patchJson("/api/notes/{$note->id}", ['pattern' => null]);
        $res->assertOk();
        $this->assertNull($res->json('note.pattern'));
        $this->assertNull($note->fresh()->pattern);
    }

    // ---------- Fixar ----------

    public function test_pin_toggles_on_and_off(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user);

        $this->assertFalse((bool) $note->pinned);
        $this->assertTrue($this->postJson("/api/notes/{$note->id}/pin")->json('note.pinned'));
        $this->assertFalse($this->postJson("/api/notes/{$note->id}/pin")->json('note.pinned'));
    }

    // ---------- Arquivar ----------

    public function test_archive_sets_timestamp_clears_pin_then_unarchives(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['pinned' => true]);

        $res = $this->postJson("/api/notes/{$note->id}/archive");
        $res->assertOk();
        $this->assertNotNull($res->json('note.archivedAt'));
        $this->assertFalse($res->json('note.pinned')); // arquivar desfixa

        $res = $this->postJson("/api/notes/{$note->id}/archive");
        $res->assertOk();
        $this->assertNull($res->json('note.archivedAt'));
    }

    // ---------- Etiquetas ----------

    public function test_label_crud_and_per_user_uniqueness(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $res = $this->postJson('/api/labels', ['name' => 'Trabalho']);
        $res->assertCreated();
        $labelId = (int) $res->json('label.id');
        $this->assertCount(1, $res->json('labels'));

        // duplicada para o mesmo usuário -> 422
        $this->postJson('/api/labels', ['name' => 'Trabalho'])->assertStatus(422);

        // outro usuário pode ter o mesmo nome
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->postJson('/api/labels', ['name' => 'Trabalho'])->assertCreated();

        // renomear (de volta como dono)
        $this->actingAs($user);
        $res = $this->patchJson("/api/labels/{$labelId}", ['name' => 'Pessoal']);
        $res->assertOk();
        $this->assertSame('Pessoal', $res->json('labels.0.name'));

        // excluir
        $this->deleteJson("/api/labels/{$labelId}")->assertOk();
        $this->assertDatabaseMissing('labels', ['id' => $labelId]);
    }

    public function test_sync_labels_attaches_and_detaches(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user);
        $a = $user->labels()->create(['name' => 'A']);
        $b = $user->labels()->create(['name' => 'B']);

        $res = $this->postJson("/api/notes/{$note->id}/labels", ['label_ids' => [$a->id, $b->id]]);
        $res->assertOk();
        $this->assertCount(2, $res->json('note.labels'));

        $res = $this->postJson("/api/notes/{$note->id}/labels", ['label_ids' => [$a->id]]);
        $res->assertOk();
        $this->assertCount(1, $res->json('note.labels'));
        $this->assertSame('A', $res->json('note.labels.0.name'));

        // limpar tudo
        $res = $this->postJson("/api/notes/{$note->id}/labels", ['label_ids' => []]);
        $this->assertCount(0, $res->json('note.labels'));
    }

    public function test_sync_labels_ignores_labels_owned_by_others(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $other->labels()->create(['name' => 'Alheia']);

        $this->actingAs($user);
        $note = $this->note($user);

        $res = $this->postJson("/api/notes/{$note->id}/labels", ['label_ids' => [$foreign->id]]);
        $res->assertOk();
        $this->assertCount(0, $res->json('note.labels'));
        $this->assertDatabaseMissing('label_note', ['note_id' => $note->id, 'label_id' => $foreign->id]);
    }

    public function test_deleting_label_detaches_it_from_notes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user);
        $label = $user->labels()->create(['name' => 'Some']);
        $note->labels()->attach($label->id);

        $this->deleteJson("/api/labels/{$label->id}")->assertOk();
        $this->assertDatabaseMissing('label_note', ['label_id' => $label->id]);
        $this->assertSame([], $note->fresh()->labels->all());
    }

    // ---------- Lixeira ----------

    public function test_trash_lists_soft_deleted_then_restore(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['title' => 'Pra lixeira']);

        $this->deleteJson("/api/notes/{$note->id}")->assertOk();
        $this->assertSoftDeleted('notes', ['id' => $note->id]);

        $res = $this->getJson('/api/notes/trash');
        $res->assertOk();
        $this->assertCount(1, $res->json('notes'));
        $this->assertNotNull($res->json('notes.0.deletedAt'));

        $res = $this->postJson("/api/notes/{$note->id}/restore");
        $res->assertOk();
        $this->assertDatabaseHas('notes', ['id' => $note->id, 'deleted_at' => null]);
        $this->assertCount(0, $this->getJson('/api/notes/trash')->json('notes'));
    }

    public function test_force_delete_removes_permanently_and_detaches_labels(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user);
        $label = $user->labels()->create(['name' => 'L']);
        $note->labels()->attach($label->id);
        $this->deleteJson("/api/notes/{$note->id}");

        $res = $this->deleteJson("/api/notes/{$note->id}/force");
        $res->assertOk();
        $this->assertTrue($res->json('deleted'));
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
        $this->assertDatabaseMissing('label_note', ['note_id' => $note->id]); // hook forceDeleting
        $this->assertDatabaseHas('labels', ['id' => $label->id]); // a etiqueta em si permanece
    }

    public function test_bootstrap_purges_trash_older_than_seven_days_keeps_recent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $old = $this->note($user, ['title' => 'Velha']);
        $recent = $this->note($user, ['title' => 'Recente']);
        $old->delete();
        $recent->delete();
        DB::table('notes')->where('id', $old->id)->update(['deleted_at' => now()->subDays(8)]);

        $this->getJson('/api/bootstrap')->assertOk();

        $this->assertDatabaseMissing('notes', ['id' => $old->id]); // expurgada
        $this->assertSoftDeleted('notes', ['id' => $recent->id]);   // ainda dentro do prazo
    }

    public function test_bootstrap_includes_labels_and_archived_but_not_trashed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->labels()->create(['name' => 'Z']);
        $active = $this->note($user, ['title' => 'Ativa']);
        $archived = $this->note($user, ['title' => 'Arquivada', 'archived_at' => now()]);
        $trashed = $this->note($user, ['title' => 'Lixo']);
        $trashed->delete();

        $res = $this->getJson('/api/bootstrap');
        $res->assertOk();
        $ids = collect($res->json('notes'))->pluck('id')->all();
        $this->assertContains((string) $active->id, $ids);
        $this->assertContains((string) $archived->id, $ids); // front separa Ativas/Arquivo
        $this->assertNotContains((string) $trashed->id, $ids);
        $this->assertCount(1, $res->json('labels'));
        $this->assertSame('Z', $res->json('labels.0.name'));
    }

    // ---------- Autorização (NotePolicy) ----------

    public function test_non_owner_is_forbidden_across_note_endpoints(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $note = $this->note($owner);
        $label = $owner->labels()->create(['name' => 'Owner']);

        $this->actingAs($intruder);
        $this->getJson("/api/notes/{$note->id}")->assertForbidden();
        $this->patchJson("/api/notes/{$note->id}", ['title' => 'hack'])->assertForbidden();
        $this->deleteJson("/api/notes/{$note->id}")->assertForbidden();
        $this->postJson("/api/notes/{$note->id}/pin")->assertForbidden();
        $this->postJson("/api/notes/{$note->id}/archive")->assertForbidden();
        $this->postJson("/api/notes/{$note->id}/labels", ['label_ids' => []])->assertForbidden();

        // etiquetas de outro usuário -> 404 (abort_unless no LabelController)
        $this->patchJson("/api/labels/{$label->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/labels/{$label->id}")->assertNotFound();
    }

    public function test_non_owner_cannot_restore_or_force_delete(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $note = $this->note($owner);
        $note->delete();

        $this->actingAs($intruder);
        $this->postJson("/api/notes/{$note->id}/restore")->assertForbidden();
        $this->deleteJson("/api/notes/{$note->id}/force")->assertForbidden();
        $this->assertSoftDeleted('notes', ['id' => $note->id]); // permanece intacta na lixeira do dono
    }

    // ---------- Preferências (modo de visualização) ----------

    public function test_notes_view_mode_preference_persists(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $res = $this->putJson('/api/preferences', ['notesViewMode' => 'list']);
        $res->assertOk();
        $this->assertSame('list', $res->json('prefs.notesViewMode'));
        $this->assertSame('list', $user->fresh()->prefs()['notesViewMode']);

        $this->putJson('/api/preferences', ['notesViewMode' => 'invalid'])->assertStatus(422);
    }

    // ---------- Shell (Blade compila e carrega os módulos novos) ----------

    public function test_app_shell_renders_and_wires_notes_modules(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $res = $this->get('/');
        $res->assertOk();
        $res->assertSee('app/notes-modals.js');
        $res->assertSee('app/notes-views.js');
        $res->assertSee('Material+Symbols+Outlined', false);
    }
}
