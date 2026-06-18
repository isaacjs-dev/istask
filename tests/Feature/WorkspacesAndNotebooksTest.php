<?php

namespace Tests\Feature;

use App\Actions\ProvisionWorkspace;
use App\Models\Note;
use App\Models\Notebook;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1: Áreas de Trabalho + Cadernos (estrutura, migração e gerenciamento).
 */
class WorkspacesAndNotebooksTest extends TestCase
{
    use RefreshDatabase;

    private function provisioned(): User
    {
        $user = User::factory()->create();
        (new ProvisionWorkspace)->for($user);

        return $user;
    }

    public function test_provision_creates_personal_workspace_geral_notebook_and_projects(): void
    {
        $user = $this->provisioned();

        $ws = Workspace::where('owner_id', $user->id)->get();
        $this->assertCount(1, $ws);
        $this->assertSame('Pessoal', $ws->first()->name);
        $this->assertCount(5, $user->projects()->get());
        $this->assertTrue($user->projects()->whereNull('workspace_id')->doesntExist());
        $this->assertSame('Geral', Notebook::where('workspace_id', $ws->first()->id)->first()->name);
    }

    public function test_backfill_migration_links_legacy_data(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']); // legado sem workspace
        $note = Note::create(['user_id' => $user->id, 'body' => 'legado']); // legado sem caderno

        (require base_path('database/migrations/2026_06_15_000005_backfill_workspaces_and_notebooks.php'))->up();

        $ws = Workspace::where('owner_id', $user->id)->first();
        $this->assertNotNull($ws);
        $this->assertSame('Pessoal', $ws->name);
        $this->assertSame($ws->id, $project->fresh()->workspace_id);
        $notebook = Notebook::where('workspace_id', $ws->id)->first();
        $this->assertSame('Geral', $notebook->name);
        $this->assertSame($notebook->id, $note->fresh()->notebook_id);
    }

    // ---------- Áreas ----------

    public function test_create_rename_workspace_creates_default_notebook(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);

        $res = $this->postJson('/api/workspaces', ['name' => 'Trabalho']);
        $res->assertCreated();
        $id = (int) $res->json('workspace.id');
        $this->assertSame('Trabalho', $res->json('workspace.name'));
        $this->assertTrue($res->json('workspace.isOwner'));
        $this->assertSame('Geral', Notebook::where('workspace_id', $id)->first()->name);

