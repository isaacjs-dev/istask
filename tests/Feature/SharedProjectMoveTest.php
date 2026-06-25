<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Garante que salvar uma tarefa num projeto COMPARTILHADO (com edição) realmente
 * vincula a tarefa a esse projeto — e não a deixa cair no projeto padrão "Geral".
 */
class SharedProjectMoveTest extends TestCase
{
    use RefreshDatabase;

    /** Projeto PA do dono A; B tem sua própria área com tarefa TB em "geral". @return array{0:Project,1:User,2:Task} */
    private function scaffold(string $bPermission): array
    {
        $a = User::factory()->create();
        $wa = Workspace::create(['owner_id' => $a->id, 'name' => 'WA', 'position' => 0]);
        $pa = Project::create(['user_id' => $a->id, 'workspace_id' => $wa->id, 'slug' => 'pa-compartilhado', 'name' => 'PA']);

        $b = User::factory()->create();
        $wb = Workspace::create(['owner_id' => $b->id, 'name' => 'WB', 'position' => 0]);
        $geralB = Project::create(['user_id' => $b->id, 'workspace_id' => $wb->id, 'slug' => 'geral', 'name' => 'Geral']);
        $tb = $geralB->tasks()->create(['title' => 'TB', 'status' => 'pendente', 'priority' => 'media', 'position' => 0]);

        // compartilha o projeto PA com B
        $pa->members()->attach($b->id, ['permission' => $bPermission, 'invited_by' => $a->id]);

        return [$pa, $b, $tb];
    }

    private function syncPayload(array $extra = []): array
    {
        return array_merge([
            'title' => 'TB', 'status' => 'pendente', 'priority' => 'media', 'checklist' => [], 'comments' => [],
        ], $extra);
    }

    public function test_editor_can_move_task_into_shared_project(): void
    {
        [$pa, $b, $tb] = $this->scaffold('edit');
        $this->actingAs($b);

        $res = $this->putJson("/api/tasks/{$tb->id}", $this->syncPayload(['project' => 'pa-compartilhado']));
        $res->assertOk();
        $this->assertSame('pa-compartilhado', $res->json('task.project'));
        $this->assertSame($pa->id, $tb->fresh()->project_id);
    }

    public function test_viewer_cannot_move_task_into_shared_project(): void
    {
        [, $b, $tb] = $this->scaffold('view');
        $originalProjectId = $tb->project_id;
        $this->actingAs($b);

        // B é só leitor de PA: o projeto não deve mudar (continua em "geral")
        $res = $this->putJson("/api/tasks/{$tb->id}", $this->syncPayload(['project' => 'pa-compartilhado']));
        $res->assertOk();
        $this->assertSame('geral', $res->json('task.project'));
        $this->assertSame($originalProjectId, $tb->fresh()->project_id);
    }
}
