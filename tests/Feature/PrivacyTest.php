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
 * Garante que conteúdo NÃO compartilhado é invisível para terceiros em TODOS os
 * payloads do bootstrap (tarefas, notas, projetos, cadernos, áreas) — privacidade
 * é requisito inegociável.
 */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function provisioned(?string $email = null): User
    {
        $user = User::factory()->create($email ? ['email' => $email] : []);
        (new ProvisionWorkspace)->for($user);

        return $user;
    }

    public function test_private_content_does_not_leak_to_strangers(): void
    {
        $owner = $this->provisioned('owner@taskai.test');
        $stranger = $this->provisioned('stranger@taskai.test');

        $ws = Workspace::where('owner_id', $owner->id)->first();
        $project = $owner->projects()->where('slug', 'geral')->first();
        $notebook = Notebook::where('workspace_id', $ws->id)->first();
        $task = Task::create(['project_id' => $project->id, 'title' => 'Segredo', 'status' => 'pendente', 'priority' => 'media']);
        $note = $owner->notes()->create(['notebook_id' => $notebook->id, 'title' => 'Nota privada', 'body' => 'x']);

        $this->actingAs($stranger);
        $boot = $this->getJson('/api/bootstrap')->assertOk();

        $ids = fn (string $key, string $field = 'id') => collect($boot->json($key))->pluck($field)->map(fn ($v) => (string) $v)->all();

        $this->assertNotContains((string) $task->id, $ids('tasks'), 'tarefa privada vazou');
        $this->assertNotContains((string) $note->id, $ids('notes'), 'nota privada vazou');
        $this->assertNotContains((string) $project->id, $ids('projects'), 'projeto privado vazou');
        $this->assertNotContains((string) $notebook->id, $ids('notebooks'), 'caderno privado vazou');
        $this->assertNotContains((string) $ws->id, $ids('workspaces'), 'área privada vazou');

        // E os endpoints diretos não vazam existência
        $this->getJson("/api/notes/{$note->id}")->assertForbidden();
        $this->postJson("/api/tasks/{$task->id}/toggle")->assertNotFound();
    }

    public function test_full_workspace_share_keeps_projects_inside_it_not_in_virtual(): void
    {
        $owner = $this->provisioned('owner2@taskai.test');
        $mate = $this->provisioned('mate2@taskai.test');
        $ws = Workspace::where('owner_id', $owner->id)->first();
        $project = $owner->projects()->where('slug', 'sistemas')->first();

        $this->actingAs($owner);
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'mate2@taskai.test', 'permission' => 'edit'])->assertOk();

        $this->actingAs($mate);
        $boot = $this->getJson('/api/bootstrap')->assertOk();
        $proj = collect($boot->json('projects'))->firstWhere('id', $project->id);

        $this->assertNotNull($proj, 'projeto da área compartilhada deveria aparecer');
        $this->assertFalse($proj['sharedSolo'], 'projeto de área compartilhada inteira NÃO vai para a virtual');
        $this->assertSame((string) $ws->id, $proj['workspaceId'], 'projeto continua dentro da própria área');
    }
}
