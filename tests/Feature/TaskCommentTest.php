<?php

namespace Tests\Feature;

use App\Actions\ProvisionWorkspace;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCommentTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);
        $task = $project->tasks()->create(['title' => 'T', 'status' => 'pendente', 'priority' => 'media']);
        $comment = $task->comments()->create(['user_id' => $user->id, 'comment' => 'original', 'author' => $user->name, 'is_ai' => false]);

        return [$user, $task, $comment];
    }

    public function test_author_can_edit_and_delete_own_comment(): void
    {
        [$user, $task, $comment] = $this->scaffold();
        $this->actingAs($user);

        $res = $this->patchJson("/api/tasks/{$task->id}/comments/{$comment->id}", ['text' => 'editado']);
        $res->assertOk();
        $this->assertSame('editado', collect($res->json('comments'))->firstWhere('id', (string) $comment->id)['text']);

        $res = $this->deleteJson("/api/tasks/{$task->id}/comments/{$comment->id}");
        $res->assertOk();
        $this->assertSame(0, TaskComment::where('id', $comment->id)->count());
    }

    public function test_other_user_cannot_edit_or_delete_comment(): void
    {
        // dono compartilha a área (edit) com um colega; o colega NÃO pode mexer no comentário alheio
        $owner = User::factory()->create(['email' => 'o@taskai.test']);
        (new ProvisionWorkspace)->for($owner);
        $mate = User::factory()->create(['email' => 'm@taskai.test']);
        (new ProvisionWorkspace)->for($mate);
        $ws = Workspace::where('owner_id', $owner->id)->first();
        $project = $owner->projects()->where('slug', 'geral')->first();
        $task = $project->tasks()->create(['title' => 'T', 'status' => 'pendente', 'priority' => 'media']);
        $comment = $task->comments()->create(['user_id' => $owner->id, 'comment' => 'do dono', 'author' => $owner->name, 'is_ai' => false]);

        $this->actingAs($owner);
        $this->postJson("/api/workspaces/{$ws->id}/members", ['email' => 'm@taskai.test', 'permission' => 'edit'])->assertOk();

        $this->actingAs($mate);
        $this->patchJson("/api/tasks/{$task->id}/comments/{$comment->id}", ['text' => 'hack'])->assertForbidden();
        $this->deleteJson("/api/tasks/{$task->id}/comments/{$comment->id}")->assertForbidden();
        $this->assertSame('do dono', $comment->fresh()->comment);
    }
}
