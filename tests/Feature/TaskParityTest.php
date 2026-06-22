<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskParityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Project, 2: Task} */
    private function scaffold(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);
        $task = $project->tasks()->create(['title' => 'Tarefa', 'status' => 'pendente', 'priority' => 'media', 'position' => 0]);

        return [$user, $project, $task];
    }

    public function test_sync_persists_advanced_dates_and_labels(): void
    {
        [$user, , $task] = $this->scaffold();
        $label = Label::create(['user_id' => $user->id, 'name' => 'Importante']);
        $this->actingAs($user);

        $res = $this->putJson("/api/tasks/{$task->id}", [
            'title' => 'Tarefa', 'status' => 'pendente', 'priority' => 'alta',
            'startDate' => '2026-06-20', 'estimatedMinutes' => 90,
            'recurrence' => 'weekly', 'remindAt' => '2026-06-21 09:00:00',
            'labelIds' => [$label->id], 'checklist' => [], 'comments' => [],
        ]);

        $res->assertOk();
        $this->assertSame('2026-06-20', $res->json('task.startDate'));
        $this->assertSame(90, $res->json('task.estimatedMinutes'));
        $this->assertSame('weekly', $res->json('task.recurrence'));
        $this->assertNotNull($res->json('task.remindAt'));
        $this->assertEqualsCanonicalizing([(string) $label->id], $res->json('task.labelIds'));

        $task->refresh();
        $this->assertSame('weekly', $task->recurrence);
        $this->assertSame(90, $task->estimated_minutes);
        $this->assertTrue($task->labels->contains($label->id));
    }

    public function test_sync_persists_advanced_checklist_fields(): void
    {
        [$user, , $task] = $this->scaffold();
        $this->actingAs($user);

        $res = $this->putJson("/api/tasks/{$task->id}", [
            'title' => 'Tarefa', 'status' => 'pendente', 'priority' => 'media',
            'checklist' => [['id' => null, 'text' => 'passo', 'done' => false, 'assignee' => 'Maria', 'priority' => 'alta', 'due' => '2026-06-25']],
            'comments' => [],
        ]);

        $res->assertOk();
        $step = $res->json('task.checklist.0');
        $this->assertSame('Maria', $step['assignee']);
        $this->assertSame('alta', $step['priority']);
        $this->assertSame('2026-06-25', $step['due']);
    }

    public function test_label_ids_are_scoped_to_acting_user(): void
    {
        [$user, , $task] = $this->scaffold();
        $other = User::factory()->create();
        $foreign = Label::create(['user_id' => $other->id, 'name' => 'Alheia']);
        $this->actingAs($user);

        $res = $this->putJson("/api/tasks/{$task->id}", [
            'title' => 'Tarefa', 'status' => 'pendente', 'priority' => 'media',
            'labelIds' => [$foreign->id], 'checklist' => [], 'comments' => [],
        ]);

        $res->assertOk();
        $this->assertSame([], $res->json('task.labelIds')); // rótulo de outro usuário não é vinculado
    }

    public function test_completing_recurring_task_spawns_next_occurrence(): void
    {
        [$user, $project, $task] = $this->scaffold();
        $task->update(['recurrence' => 'daily', 'due_date' => '2026-06-20']);
        $this->actingAs($user);

        $res = $this->postJson("/api/tasks/{$task->id}/toggle");
        $res->assertOk();

        $spawned = $res->json('spawnedTask');
        $this->assertNotNull($spawned, 'deveria gerar a próxima ocorrência');
        $this->assertSame('2026-06-21', $spawned['due']);
        $this->assertSame('pendente', $spawned['status']);
        $this->assertSame('daily', $spawned['recurrence']);
        $this->assertSame(2, Task::where('project_id', $project->id)->count());
    }

    public function test_non_recurring_task_does_not_spawn(): void
    {
        [$user, $project, $task] = $this->scaffold();
        $this->actingAs($user);

        $res = $this->postJson("/api/tasks/{$task->id}/toggle");
        $res->assertOk();
        $this->assertNull($res->json('spawnedTask'));
        $this->assertSame(1, Task::where('project_id', $project->id)->count());
    }

    public function test_duplicate_clones_task_with_steps_and_labels(): void
    {
        [$user, $project, $task] = $this->scaffold();
        $task->steps()->create(['title' => 'passo 1', 'status' => 'pending', 'position' => 0]);
        $label = Label::create(['user_id' => $user->id, 'name' => 'X']);
        $task->labels()->sync([$label->id]);
        $this->actingAs($user);

        $res = $this->postJson("/api/tasks/{$task->id}/duplicate");
        $res->assertCreated();
        $this->assertStringContainsString('(cópia)', $res->json('title'));
        $this->assertCount(1, $res->json('checklist'));
        $this->assertEqualsCanonicalizing([(string) $label->id], $res->json('labelIds'));
        $this->assertSame(2, Task::where('project_id', $project->id)->count());
    }

    public function test_archive_and_restore_toggle(): void
    {
        [$user, , $task] = $this->scaffold();
        $this->actingAs($user);

        $res = $this->postJson("/api/tasks/{$task->id}/archive");
        $res->assertOk();
        $this->assertNotNull($res->json('archivedAt'));

        $res = $this->postJson("/api/tasks/{$task->id}/archive");
        $res->assertOk();
        $this->assertNull($res->json('archivedAt'));
    }

    public function test_reminders_due_fires_notification_once(): void
    {
        [$user, , $task] = $this->scaffold();
        $task->update(['remind_at' => now()->subMinutes(5)]);
        $this->actingAs($user);

        $res = $this->getJson('/api/tasks/reminders/due');
        $res->assertOk();
        $this->assertEqualsCanonicalizing([(string) $task->id], $res->json('fired'));
        $this->assertSame(1, $user->notifications()->count());

        // segundo polling não dispara de novo
        $res = $this->getJson('/api/tasks/reminders/due');
        $this->assertSame([], $res->json('fired'));
        $this->assertSame(1, $user->fresh()->notifications()->count());
    }
}
