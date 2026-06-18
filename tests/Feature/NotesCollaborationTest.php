<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 5 do módulo de Notas (Colaboração): adicionar/remover colaborador por
 * e-mail, autorização (NotePolicy ampliada) e notas compartilhadas no bootstrap.
 */
class NotesCollaborationTest extends TestCase
{
    use RefreshDatabase;

    private function note(User $user, array $attrs = []): Note
    {
        return Note::create(array_merge(['user_id' => $user->id, 'body' => 'corpo'], $attrs));
    }

    public function test_owner_adds_collaborator_by_email(): void
    {
        $owner = User::factory()->create();
        $mate = User::factory()->create(['email' => 'mate@taskai.test']);
        $note = $this->note($owner);

        $this->actingAs($owner);
        $res = $this->postJson("/api/notes/{$note->id}/collaborators", ['email' => 'mate@taskai.test']);
        $res->assertOk();
        $this->assertCount(1, $res->json('note.collaborators'));
        $this->assertSame('edit', $res->json('note.collaborators.0.permission'));
        $this->assertSame((string) $mate->id, $res->json('note.collaborators.0.id'));
        $this->assertDatabaseHas('note_collaborators', ['note_id' => $note->id, 'user_id' => $mate->id, 'invited_by' => $owner->id]);
    }

    public function test_add_collaborator_validations(): void
    {
        $owner = User::factory()->create();
        $mate = User::factory()->create(['email' => 'mate@taskai.test']);
        $note = $this->note($owner);
        $this->actingAs($owner);

        // e-mail inexistente -> 404
        $this->postJson("/api/notes/{$note->id}/collaborators", ['email' => 'ghost@taskai.test'])->assertNotFound();
        // próprio dono -> 422
        $this->postJson("/api/notes/{$note->id}/collaborators", ['email' => $owner->email])->assertStatus(422);
        // duplicado -> 422
        $this->postJson("/api/notes/{$note->id}/collaborators", ['email' => 'mate@taskai.test'])->assertOk();
        $this->postJson("/api/notes/{$note->id}/collaborators", ['email' => 'mate@taskai.test'])->assertStatus(422);
        // permissão inválida -> 422
        $this->postJson("/api/notes/{$note->id}/collaborators", ['email' => 'mate@taskai.test', 'permission' => 'admin'])->assertStatus(422);
    }

    public function test_non_owner_cannot_manage_sharing(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $victim = User::factory()->create(['email' => 'v@taskai.test']);
        $note = $this->note($owner);

        $this->actingAs($intruder);
        $this->postJson("/api/notes/{$note->id}/collaborators", ['email' => 'v@taskai.test'])->assertForbidden();
    }

    public function test_owner_removes_and_collaborator_removes_self(): void
    {
        $owner = User::factory()->create();
        $mate = User::factory()->create();
        $note = $this->note($owner);
        $note->collaborators()->attach($mate->id, ['permission' => 'edit']);

        // dono remove
        $this->actingAs($owner);
        $this->deleteJson("/api/notes/{$note->id}/collaborators/{$mate->id}")->assertOk();
        $this->assertDatabaseMissing('note_collaborators', ['note_id' => $note->id, 'user_id' => $mate->id]);

        // colaborador remove a si mesmo
        $note->collaborators()->attach($mate->id, ['permission' => 'edit']);
        $this->actingAs($mate);
        $this->deleteJson("/api/notes/{$note->id}/collaborators/{$mate->id}")->assertOk();
        $this->assertDatabaseMissing('note_collaborators', ['note_id' => $note->id, 'user_id' => $mate->id]);
    }

    public function test_third_party_cannot_remove_collaborator(): void
    {
        $owner = User::factory()->create();
        $mate = User::factory()->create();
        $stranger = User::factory()->create();
        $note = $this->note($owner);
        $note->collaborators()->attach($mate->id, ['permission' => 'edit']);

        $this->actingAs($stranger);
        $this->deleteJson("/api/notes/{$note->id}/collaborators/{$mate->id}")->assertForbidden();
    }

    public function test_policy_view_and_edit_for_collaborators(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();
        $note = $this->note($owner, ['title' => 'Compartilhada']);
        $note->collaborators()->attach($editor->id, ['permission' => 'edit']);
        $note->collaborators()->attach($viewer->id, ['permission' => 'view']);

        // editor: vê e edita
        $this->actingAs($editor);
        $this->getJson("/api/notes/{$note->id}")->assertOk();
        $this->patchJson("/api/notes/{$note->id}", ['title' => 'Editada pelo colaborador'])->assertOk();
        $this->assertSame('Editada pelo colaborador', $note->fresh()->title);

        // viewer: vê mas não edita
        $this->actingAs($viewer);
        $this->getJson("/api/notes/{$note->id}")->assertOk();
        $this->patchJson("/api/notes/{$note->id}", ['title' => 'hack'])->assertForbidden();

        // estranho: nem vê
        $this->actingAs($stranger);
        $this->getJson("/api/notes/{$note->id}")->assertForbidden();
    }

    public function test_collaborator_cannot_delete_note(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $note = $this->note($owner);
        $note->collaborators()->attach($editor->id, ['permission' => 'edit']);

        $this->actingAs($editor);
        $this->deleteJson("/api/notes/{$note->id}")->assertForbidden(); // delete continua exclusivo do dono
    }

    public function test_shared_note_appears_in_collaborator_bootstrap(): void
    {
        $owner = User::factory()->create(['name' => 'Dona Ana']);
        $mate = User::factory()->create();
        $note = $this->note($owner, ['title' => 'Projeto X']);
        $note->collaborators()->attach($mate->id, ['permission' => 'edit']);

        $this->actingAs($mate);
        $res = $this->getJson('/api/bootstrap');
        $res->assertOk();
        $shared = collect($res->json('notes'))->firstWhere('id', (string) $note->id);
        $this->assertNotNull($shared, 'a nota compartilhada deve aparecer no bootstrap do colaborador');
        $this->assertFalse($shared['isOwner']);
        $this->assertSame('Dona Ana', $shared['ownerName']);
        $this->assertCount(1, $shared['collaborators']);
    }

    public function test_force_delete_detaches_collaborators(): void
    {
        $owner = User::factory()->create();
        $mate = User::factory()->create();
        $note = $this->note($owner);
        $note->collaborators()->attach($mate->id, ['permission' => 'edit']);

        $this->actingAs($owner);
        $this->deleteJson("/api/notes/{$note->id}");          // soft delete
        $this->deleteJson("/api/notes/{$note->id}/force")->assertOk();
        $this->assertDatabaseMissing('note_collaborators', ['note_id' => $note->id]);
    }

    public function test_shell_wires_phase5_phase6_modules(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $res = $this->get('/');
        $res->assertOk();
        $res->assertSee('app/notes-collab.js');
        $res->assertSee('app/notes-export.js');
    }
}