        $this->patchJson("/api/workspaces/{$id}", ['name' => 'Trampo'])->assertOk();
        $this->assertSame('Trampo', Workspace::find($id)->name);
    }

    public function test_cannot_delete_last_workspace(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $only = Workspace::where('owner_id', $user->id)->first();

        $this->deleteJson("/api/workspaces/{$only->id}")->assertStatus(422);
    }

    public function test_delete_workspace_moves_projects_and_notebooks_to_fallback(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $personal = Workspace::where('owner_id', $user->id)->first();
        $extra = $this->postJson('/api/workspaces', ['name' => 'Extra'])->json('workspace.id');
        $project = $user->projects()->create(['workspace_id' => $extra, 'slug' => 'x', 'name' => 'X']);
        $notebookInExtra = Notebook::where('workspace_id', $extra)->first();

        $this->deleteJson("/api/workspaces/{$extra}")->assertOk();

        $this->assertSoftDeleted('workspaces', ['id' => $extra]);
        $this->assertSame($personal->id, $project->fresh()->workspace_id);          // projeto migrou
        $this->assertSame($personal->id, $notebookInExtra->fresh()->workspace_id);  // caderno migrou
    }

    public function test_non_owner_cannot_touch_workspace(): void
    {
        $owner = $this->provisioned();
        $intruder = $this->provisioned();
        $ws = Workspace::where('owner_id', $owner->id)->first();

        $this->actingAs($intruder);
        $this->patchJson("/api/workspaces/{$ws->id}", ['name' => 'hack'])->assertNotFound();
        $this->deleteJson("/api/workspaces/{$ws->id}")->assertNotFound();
    }

    // ---------- Cadernos ----------

    public function test_create_rename_delete_notebook(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $ws = Workspace::where('owner_id', $user->id)->first();

        $res = $this->postJson('/api/notebooks', ['name' => 'Estudos', 'workspace_id' => $ws->id, 'color' => 'mint']);
        $res->assertCreated();
        $id = (int) $res->json('notebook.id');
        $this->assertSame('Estudos', $res->json('notebook.name'));

        $this->patchJson("/api/notebooks/{$id}", ['name' => 'Cursos', 'color' => 'blue'])->assertOk();
        $this->assertSame('Cursos', Notebook::find($id)->name);

        // excluir move as notas para o caderno restante (Geral)
        $geral = Notebook::where('workspace_id', $ws->id)->where('name', 'Geral')->first();
        $note = $user->notes()->create(['notebook_id' => $id, 'body' => 'oi']);
        $this->deleteJson("/api/notebooks/{$id}")->assertOk();
        $this->assertSoftDeleted('notebooks', ['id' => $id]);
        $this->assertSame($geral->id, $note->fresh()->notebook_id);
    }

    public function test_cannot_delete_last_notebook_of_workspace(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $ws = Workspace::where('owner_id', $user->id)->first();
        $only = Notebook::where('workspace_id', $ws->id)->first();

        $this->deleteJson("/api/notebooks/{$only->id}")->assertStatus(422);
    }

    public function test_notebook_in_other_users_workspace_is_forbidden(): void
    {
        $owner = $this->provisioned();
        $intruder = $this->provisioned();
        $ws = Workspace::where('owner_id', $owner->id)->first();

        $this->actingAs($intruder);
        $this->postJson('/api/notebooks', ['name' => 'x', 'workspace_id' => $ws->id])->assertNotFound();
    }

    // ---------- Mover ----------

    public function test_move_note_between_notebooks(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $ws = Workspace::where('owner_id', $user->id)->first();
        $geral = Notebook::where('workspace_id', $ws->id)->first();
        $other = Notebook::create(['workspace_id' => $ws->id, 'name' => 'Outro', 'position' => 1]);
        $note = $user->notes()->create(['notebook_id' => $geral->id, 'body' => 'x']);

        $res = $this->postJson("/api/notes/{$note->id}/move", ['notebook_id' => $other->id]);
        $res->assertOk();
        $this->assertSame((string) $other->id, $res->json('note.notebookId'));
        $this->assertSame($other->id, $note->fresh()->notebook_id);

        // caderno de outro usuário -> 404
        $intruder = $this->provisioned();
        $foreignNb = Notebook::where('workspace_id', Workspace::where('owner_id', $intruder->id)->first()->id)->first();
        $this->postJson("/api/notes/{$note->id}/move", ['notebook_id' => $foreignNb->id])->assertNotFound();
    }

    public function test_move_project_between_workspaces(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $personal = Workspace::where('owner_id', $user->id)->first();
        $extra = (int) $this->postJson('/api/workspaces', ['name' => 'Extra'])->json('workspace.id');
        $project = $user->projects()->create(['workspace_id' => $personal->id, 'slug' => 'p', 'name' => 'P']);

        $res = $this->postJson("/api/projects/{$project->id}/move", ['workspace_id' => $extra]);
        $res->assertOk();
        $this->assertSame($extra, $project->fresh()->workspace_id);
    }

    public function test_create_project_lands_in_given_workspace(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $extra = (int) $this->postJson('/api/workspaces', ['name' => 'Extra'])->json('workspace.id');

        $res = $this->postJson('/api/projects', ['name' => 'Lançamento', 'workspace_id' => $extra]);
        $res->assertCreated();
        $this->assertSame((string) $extra, $res->json('project.workspaceId'));
    }

    // ---------- Bootstrap / prefs ----------

    public function test_bootstrap_exposes_workspaces_notebooks_and_scoping_fields(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $ws = Workspace::where('owner_id', $user->id)->first();
        $nb = Notebook::where('workspace_id', $ws->id)->first();
        $user->notes()->create(['notebook_id' => $nb->id, 'body' => 'n']);

        $res = $this->getJson('/api/bootstrap');
        $res->assertOk();
        $this->assertCount(1, $res->json('workspaces'));
        $this->assertSame((string) $ws->id, $res->json('activeWorkspaceId'));
        $this->assertNotEmpty($res->json('notebooks'));
        $this->assertSame((string) $ws->id, collect($res->json('projects'))->first()['workspaceId']);
        $this->assertSame((string) $nb->id, collect($res->json('notes'))->first()['notebookId']);
    }

    public function test_active_workspace_id_preference_persists(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $ws = Workspace::where('owner_id', $user->id)->first();

        $res = $this->putJson('/api/preferences', ['activeWorkspaceId' => $ws->id]);
        $res->assertOk();
        $this->assertSame($ws->id, $user->fresh()->prefs()['activeWorkspaceId']);
    }

    public function test_shell_wires_workspaces_modal(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $this->get('/')->assertOk()->assertSee('app/workspaces-modal.js');
    }
}
