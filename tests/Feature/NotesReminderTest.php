<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\User;
use App\Notifications\NoteReminderDue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4 do módulo de Notas (Lembretes): definir/editar/remover lembrete com
 * recorrência, listar, e disparo on-demand via polling (/reminders/due) com
 * registro em notifications e avanço de recorrência.
 */
class NotesReminderTest extends TestCase
{
    use RefreshDatabase;

    private function note(User $user, array $attrs = []): Note
    {
        return Note::create(array_merge(['user_id' => $user->id, 'body' => 'corpo'], $attrs));
    }

    public function test_set_reminder_with_recurrence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user);
        $when = now()->addDay()->toIso8601String();

        $res = $this->postJson("/api/notes/{$note->id}/reminder", ['remind_at' => $when, 'remind_recurrence' => 'weekly']);
        $res->assertOk();
        $this->assertNotNull($res->json('note.remindAt'));
        $this->assertSame('weekly', $res->json('note.remindRecurrence'));
        $this->assertNull($note->fresh()->remind_last_fired_at);
    }

    public function test_recurrence_without_remind_at_is_dropped(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user);

        $res = $this->postJson("/api/notes/{$note->id}/reminder", ['remind_at' => null, 'remind_recurrence' => 'daily']);
        $res->assertOk();
        $this->assertNull($res->json('note.remindAt'));
        $this->assertNull($res->json('note.remindRecurrence'));
    }

    public function test_remove_reminder(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['remind_at' => now()->addDay(), 'remind_recurrence' => 'daily']);

        $res = $this->postJson("/api/notes/{$note->id}/reminder", ['remind_at' => null]);
        $res->assertOk();
        $this->assertNull($res->json('note.remindAt'));
        $this->assertNull($note->fresh()->remind_at);
    }

    public function test_reminder_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user);

        $this->postJson("/api/notes/{$note->id}/reminder", ['remind_at' => 'not-a-date'])->assertStatus(422);
        $this->postJson("/api/notes/{$note->id}/reminder", ['remind_at' => now()->toIso8601String(), 'remind_recurrence' => 'hourly'])->assertStatus(422);
    }

    public function test_reminders_list_only_includes_notes_with_reminder(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $a = $this->note($user, ['title' => 'Com', 'remind_at' => now()->addDays(2)]);
        $this->note($user, ['title' => 'Sem']);
        $this->note($user, ['title' => 'Arquivada', 'remind_at' => now()->addDay(), 'archived_at' => now()]);

        $res = $this->getJson('/api/notes/reminders');
        $res->assertOk();
        $this->assertCount(1, $res->json('notes'));
        $this->assertSame((string) $a->id, $res->json('notes.0.id'));
    }

    public function test_due_fires_once_and_records_notification(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['title' => 'Vencido', 'remind_at' => now()->subHour()]);

        $res = $this->getJson('/api/notes/reminders/due');
        $res->assertOk();
        $this->assertCount(1, $res->json('fired'));
        $this->assertSame((string) $note->id, $res->json('fired.0.id'));
        $this->assertNotNull($note->fresh()->remind_last_fired_at);
        $this->assertDatabaseHas('notifications', [
            'type' => NoteReminderDue::class,
            'notifiable_id' => $user->id,
            'notifiable_type' => User::class,
        ]);

        // segundo polling: não dispara de novo (one-time)
        $this->assertCount(0, $this->getJson('/api/notes/reminders/due')->json('fired'));
    }

    public function test_future_reminder_does_not_fire(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->note($user, ['remind_at' => now()->addHour()]);

        $this->assertCount(0, $this->getJson('/api/notes/reminders/due')->json('fired'));
    }

    public function test_recurring_reminder_advances_to_future(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $note = $this->note($user, ['remind_at' => now()->subDays(2), 'remind_recurrence' => 'daily']);

        $res = $this->getJson('/api/notes/reminders/due');
        $this->assertCount(1, $res->json('fired'));
        $fresh = $note->fresh();
        $this->assertTrue($fresh->remind_at->isFuture(), 'remind_at deve avançar para o futuro');
        $this->assertNotNull($fresh->remind_last_fired_at);

        // logo após o disparo, ainda não vence de novo
        $this->assertCount(0, $this->getJson('/api/notes/reminders/due')->json('fired'));
    }

    public function test_non_owner_cannot_set_reminder(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $note = $this->note($owner);

        $this->actingAs($intruder);
        $this->postJson("/api/notes/{$note->id}/reminder", ['remind_at' => now()->addDay()->toIso8601String()])->assertForbidden();
    }

    public function test_shell_wires_reminder_module(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->get('/')->assertOk()->assertSee('app/notes-reminder.js');
    }
}
