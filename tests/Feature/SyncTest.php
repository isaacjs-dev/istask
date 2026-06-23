<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TaskRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:Task,2:Workspace} */
    private function sharedScaffold(): array
    {
        Carbon::setTestNow('2026-06-23 10:00:00');
        $a = User::factory()->create();
        $wa = Workspace::create(['owner_id' => $a->id, 'name' => 'WA', 'position' => 0]);
        $pa = Project::create(['user_id' => $a->id, 'workspace_id' => $wa->id, 'slug' => 'pa', 'name' => 'PA']);
        $ta = $pa->tasks()->create(['title' => 'TA', 'status' => 'pendente', 'priority' => 'media', 'position' => 0]);
        $b = User::factory()->create();
        $wa->members()->attach($b->id, ['permission' => 'edit', 'invited_by' => $a->id]);

        return [$b, $ta, $wa];
    }

    public function test_has_changes_since_detects_shared_content_update_and_respects_scope(): void
    {
        [$b, $ta] = $this->sharedScaffold();
        $repo = app(TaskRepository::class);
        $baseline = Carbon::parse('2026-06-23 10:05:00');

        // nada mudou depois da linha de base
        $this->assertFalse($repo->hasChangesSince($b, $baseline));

        // o dono edita a tarefa compartilhada às 10:10
        Carbon::setTestNow('2026-06-23 10:10:00');
        $ta->update(['title' => 'TA editada']);

        // o colaborador (com acesso) detecta a mudança
        $this->assertTrue($repo->hasChangesSince($b, $baseline));

        // um estranho (sem acesso) não detecta nada
        $stranger = User::factory()->create();
        $this->assertFalse($repo->hasChangesSince($stranger, $baseline));

        Carbon::setTestNow();
    }

    public function test_sync_endpoint_returns_changed_payload_for_collaborator(): void
    {
        [$b, $ta] = $this->sharedScaffold();
        Carbon::setTestNow('2026-06-23 10:10:00');
        $ta->update(['title' => 'TA2']);
        $this->actingAs($b);

        $res = $this->getJson('/api/sync?since=' . urlencode('2026-06-23 10:05:00'));
        $res->assertOk()->assertJson(['changed' => true]);
        $this->assertContains('TA2', collect($res->json('tasks'))->pluck('title')->all());

        // sem `since` → apenas a linha de base (sem despejar dados)
        $this->getJson('/api/sync')->assertOk()->assertJson(['changed' => false]);

        Carbon::setTestNow();
    }

    public function test_sync_endpoint_reports_no_change_when_nothing_updated(): void
    {
        [$b] = $this->sharedScaffold();
        $this->actingAs($b);

        $res = $this->getJson('/api/sync?since=' . urlencode('2026-06-23 10:05:00'));
        $res->assertOk()->assertJson(['changed' => false]);

        Carbon::setTestNow();
    }
}
