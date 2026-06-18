<?php

namespace Tests\Feature;

use App\Actions\ProvisionWorkspace;
use App\Models\Note;
use App\Models\Notebook;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 2: compartilhamento de Áreas/Projetos/Cadernos, permissões em cascata
 * (editar|ver) e transferência de propriedade.
 */
class SharingAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function provisioned(?string $email = null): User
    {
        $user = User::factory()->create($email ? ['email' => $email] : []);
        (new ProvisionWorkspace)->for($user);

        return $user;
    }

    private function workspace(User $u): Workspace
    {
        return Workspace::where('owner_id', $u->id)->first();
    }

    private function project(User $u): Project
    {
        return $u->projects()->where('slug', 'geral')->first();
    }

    private function notebook(User $u): Notebook
    {
        return Notebook::where('workspace_id', $this->workspace($u)->id)->first();
    }

    private function task(User $u): Task
    {
        return Task::create(['project_id' => $this->project($u)->id, 'title' => 'T', 'status' => 'pendente', 'priority' => 'media']);
    }

    private function note(User $u): Note
    {
        return $u->notes()->create(['notebook_id' => $this->notebook($u)->id, 'body' => 'n']);
    }

    // ---------- Compartilhar área ----------

    public function test_share_workspace_member_sees_content_in_bootstrap(): void
    {
        $owner = $this->provisioned();
        $mate = $this->provisioned('mate@taskai.test');
        $task = $this->task($owner);
        $note = $this->note($owner);
        $ws = $this->workspace($owner);

        $this->actingAs($owner);
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'mate@taskai.test', 'permission' => 'edit'])->assertOk();

        $this->actingAs($mate);
        $boot = $this->getJson('/api/bootstrap')->assertOk();
        $this->assertContains((string) $ws->id, collect($boot->json('workspaces'))->pluck('id')->all());
        $this->assertContains((string) $task->id, collect($boot->json('tasks'))->pluck('id')->all());
        $this->assertContains((string) $note->id, collect($boot->json('notes'))->pluck('id')->all());
        $shared = collect($boot->json('workspaces'))->firstWhere('id', (string) $ws->id);
        $this->assertFalse($shared['isOwner']);
        $this->assertSame('edit', $shared['permission']);
    }

    public function test_workspace_edit_member_can_edit_task_and_note(): void
    {
        $owner = $this->provisioned();
        $mate = $this->provisioned('mate@taskai.test');
        $task = $this->task($owner);
        $note = $this->note($owner);
        $ws = $this->workspace($owner);
        $this->actingAs($owner);
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'mate@taskai.test', 'permission' => 'edit'])->assertOk();

        $this->actingAs($mate);
        $this->postJson("/api/tasks/{$task->id}/toggle")->assertOk();           // edita tarefa via cascata
        $this->patchJson("/api/notes/{$note->id}", ['title' => 'edit'])->assertOk(); // edita nota via cascata
    }

    public function test_workspace_view_member_cannot_edit(): void
    {
        $owner = $this->provisioned();
        $mate = $this->provisioned('mate@taskai.test');
        $task = $this->task($owner);
        $note = $this->note($owner);
        $ws = $this->workspace($owner);
        $this->actingAs($owner);
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'mate@taskai.test', 'permission' => 'view'])->assertOk();

        $this->actingAs($mate);
        $this->postJson("/api/tasks/{$task->id}/toggle")->assertForbidden();     // 403 (somente-visualização)
        $this->patchJson("/api/notes/{$note->id}", ['title' => 'x'])->assertForbidden();
        // mas continua vendo no bootstrap
        $this->assertContains((string) $task->id, collect($this->getJson('/api/bootstrap')->json('tasks'))->pluck('id')->all());
    }

    public function test_non_member_has_no_access(): void
    {
        $owner = $this->provisioned();
        $stranger = $this->provisioned('s@taskai.test');
        $task = $this->task($owner);
        $note = $this->note($owner);

        $this->actingAs($stranger);
        $this->postJson("/api/tasks/{$task->id}/toggle")->assertNotFound();      // 404 não vaza
        $this->getJson("/api/notes/{$note->id}")->assertForbidden();
        $this->assertNotContains((string) $task->id, collect($this->getJson('/api/bootstrap')->json('tasks'))->pluck('id')->all());
    }

    // ---------- Compartilhar projeto / caderno (escopo restrito) ----------

    public function test_share_project_grants_only_that_projects_tasks(): void
    {
        $owner = $this->provisioned();
        $mate = $this->provisioned('mate@taskai.test');
        $projA = $this->project($owner);
        $projB = $owner->projects()->where('slug', 'sistemas')->first();
        $taskA = Task::create(['project_id' => $projA->id, 'title' => 'A', 'status' => 'pendente', 'priority' => 'media']);
        $taskB = Task::create(['project_id' => $projB->id, 'title' => 'B', 'status' => 'pendente', 'priority' => 'media']);

        $this->actingAs($owner);
        $this->postJson("/api/projects/{$projA->id}/members", ['email' => 'mate@taskai.test', 'permission' => 'edit'])->assertOk();

        $this->actingAs($mate);
        $this->postJson("/api/tasks/{$taskA->id}/toggle")->assertOk();           // projeto compartilhado
        $this->postJson("/api/tasks/{$taskB->id}/toggle")->assertNotFound();     // outro projeto: sem acesso
    }

    public function test_share_notebook_grants_note_edit(): void
    {
        $owner = $this->provisioned();
        $mate = $this->provisioned('mate@taskai.test');
        $nb = $this->notebook($owner);
        $note = $owner->notes()->create(['notebook_id' => $nb->id, 'body' => 'x']);

        $this->actingAs($owner);
        $this->postJson("/api/notebooks/{$nb->id}/members", ['email' => 'mate@taskai.test', 'permission' => 'edit'])->assertOk();

        $this->actingAs($mate);
        $this->patchJson("/api/notes/{$note->id}", ['title' => 'editado'])->assertOk();
    }

    // ---------- Gerenciar membros ----------

    public function test_owner_removes_and_member_leaves(): void
    {
        $owner = $this->provisioned();
        $mate = $this->provisioned('mate@taskai.test');
        $ws = $this->workspace($owner);

        $this->actingAs($owner);
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'mate@taskai.test'])->assertOk();
        $this->deleteJson("/api/workspaces/{$ws->id}/members/{$mate->id}")->assertOk();
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $ws->id, 'user_id' => $mate->id]);

        // re-adiciona e o próprio membro sai
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'mate@taskai.test'])->assertOk();
        $this->actingAs($mate);
        $this->deleteJson("/api/workspaces/{$ws->id}/members/{$mate->id}")->assertOk();
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $ws->id, 'user_id' => $mate->id]);
    }

    public function test_change_member_permission(): void
    {
        $owner = $this->provisioned();
        $mate = $this->provisioned('mate@taskai.test');
        $ws = $this->workspace($owner);
        $this->actingAs($owner);

        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'mate@taskai.test', 'permission' => 'view'])->assertOk();
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'mate@taskai.test', 'permission' => 'edit'])->assertOk();
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $ws->id, 'user_id' => $mate->id, 'permission' => 'edit']);
    }

    public function test_member_add_validations_and_non_owner_blocked(): void
    {
        $owner = $this->provisioned();
        $intruder = $this->provisioned('i@taskai.test');
        $ws = $this->workspace($owner);

        $this->actingAs($owner);
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'ghost@taskai.test'])->assertNotFound();
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => $owner->email])->assertStatus(422);

        $this->actingAs($intruder);
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'i@taskai.test'])->assertForbidden();
    }

    // ---------- Transferência de propriedade ----------

    public function test_transfer_workspace_ownership(): void
    {
        $owner = $this->provisioned();
        $mate = $this->provisioned('mate@taskai.test');
        $ws = $this->workspace($owner);
        $project = $this->project($owner);
        $this->actingAs($owner);
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'mate@taskai.test', 'permission' => 'edit'])->assertOk();

        $this->postJson("/api/workspaces/{$ws->id}/transfer", ['user_id' => $mate->id])->assertOk();

        $this->assertSame($mate->id, $ws->fresh()->owner_id);
        $this->assertSame($mate->id, $project->fresh()->user_id);                       // projetos seguem o novo dono
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $ws->id, 'user_id' => $owner->id, 'permission' => 'edit']); // antigo dono vira editor
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $ws->id, 'user_id' => $mate->id]);
    }

    public function test_transfer_requires_member_and_owner(): void
    {
        $owner = $this->provisioned();
        $stranger = $this->provisioned('s@taskai.test');
        $ws = $this->workspace($owner);

        $this->actingAs($owner);
        $this->postJson("/api/workspaces/{$ws->id}/transfer", ['user_id' => $stranger->id])->assertStatus(422); // não é membro

        $this->actingAs($stranger);
        $this->postJson("/api/workspaces/{$ws->id}/transfer", ['user_id' => $stranger->id])->assertForbidden();  // não é dono
    }

    public function test_transfer_project_ownership(): void
    {
        $owner = $this->provisioned();
        $mate = $this->provisioned('mate@taskai.test');
        $project = $this->project($owner);
        $this->actingAs($owner);
        $this->postJson("/api/projects/{$project->id}/members", ['email' => 'mate@taskai.test', 'permission' => 'edit'])->assertOk();

        $this->postJson("/api/projects/{$project->id}/transfer", ['user_id' => $mate->id])->assertOk();
        $this->assertSame($mate->id, $project->fresh()->user_id);
        $this->assertDatabaseHas('project_members', ['project_id' => $project->id, 'user_id' => $owner->id, 'permission' => 'edit']);
    }

    public function test_edit_member_can_create_note_in_shared_notebook(): void
    {
        $owner = $this->provisioned();
        $editor = $this->provisioned('editor@taskai.test');
        $viewer = $this->provisioned('viewer@taskai.test');
        $nb = $this->notebook($owner);
        $this->actingAs($owner);
        $this->postJson("/api/notebooks/{$nb->id}/members", ['email' => 'editor@taskai.test', 'permission' => 'edit'])->assertOk();
        $this->postJson("/api/notebooks/{$nb->id}/members", ['email' => 'viewer@taskai.test', 'permission' => 'view'])->assertOk();

        $this->actingAs($editor);
        $res = $this->postJson('/api/notes', ['body' => 'do membro', 'notebook_id' => $nb->id]);
        $res->assertCreated();
        $this->assertSame((string) $nb->id, $res->json('note.notebookId'));

        $this->actingAs($viewer);
        $this->postJson('/api/notes', ['body' => 'tentativa', 'notebook_id' => $nb->id])->assertNotFound();
    }

    public function test_active_shared_workspace_persists_in_bootstrap(): void
    {
        $owner = $this->provisioned();
        $mate = $this->provisioned('mate@taskai.test');
        $ws = $this->workspace($owner);
        $this->actingAs($owner);
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'mate@taskai.test'])->assertOk();

        $this->actingAs($mate);
        $this->putJson('/api/preferences', ['activeWorkspaceId' => $ws->id])->assertOk();
        $this->assertSame((string) $ws->id, $this->getJson('/api/bootstrap')->json('activeWorkspaceId'));
    }

    public function test_shell_wires_share_modal(): void
    {
        $user = $this->provisioned();
        $this->actingAs($user);
        $this->get('/')->assertOk()->assertSee('app/share-modal.js');
    }
}
