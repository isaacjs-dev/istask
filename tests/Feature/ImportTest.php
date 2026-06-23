<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Note;
use App\Models\Notebook;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_tasks_creates_tasks_with_fields_and_checklist(): void
    {
        $user = User::factory()->create();
        Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);
        $proj = Project::create(['user_id' => $user->id, 'slug' => 'sistemas', 'name' => 'Sistemas']);
        $label = Label::create(['user_id' => $user->id, 'name' => 'Importante']);
        $this->actingAs($user);

        $res = $this->postJson('/api/tasks/import', ['tasks' => [
            [
                'title' => 'Configurar servidor', 'description' => '<p>Detalhes</p>',
                'status' => 'andamento', 'priority' => 'alta', 'project' => 'sistemas',
                'section' => 'prioridade', 'due' => '2026-07-01', 'responsible' => 'Fulano Externo',
                'labelIds' => [$label->id],
                'checklist' => [['text' => 'Subtarefa', 'done' => true]],
            ],
            ['title' => 'Tarefa simples'],
        ]]);

        $res->assertOk();
        $this->assertCount(2, $res->json('results'));
        $this->assertTrue($res->json('results.0.ok'));
        $this->assertTrue($res->json('results.1.ok'));
        $this->assertGreaterThanOrEqual(2, count($res->json('tasks')));

        $t = Task::where('title', 'Configurar servidor')->first();
        $this->assertNotNull($t);
        $this->assertSame($proj->id, $t->project_id);
        $this->assertSame('andamento', $t->status);
        $this->assertSame('alta', $t->priority);
        $this->assertSame('prioridade', $t->section);
        $this->assertSame('Fulano Externo', $t->responsible);
        $this->assertTrue($t->labels->contains($label->id));
        $this->assertSame(1, $t->steps()->count());
        $this->assertSame('done', $t->steps()->first()->status);

        // sem projeto -> projeto padrão (geral)
        $simple = Task::where('title', 'Tarefa simples')->first();
        $this->assertSame('geral', $simple->project->slug);
        $this->assertSame('pendente', $simple->status);
    }

    public function test_import_tasks_requires_title(): void
    {
        $user = User::factory()->create();
        Project::create(['user_id' => $user->id, 'slug' => 'geral', 'name' => 'Geral']);
        $this->actingAs($user);

        $this->postJson('/api/tasks/import', ['tasks' => [['description' => 'sem título']]])
            ->assertStatus(422);
    }

    public function test_import_notes_creates_text_and_checklist_notes(): void
    {
        $user = User::factory()->create();
        $ws = Workspace::create(['owner_id' => $user->id, 'name' => 'Pessoal', 'position' => 0]);
        $nb = Notebook::create(['workspace_id' => $ws->id, 'name' => 'Caderno', 'position' => 0]);
        $label = Label::create(['user_id' => $user->id, 'name' => 'Ideia']);
        $this->actingAs($user);

        $res = $this->postJson('/api/notes/import', ['notes' => [
            ['title' => 'Nota texto', 'body' => '<p><strong>Oi</strong></p>', 'type' => 'text', 'notebook_id' => $nb->id, 'labelIds' => [$label->id]],
            ['title' => 'Lista', 'type' => 'checklist', 'notebook_id' => $nb->id, 'items' => [['text' => 'A', 'done' => false], ['text' => 'B', 'done' => true]]],
        ]]);

        $res->assertOk();
        $this->assertCount(2, $res->json('results'));
        $this->assertTrue($res->json('results.0.ok'));
        $this->assertTrue($res->json('results.1.ok'));

        $text = Note::where('title', 'Nota texto')->first();
        $this->assertSame('text', $text->type);
        $this->assertSame($nb->id, $text->notebook_id);
        $this->assertTrue($text->labels->contains($label->id));

        $list = Note::where('title', 'Lista')->first();
        $this->assertSame('checklist', $list->type);
        $this->assertSame(2, $list->items()->count());
        $this->assertSame('B', $list->items()->where('done', true)->first()->text);
    }
}
