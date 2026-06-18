<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\DiaryEntry;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Diary\DiaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiaryActivityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Project} */
    private function userWithProject(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);

        return [$user, $project];
    }

    private function task(Project $project, string $title = 'Desenvolver tela de cadastro'): Task
    {
        return Task::create(['project_id' => $project->id, 'title' => $title, 'status' => 'pendente']);
    }

    public function test_moving_to_andamento_opens_a_single_open_period(): void
    {
        [$user, $project] = $this->userWithProject();
        $this->actingAs($user);
        $task = $this->task($project);

        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'andamento'])->assertOk();

        $entries = DiaryEntry::where('task_id', $task->id)->get();
        $this->assertCount(1, $entries);
        $this->assertTrue($entries->first()->isOpen());
        $this->assertSame('andamento', $entries->first()->status_to);
        $this->assertSame('auto', $entries->first()->source);
        $this->assertSame('D', $entries->first()->activity_type); // "Desenvolver tela" -> D
    }

    public function test_repeated_move_to_andamento_is_idempotent(): void
    {
        [$user, $project] = $this->userWithProject();
        $this->actingAs($user);
        $task = $this->task($project);

        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'andamento'])->assertOk();
        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'andamento'])->assertOk(); // refresh/repeat

        $this->assertSame(1, DiaryEntry::where('task_id', $task->id)->count());
        $this->assertSame(1, DiaryEntry::where('task_id', $task->id)->whereNull('ended_at')->count());
    }

    public function test_moving_out_of_andamento_closes_the_period(): void
    {
        [$user, $project] = $this->userWithProject();
        $this->actingAs($user);
        $task = $this->task($project);

        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'andamento'])->assertOk();
        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'aguardando'])->assertOk();

        $entries = DiaryEntry::where('task_id', $task->id)->get();
        $this->assertCount(1, $entries);
        $this->assertFalse($entries->first()->isOpen());
        $this->assertSame('aguardando', $entries->first()->status_to);
    }

    public function test_completing_closes_open_period_and_leaves_none_open(): void
    {
        [$user, $project] = $this->userWithProject();
        $this->actingAs($user);
        $task = $this->task($project);

        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'andamento'])->assertOk();
        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'concluido'])->assertOk();

        $this->assertSame(1, DiaryEntry::where('task_id', $task->id)->count());
        $this->assertSame(0, DiaryEntry::where('task_id', $task->id)->whereNull('ended_at')->count());
        $this->assertSame('concluido', DiaryEntry::where('task_id', $task->id)->first()->status_to);
    }

    public function test_reopening_creates_new_cycle_preserving_history(): void
    {
        [$user, $project] = $this->userWithProject();
        $this->actingAs($user);
        $task = $this->task($project);

        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'andamento'])->assertOk();
        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'concluido'])->assertOk();
        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'andamento'])->assertOk(); // reabre

        $entries = DiaryEntry::where('task_id', $task->id)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertFalse($entries[0]->isOpen());      // ciclo anterior preservado
        $this->assertTrue($entries[1]->isOpen());        // novo ciclo aberto
    }

    public function test_reconcile_splits_open_entry_across_days_and_is_idempotent(): void
    {
        [$user, $project] = $this->userWithProject();
        $task = Task::create(['project_id' => $project->id, 'title' => 'Migração contínua', 'status' => 'andamento']);

        $entry = $user->diaryEntries()->create([
            'task_id'    => $task->id,
            'project_id' => $project->id,
            'source'     => 'auto',
            'status_to'  => 'andamento',
            'started_at' => Carbon::yesterday()->setTime(14, 0),
        ]);

        $service = app(DiaryService::class);
        $service->reconcile($user, Carbon::today()->setTime(12, 0));

        $entry->refresh();
        $this->assertNotNull($entry->ended_at);
        $this->assertSame(Carbon::yesterday()->setTime(18, 0)->toDateTimeString(), $entry->ended_at->toDateTimeString());

        $split = DiaryEntry::where('task_id', $task->id)->where('source', 'auto_split')->get();
        $this->assertCount(1, $split);
        $this->assertTrue($split->first()->isOpen());
        $this->assertTrue($split->first()->started_at->isSameDay(Carbon::today()));

        // idempotência: segunda passagem não cria nada novo
        $service->reconcile($user, Carbon::today()->setTime(12, 5));
        $this->assertSame(1, DiaryEntry::where('task_id', $task->id)->where('source', 'auto_split')->count());
    }

    public function test_attachment_upload_to_task_and_import_into_diary(): void
    {
        Storage::fake('public');
        [$user, $project] = $this->userWithProject();
        $this->actingAs($user);
        $task = $this->task($project);

        $res = $this->postJson('/api/attachments', [
            'attachable_type' => 'task',
            'attachable_id'   => $task->id,
            'file'            => UploadedFile::fake()->image('diagrama.png'),
        ]);
        $res->assertCreated();
        $taskAttId = (int) $res->json('attachment.id');
        $this->assertDatabaseHas('attachments', ['id' => $taskAttId, 'origin' => 'own', 'attachable_id' => $task->id]);
        $taskAtt = Attachment::find($taskAttId);
        Storage::disk('public')->assertExists($taskAtt->path);

        $entry = $user->diaryEntries()->create(['task_id' => $task->id, 'started_at' => now()]);

        $res = $this->postJson("/api/diary/{$entry->id}/attachments/import", ['attachment_ids' => [$taskAttId]]);
        $res->assertCreated();
        $imported = collect($res->json('attachments'))->first();
        $this->assertSame('task', $imported['origin']);
        $this->assertSame((string) $taskAttId, $imported['sourceId']);

        // o anexo importado é cópia própria; o original da tarefa permanece intacto
        $this->assertSame(2, Attachment::count());
        $importedModel = Attachment::find((int) $imported['id']);
        $this->assertNotSame($taskAtt->path, $importedModel->path);

        $this->deleteJson("/api/attachments/{$importedModel->id}")->assertOk();
        Storage::disk('public')->assertExists($taskAtt->path); // original preservado
    }

    public function test_manual_complement_keeps_movement_fields_and_logs_time_adjust(): void
    {
        [$user, $project] = $this->userWithProject();
        $this->actingAs($user);
        $task = $this->task($project);

        $this->postJson("/api/tasks/{$task->id}/move", ['status' => 'andamento'])->assertOk();
        $entry = DiaryEntry::where('task_id', $task->id)->first();

        $res = $this->patchJson("/api/diary/{$entry->id}", [
            'observations' => 'Bloqueado por dependência externa',
            'progress'     => 40,
            'started_at'   => now()->subHours(2)->toIso8601String(),
        ]);
        $res->assertOk();

        $this->assertSame('andamento', $res->json('entry.statusTo')); // movimentação preservada
        $this->assertSame('auto', $res->json('entry.source'));
        $this->assertSame('Bloqueado por dependência externa', $res->json('entry.observations'));
        $this->assertSame(40, $res->json('entry.progress'));
        $actions = collect($res->json('entry.history'))->pluck('action');
        $this->assertTrue($actions->contains('time_adjusted'));
    }

    public function test_update_rejects_end_before_start(): void
    {
        [$user, $project] = $this->userWithProject();
        $this->actingAs($user);
        $entry = $user->diaryEntries()->create(['started_at' => now(), 'source' => 'manual']);

        $this->patchJson("/api/diary/{$entry->id}", [
            'started_at' => now()->toIso8601String(),
            'ended_at'   => now()->subHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_workday_preferences_persist_and_appear_in_bootstrap(): void
    {
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        $this->putJson('/api/preferences', ['workdayStart' => '08:30', 'workdayEnd' => '17:30'])
            ->assertOk()
            ->assertJsonPath('prefs.workdayStart', '08:30')
            ->assertJsonPath('prefs.workdayEnd', '17:30');

        $this->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonPath('prefs.workdayStart', '08:30');
    }
}
