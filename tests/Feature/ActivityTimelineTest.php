<?php

namespace Tests\Feature;

use App\Models\DiaryEntry;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_activities_endpoint_lists_task_history_with_start_and_completion(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Escrever relatório', 'status' => 'pendente']);
        $this->actingAs($user);

        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'andamento'])->assertOk();
        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'concluido'])->assertOk();

        $res = $this->getJson('/api/activities')->assertOk();
        $actions = collect($res->json('activities'))->pluck('action');

        $this->assertTrue($actions->contains(fn ($a) => preg_match('/trabalh|andamento/i', $a) === 1), 'falta a atividade de início');
        $this->assertTrue($actions->contains(fn ($a) => preg_match('/conclu|finaliz/i', $a) === 1), 'falta a atividade de conclusão');
        foreach ($res->json('activities') as $a) {
            $this->assertArrayHasKey('taskId', $a);
            $this->assertArrayHasKey('at', $a);
        }
    }

    public function test_diary_entries_appear_in_activities_and_respect_month_filter(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);
        $this->actingAs($user);

        // entrada do diário em maio/2026 e outra em junho/2026
        DiaryEntry::create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Reunião de maio', 'started_at' => '2026-05-10 09:00:00', 'ended_at' => '2026-05-10 10:00:00']);
        DiaryEntry::create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Reunião de junho', 'started_at' => '2026-06-12 14:00:00', 'ended_at' => '2026-06-12 15:30:00']);

        // sem filtro: o diário aparece (kind=diary)
        $all = collect($this->getJson('/api/activities')->assertOk()->json('activities'));
        $diary = $all->where('kind', 'diary');
        $this->assertTrue($diary->isNotEmpty(), 'entradas do diário devem aparecer nas atividades');
        $this->assertTrue($diary->contains(fn ($a) => str_contains((string) $a['taskTitle'], 'junho')));

        // filtro por mês (junho): só a entrada de junho
        $jun = collect($this->getJson('/api/activities?from=2026-06-01T00:00:00&to=2026-06-30T23:59:59')->assertOk()->json('activities'))
            ->where('kind', 'diary')->pluck('taskTitle');
        $this->assertTrue($jun->contains(fn ($t) => str_contains((string) $t, 'junho')));
        $this->assertFalse($jun->contains(fn ($t) => str_contains((string) $t, 'maio')), 'entrada de maio não deve aparecer no filtro de junho');
    }

    public function test_diary_excluded_from_team_view(): void
    {
        $owner = User::factory()->create();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Time']);
        $project = Project::create(['user_id' => $owner->id, 'workspace_id' => $ws->id, 'slug' => 'geral', 'name' => 'Geral']);
        DiaryEntry::create(['user_id' => $owner->id, 'project_id' => $project->id, 'title' => 'Diário pessoal', 'started_at' => now()]);
        $this->actingAs($owner);

        $teamDiary = collect($this->getJson("/api/activities?workspace={$ws->id}")->assertOk()->json('activities'))->where('kind', 'diary');
        $this->assertTrue($teamDiary->isEmpty(), 'o Diário (pessoal) não entra na visão de time');
    }

    public function test_team_activities_require_workspace_access(): void
    {
        $owner = User::factory()->create();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Time A']);
        $project = Project::create(['user_id' => $owner->id, 'workspace_id' => $ws->id, 'slug' => 'geral', 'name' => 'Geral']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Tarefa do time', 'status' => 'pendente']);

        $this->actingAs($owner);
        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'andamento'])->assertOk();

        // Não-membro não pode ver as atividades do time.
        $stranger = User::factory()->create();
        $this->actingAs($stranger);
        $this->getJson("/api/activities?workspace={$ws->id}")->assertStatus(403);

        // Após receber o compartilhamento, passa a ver as atividades de todos na área.
        $ws->members()->attach($stranger->id, ['permission' => 'view', 'invited_by' => $owner->id]);
        $res = $this->getJson("/api/activities?workspace={$ws->id}")->assertOk();
        $this->assertNotEmpty($res->json('activities'));
    }
}
