<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TaskRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Um editor de uma Área de Trabalho compartilhada pode criar projetos (e, por
 * consequência, tarefas) nela. Quem só visualiza, ou não é membro, não pode.
 */
class SharedWorkspaceProjectCreateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:Workspace} A é dono de WA; WA compartilhada com B na permissão dada. */
    private function scaffold(User $b, string $permission): array
    {
        $a = User::factory()->create();
        $wa = Workspace::create(['owner_id' => $a->id, 'name' => 'WA', 'position' => 0]);
        $wa->members()->attach($b->id, ['permission' => $permission, 'invited_by' => $a->id]);

        return [$a, $wa];
    }

    public function test_editor_can_create_project_in_shared_workspace(): void
    {
        $b = User::factory()->create();
        [$a, $wa] = $this->scaffold($b, 'edit');
        $this->actingAs($b);

        $res = $this->postJson('/api/projects', ['name' => 'Projeto do B', 'workspace_id' => $wa->id]);
        $res->assertCreated();
        $this->assertSame((string) $wa->id, $res->json('project.workspaceId'));

        $proj = Project::where('slug', $res->json('project.slug'))->first();
        $this->assertSame($b->id, $proj->user_id);        // criador vira dono do projeto
        $this->assertSame($wa->id, $proj->workspace_id);  // dentro da área compartilhada

        // visível para o dono da área (A) e para o criador (B)
        $slugsA = collect(app(TaskRepository::class)->projectsPayload($a))->pluck('slug');
        $slugsB = collect(app(TaskRepository::class)->projectsPayload($b))->pluck('slug');
        $this->assertTrue($slugsA->contains($proj->slug));
        $this->assertTrue($slugsB->contains($proj->slug));

        // B consegue criar uma tarefa no novo projeto
        $task = $this->postJson('/api/tasks', ['project' => $proj->slug]);
        $task->assertCreated();
        $this->assertSame($proj->slug, $task->json('project'));
    }

    public function test_viewer_cannot_create_project_in_shared_workspace(): void
    {
        $b = User::factory()->create();
        [, $wa] = $this->scaffold($b, 'view');
        $this->actingAs($b);

        $this->postJson('/api/projects', ['name' => 'Nope', 'workspace_id' => $wa->id])->assertNotFound();
    }

    public function test_non_member_cannot_create_project_in_workspace(): void
    {
        $owner = User::factory()->create();
        $wa = Workspace::create(['owner_id' => $owner->id, 'name' => 'WA', 'position' => 0]);
        $stranger = User::factory()->create();
        $this->actingAs($stranger);

        $this->postJson('/api/projects', ['name' => 'Nope', 'workspace_id' => $wa->id])->assertNotFound();
    }
}
