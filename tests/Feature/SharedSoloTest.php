<?php

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TaskRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Garante o flag `sharedSolo`, que alimenta as áreas/cadernos virtuais
 * "Projetos compartilhados" / "Notas compartilhadas" no front.
 */
class SharedSoloTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_shared_project_is_flagged_for_recipient_until_workspace_is_shared(): void
    {
        $owner = User::factory()->create();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Área A']);
        $project = Project::create(['user_id' => $owner->id, 'workspace_id' => $ws->id, 'slug' => 'proj-x', 'name' => 'Projeto X']);

        $member = User::factory()->create();
        $project->members()->attach($member->id, ['permission' => 'edit', 'invited_by' => $owner->id]);
        $this->actingAs($member);

        $repo = app(TaskRepository::class);
        $p = collect($repo->projectsPayload($member))->firstWhere('id', $project->id);
        $this->assertNotNull($p);
        $this->assertTrue($p['sharedSolo'], 'projeto avulso deve ser sharedSolo quando a área não é acessível');

        // Compartilhar a área inteira → o projeto deixa de ser avulso.
        $ws->members()->attach($member->id, ['permission' => 'view', 'invited_by' => $owner->id]);
        $p2 = collect($repo->projectsPayload($member))->firstWhere('id', $project->id);
        $this->assertFalse($p2['sharedSolo']);
    }

    public function test_solo_shared_note_is_flagged_for_recipient_until_notebook_is_shared(): void
    {
        $owner = User::factory()->create();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Área A']);
        $nb = Notebook::create(['workspace_id' => $ws->id, 'name' => 'Caderno A']);
        $note = $owner->notes()->create(['notebook_id' => $nb->id, 'title' => 'Nota X', 'body' => '']);

        $member = User::factory()->create();
        $note->collaborators()->attach($member->id, ['permission' => 'view', 'invited_by' => $owner->id]);
        $this->actingAs($member);

        $repo = app(TaskRepository::class);
        $n = collect($repo->notesPayload($member))->firstWhere('id', (string) $note->id);
        $this->assertNotNull($n);
        $this->assertTrue($n['sharedSolo'], 'nota avulsa deve ser sharedSolo quando o caderno não é acessível');

        // Compartilhar o caderno → a nota deixa de ser avulsa.
        $nb->members()->attach($member->id, ['permission' => 'view', 'invited_by' => $owner->id]);
        $n2 = collect($repo->notesPayload($member))->firstWhere('id', (string) $note->id);
        $this->assertFalse($n2['sharedSolo']);
    }
}
