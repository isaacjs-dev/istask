<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Notebook;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TaskRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Garante a cascata de compartilhamento (Área→Projetos/Tarefas + Cadernos/Notas)
 * e o isolamento: nada fora do escopo compartilhado pode vazar para outro usuário.
 */
class SharingIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** Cria o conteúdo do dono A: WA(PA/TA, NA/NoteA, NoteA2) + WA2(PA2/TA2). */
    private function seedOwner(): array
    {
        $a = User::factory()->create();
        $wa = Workspace::create(['owner_id' => $a->id, 'name' => 'WA', 'position' => 0]);
        $pa = Project::create(['user_id' => $a->id, 'workspace_id' => $wa->id, 'slug' => 'pa', 'name' => 'PA']);
        $ta = $pa->tasks()->create(['title' => 'TA', 'status' => 'pendente', 'priority' => 'media', 'position' => 0]);
        $na = Notebook::create(['workspace_id' => $wa->id, 'name' => 'NA', 'position' => 0]);
        $noteA = $a->notes()->create(['notebook_id' => $na->id, 'title' => 'NoteA', 'type' => 'text', 'body' => '']);
        $noteA2 = $a->notes()->create(['notebook_id' => $na->id, 'title' => 'NoteA2', 'type' => 'text', 'body' => '']);
        $wa2 = Workspace::create(['owner_id' => $a->id, 'name' => 'WA2', 'position' => 1]);
        $pa2 = Project::create(['user_id' => $a->id, 'workspace_id' => $wa2->id, 'slug' => 'pa2', 'name' => 'PA2']);
        $ta2 = $pa2->tasks()->create(['title' => 'TA2', 'status' => 'pendente', 'priority' => 'media', 'position' => 0]);

        return compact('a', 'wa', 'pa', 'ta', 'na', 'noteA', 'noteA2', 'wa2', 'pa2', 'ta2');
    }

    private function repo(): TaskRepository
    {
        return app(TaskRepository::class);
    }
    private function taskTitles(User $u): array { return collect($this->repo()->tasksFor($u))->pluck('title')->all(); }
    private function projSlugs(User $u): array { return collect($this->repo()->projectsPayload($u))->pluck('slug')->all(); }
    private function nbNames(User $u): array { return collect($this->repo()->notebooksPayload($u))->pluck('name')->all(); }
    private function noteTitles(User $u): array { return collect($this->repo()->notesPayload($u))->pluck('title')->all(); }

    public function test_unshared_content_is_fully_isolated(): void
    {
        $this->seedOwner();
        $b = User::factory()->create();

        $this->assertNotContains('TA', $this->taskTitles($b));
        $this->assertNotContains('pa', $this->projSlugs($b));
        $this->assertNotContains('NA', $this->nbNames($b));
        $this->assertNotContains('NoteA', $this->noteTitles($b));
    }

    public function test_sharing_workspace_cascades_to_projects_tasks_notebooks_notes(): void
    {
        $s = $this->seedOwner();
        $b = User::factory()->create();
        $s['wa']->members()->attach($b->id, ['permission' => 'edit', 'invited_by' => $s['a']->id]);

        // vê tudo da área compartilhada
        $this->assertContains('TA', $this->taskTitles($b));
        $this->assertContains('pa', $this->projSlugs($b));
        $this->assertContains('NA', $this->nbNames($b));
        $this->assertContains('NoteA', $this->noteTitles($b));
        // NÃO vaza a outra área (WA2)
        $this->assertNotContains('TA2', $this->taskTitles($b));
        $this->assertNotContains('pa2', $this->projSlugs($b));
    }

    public function test_sharing_only_project_shares_its_tasks_not_notebooks(): void
    {
        $s = $this->seedOwner();
        $c = User::factory()->create();
        $s['pa']->members()->attach($c->id, ['permission' => 'edit', 'invited_by' => $s['a']->id]);

        $this->assertContains('pa', $this->projSlugs($c));
        $this->assertContains('TA', $this->taskTitles($c));
        // cadernos/notas da área NÃO vêm junto
        $this->assertNotContains('NA', $this->nbNames($c));
        $this->assertNotContains('NoteA', $this->noteTitles($c));
        // projeto isolado é marcado como sharedSolo ("Projetos compartilhados")
        $row = collect($this->repo()->projectsPayload($c))->firstWhere('slug', 'pa');
        $this->assertTrue((bool) $row['sharedSolo']);
    }

    public function test_sharing_only_notebook_shares_its_notes_not_projects(): void
    {
        $s = $this->seedOwner();
        $d = User::factory()->create();
        $s['na']->members()->attach($d->id, ['permission' => 'edit', 'invited_by' => $s['a']->id]);

        $this->assertContains('NA', $this->nbNames($d));
        $this->assertContains('NoteA', $this->noteTitles($d));
        $this->assertContains('NoteA2', $this->noteTitles($d));
        $this->assertNotContains('pa', $this->projSlugs($d));
        $this->assertNotContains('TA', $this->taskTitles($d));
    }

    public function test_sharing_single_note_does_not_expose_other_notes(): void
    {
        $s = $this->seedOwner();
        $e = User::factory()->create();
        $s['noteA']->collaborators()->attach($e->id, ['permission' => 'view', 'invited_by' => $s['a']->id]);

        $this->assertContains('NoteA', $this->noteTitles($e));
        $this->assertNotContains('NoteA2', $this->noteTitles($e)); // demais notas do caderno não vazam
        $this->assertNotContains('NA', $this->nbNames($e));        // caderno não vem
        $this->assertNotContains('pa', $this->projSlugs($e));
    }

    public function test_view_permission_cannot_edit_shared_task(): void
    {
        $s = $this->seedOwner();
        $b = User::factory()->create();
        $s['wa']->members()->attach($b->id, ['permission' => 'view', 'invited_by' => $s['a']->id]);
        $this->actingAs($b);

        $this->putJson("/api/tasks/{$s['ta']->id}", [
            'title' => 'hack', 'status' => 'pendente', 'priority' => 'media', 'checklist' => [], 'comments' => [],
        ])->assertForbidden();
    }

    public function test_stranger_cannot_access_task(): void
    {
        $s = $this->seedOwner();
        $stranger = User::factory()->create();
        $this->actingAs($stranger);

        $this->putJson("/api/tasks/{$s['ta']->id}", [
            'title' => 'x', 'status' => 'pendente', 'priority' => 'media', 'checklist' => [], 'comments' => [],
        ])->assertNotFound();
    }
}
