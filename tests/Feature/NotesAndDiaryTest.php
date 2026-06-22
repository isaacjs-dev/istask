<?php

namespace Tests\Feature;

use App\Models\DiaryEntry;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Commands\ActionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotesAndDiaryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Project} */
    private function userWithProject(): array
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);

        return [$user, $project];
    }

    public function test_note_crud_via_api_with_soft_delete_and_undo(): void
    {
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        $res = $this->postJson('/api/notes', ['title' => 'Elotech', 'body' => 'login antigo', 'tags' => 'sistemas']);
        $res->assertCreated();
        $noteId = (int) $res->json('note.id');
        $this->assertDatabaseHas('notes', ['id' => $noteId, 'title' => 'Elotech', 'body' => 'login antigo']);

        $res = $this->patchJson("/api/notes/{$noteId}", ['body' => 'login novo']);
        $res->assertOk();
        $this->assertSame('login novo', $res->json('note.body'));
        $this->assertDatabaseHas('notes', ['id' => $noteId, 'body' => 'login novo']);

        $res = $this->deleteJson("/api/notes/{$noteId}");
        $res->assertOk();
        $this->assertTrue($res->json('deleted'));
        $this->assertSoftDeleted('notes', ['id' => $noteId]);

        $recorder = app(ActionRecorder::class);

        $recorder->undo($user); // desfaz exclusão -> nota volta com body "login novo"
        $this->assertDatabaseHas('notes', ['id' => $noteId, 'body' => 'login novo', 'deleted_at' => null]);

        $recorder->undo($user); // desfaz edição -> body volta a "login antigo"
        $this->assertDatabaseHas('notes', ['id' => $noteId, 'body' => 'login antigo']);

        $recorder->undo($user); // desfaz criação -> soft delete novamente
        $this->assertSoftDeleted('notes', ['id' => $noteId]);
    }

    public function test_diary_entry_crud_via_api_with_soft_delete_and_undo(): void
    {
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        $res = $this->postJson('/api/diary', ['description' => 'Reunião com cliente']);
        $res->assertCreated();
        $entryId = (int) $res->json('entry.id');
        $this->assertTrue($res->json('entry.open'));
        $this->assertDatabaseHas('diary_entries', ['id' => $entryId, 'description' => 'Reunião com cliente']);

        $startedAt = DiaryEntry::find($entryId)->started_at->toIso8601String();

        $res = $this->patchJson("/api/diary/{$entryId}", ['description' => 'Reunião encerrada']);
        $res->assertOk();
        $this->assertSame('Reunião encerrada', $res->json('entry.description'));

        $res = $this->patchJson("/api/diary/{$entryId}", [
            'description' => 'Reunião encerrada',
            'started_at'  => $startedAt,
            'ended_at'    => now()->toIso8601String(),
        ]);
        $res->assertOk();
        $this->assertFalse($res->json('entry.open'));

        $res = $this->deleteJson("/api/diary/{$entryId}");
        $res->assertOk();
        $this->assertSoftDeleted('diary_entries', ['id' => $entryId]);

        $recorder = app(ActionRecorder::class);

        $recorder->undo($user); // desfaz exclusão -> volta encerrada
        $this->assertDatabaseHas('diary_entries', ['id' => $entryId, 'deleted_at' => null]);
        $this->assertNotNull(DiaryEntry::find($entryId)->ended_at);

        $recorder->undo($user); // desfaz encerramento -> volta aberta
        $this->assertNull(DiaryEntry::find($entryId)->ended_at);

        $recorder->undo($user); // desfaz edição de descrição -> texto original
        $this->assertSame('Reunião com cliente', DiaryEntry::find($entryId)->description);

        $recorder->undo($user); // desfaz criação -> soft delete novamente
        $this->assertSoftDeleted('diary_entries', ['id' => $entryId]);
    }

    public function test_completing_task_creates_closed_diary_entry(): void
    {
        [$user, $project] = $this->userWithProject();
        $this->actingAs($user);

        $task = Task::create(['project_id' => $project->id, 'title' => 'Enviar relatório mensal']);

        $res = $this->postJson("/api/tasks/{$task->id}/toggle");
        $res->assertOk();
        $this->assertSame('concluido', $task->fresh()->status);

        $entries = DiaryEntry::where('task_id', $task->id)->get();
        $this->assertCount(1, $entries);
        $this->assertNotNull($entries->first()->ended_at);
        $this->assertSame('Enviar relatório mensal', $entries->first()->description);
    }

    public function test_completing_task_closes_existing_open_diary_entry_instead_of_duplicating(): void
    {
        // Congela o tempo dentro do expediente para o teste não depender da hora real:
        // fora do expediente, o DiaryService divide o período em aberto (comportamento correto),
        // o que tornava este teste instável quando rodado após as 18h.
        \Illuminate\Support\Carbon::setTestNow('2026-06-15 14:00:00');
        try {
            [$user, $project] = $this->userWithProject();
            $this->actingAs($user);

            $task = Task::create(['project_id' => $project->id, 'title' => 'Levantamento de requisitos']);
            $open = $user->diaryEntries()->create([
                'task_id'     => $task->id,
                'started_at'  => now()->subHour(),
                'description' => 'Levantamento de requisitos',
            ]);

            $res = $this->postJson("/api/tasks/{$task->id}/toggle");
            $res->assertOk();

            $entries = DiaryEntry::where('task_id', $task->id)->get();
            $this->assertCount(1, $entries);
            $this->assertSame($open->id, $entries->first()->id);
            $this->assertNotNull($entries->first()->ended_at);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_chat_update_note_command_produces_diff_and_undo(): void
    {
        [$user] = $this->userWithProject();
        $this->actingAs($user);

        $note = Note::create(['user_id' => $user->id, 'title' => 'Elotech', 'body' => 'login antigo', 'tags' => 'sistemas']);

        $res = $this->postJson('/api/ai/command', ['text' => 'muda a nota sobre Elotech para admin/senha novo123']);
        $res->assertOk();
        $this->assertTrue($res->json('changed'));
        $this->assertNotNull($res->json('notes'));
        $this->assertTrue($res->json('echo.canUndo'));
        $this->assertStringContainsString('Conteúdo', $res->json('echo.summary'));
        $this->assertStringContainsString('→', $res->json('echo.summary'));
        $this->assertStringContainsString('Nota atualizada', $res->json('aiMessage.text'));
        $this->assertSame('admin/senha novo123', $note->fresh()->body);

        $returned = collect($res->json('notes'))->firstWhere('id', (string) $note->id);
        $this->assertSame('admin/senha novo123', $returned['body']);

        $res = $this->postJson('/api/ai/command', ['text' => 'desfazer']);
        $res->assertOk();
        $this->assertStringContainsString('Desfeito', $res->json('aiMessage.text'));
        $this->assertSame('login antigo', $note->fresh()->body);
    }
}
