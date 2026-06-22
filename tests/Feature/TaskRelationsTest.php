<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskLink;
use App\Models\TaskRelation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskRelationsTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);
        $a = $project->tasks()->create(['title' => 'A', 'status' => 'pendente', 'priority' => 'media']);
        $b = $project->tasks()->create(['title' => 'B', 'status' => 'pendente', 'priority' => 'media']);

        return [$user, $a, $b];
    }

    public function test_add_and_remove_external_link(): void
    {
        [$user, $a] = $this->scaffold();
        $this->actingAs($user);

        $res = $this->postJson("/api/tasks/{$a->id}/links", ['url' => 'exemplo.com/doc', 'label' => 'Doc']);
        $res->assertCreated();
        $link = $res->json('links.0');
        $this->assertSame('https://exemplo.com/doc', $link['url']); // normaliza esquema
        $this->assertSame('Doc', $link['label']);

        $this->deleteJson("/api/tasks/{$a->id}/links/{$link['id']}")->assertOk();
        $this->assertSame(0, TaskLink::where('task_id', $a->id)->count());
    }

    public function test_add_and_remove_task_relation(): void
    {
        [$user, $a, $b] = $this->scaffold();
        $this->actingAs($user);

        $res = $this->postJson("/api/tasks/{$a->id}/relations", ['related_id' => $b->id, 'type' => 'depende']);
        $res->assertCreated();
        $rel = $res->json('relations.0');
        $this->assertSame((string) $b->id, $rel['relatedId']);
        $this->assertSame('depende', $rel['type']);
        $this->assertSame('B', $rel['title']);

        $this->deleteJson("/api/tasks/{$a->id}/relations/{$rel['id']}")->assertOk();
        $this->assertSame(0, TaskRelation::where('task_id', $a->id)->count());
    }

    public function test_cannot_relate_to_inaccessible_task(): void
    {
        [$user, $a] = $this->scaffold();
        $stranger = User::factory()->create();
        $sp = Project::create(['user_id' => $stranger->id, 'slug' => 'geral', 'name' => 'Geral']);
        $foreign = $sp->tasks()->create(['title' => 'X', 'status' => 'pendente', 'priority' => 'media']);

        $this->actingAs($user);
        $this->postJson("/api/tasks/{$a->id}/relations", ['related_id' => $foreign->id, 'type' => 'relacionada'])
            ->assertNotFound(); // não vaza tarefa de terceiro
    }
}
