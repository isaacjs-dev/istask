<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TaskRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Fase2MetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_update_persists_and_exposes_metadata(): void
    {
        $user = User::factory()->create();
        $ws = Workspace::create(['owner_id' => $user->id, 'name' => 'WA', 'position' => 0]);
        $this->actingAs($user);

        $res = $this->patchJson("/api/workspaces/{$ws->id}", [
            'description' => 'Área da equipe', 'startDate' => '2026-06-01', 'endDate' => '2026-12-31', 'status' => 'pausado',
        ]);
        $res->assertOk();

        $ws->refresh();
        $this->assertSame('Área da equipe', $ws->description);
        $this->assertSame('2026-06-01', $ws->start_date->format('Y-m-d'));
        $this->assertSame('pausado', $ws->status);

        $row = collect(app(TaskRepository::class)->workspacesPayload($user))->firstWhere('id', (string) $ws->id);
        $this->assertSame('Área da equipe', $row['description']);
        $this->assertSame('2026-06-01', $row['startDate']);
        $this->assertSame('pausado', $row['status']);
    }

    public function test_project_update_persists_metadata_and_stamps_completion(): void
    {
        $user = User::factory()->create();
        $ws = Workspace::create(['owner_id' => $user->id, 'name' => 'WA', 'position' => 0]);
        $proj = Project::create(['user_id' => $user->id, 'workspace_id' => $ws->id, 'slug' => 'pa', 'name' => 'PA']);
        $this->actingAs($user);

        $res = $this->patchJson("/api/projects/{$proj->id}", [
            'description' => 'Detalhes', 'startDate' => '2026-06-02', 'dueDate' => '2026-07-02',
            'status' => 'concluido', 'priority' => 'alta',
        ]);
        $res->assertOk();

        $proj->refresh();
        $this->assertSame('Detalhes', $proj->description);
        $this->assertSame('2026-07-02', $proj->due_date->format('Y-m-d'));
        $this->assertSame('concluido', $proj->status);
        $this->assertSame('alta', $proj->priority);
        $this->assertNotNull($proj->completed_at); // concluído carimba a data

        $row = collect($res->json('projects'))->firstWhere('slug', 'pa');
        $this->assertSame('concluido', $row['status']);
        $this->assertSame('alta', $row['priority']);
        $this->assertSame('2026-07-02', $row['dueDate']);
    }

    public function test_non_owner_cannot_edit_workspace_metadata(): void
    {
        $owner = User::factory()->create();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'WA', 'position' => 0]);
        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        $this->patchJson("/api/workspaces/{$ws->id}", ['description' => 'x'])->assertNotFound();
    }
}
