<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCreationContextTest extends TestCase
{
    use RefreshDatabase;

    private function seedCtx(): array
    {
        $user = User::factory()->create();
        $ws = Workspace::create(['owner_id' => $user->id, 'name' => 'Pessoal', 'position' => 0]);
        $geral = Project::create(['user_id' => $user->id, 'workspace_id' => $ws->id, 'slug' => 'geral', 'name' => 'Geral']);
        $sis = Project::create(['user_id' => $user->id, 'workspace_id' => $ws->id, 'slug' => 'sistemas', 'name' => 'Sistemas']);

        return [$user, $geral, $sis];
    }

    public function test_store_creates_task_in_context_project(): void
    {
        [$user, , $sis] = $this->seedCtx();
        $this->actingAs($user);

        $res = $this->postJson('/api/tasks', ['project' => 'sistemas']);
        $res->assertCreated();
        $this->assertSame('sistemas', $res->json('project'));
        $this->assertSame($sis->id, Task::find($res->json('id'))->project_id);
    }

    public function test_store_defaults_to_geral_without_context(): void
    {
        [$user] = $this->seedCtx();
        $this->actingAs($user);

        $res = $this->postJson('/api/tasks', []);
        $res->assertCreated();
        $this->assertSame('geral', $res->json('project'));
    }

    public function test_store_falls_back_when_project_not_accessible(): void
    {
        [$user] = $this->seedCtx();
        // projeto de outra pessoa — não acessível
        $other = User::factory()->create();
        Project::create(['user_id' => $other->id, 'slug' => 'alheio', 'name' => 'Alheio']);
        $this->actingAs($user);

        $res = $this->postJson('/api/tasks', ['project' => 'alheio']);
        $res->assertCreated();
        $this->assertSame('geral', $res->json('project')); // não vaza p/ projeto alheio
    }
}
