<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCreateIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function seedUser(): User
    {
        $user = User::factory()->create();
        $ws = Workspace::create(['owner_id' => $user->id, 'name' => 'Pessoal', 'position' => 0]);
        Project::create(['user_id' => $user->id, 'workspace_id' => $ws->id, 'slug' => 'geral', 'name' => 'Geral']);

        return $user;
    }

    public function test_same_client_token_creates_only_one_task(): void
    {
        $user = $this->seedUser();
        $this->actingAs($user);
        $token = '11111111-1111-4111-8111-111111111111';

        $a = $this->postJson('/api/tasks', ['client_token' => $token, 'title' => 'Comprar café']);
        $a->assertCreated();

        // reenvio do MESMO rascunho (duplo clique / retry) — não duplica
        $b = $this->postJson('/api/tasks', ['client_token' => $token, 'title' => 'Comprar café']);
        $b->assertOk(); // 200, não 201
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame(1, Task::where('title', 'Comprar café')->count());
    }

    public function test_full_payload_is_persisted_on_create(): void
    {
        $user = $this->seedUser();
        $this->actingAs($user);

        $res = $this->postJson('/api/tasks', [
            'client_token' => '22222222-2222-4222-8222-222222222222',
            'title'        => 'Tarefa completa',
            'description'  => '<p>detalhe</p>',
            'status'       => 'andamento',
            'priority'     => 'alta',
            'project'      => 'geral',
            'checklist'    => [['text' => 'passo 1', 'done' => false]],
        ]);
        $res->assertCreated();
        $task = Task::find($res->json('id'));
        $this->assertSame('Tarefa completa', $task->title);
        $this->assertSame('andamento', $task->status);
        $this->assertSame('alta', $task->priority);
        $this->assertSame(1, $task->steps()->count());
    }

    public function test_token_is_scoped_no_global_lock_between_users(): void
    {
        $a = $this->seedUser();
        $b = $this->seedUser();
        $token = '33333333-3333-4333-8333-333333333333';

        // mesmo token (improvável na prática, mas garante ausência de bloqueio global):
        // o 1º usuário cria; o 2º não deve receber a tarefa alheia nem ser bloqueado.
        $this->actingAs($a)->postJson('/api/tasks', ['client_token' => $token, 'title' => 'De A'])->assertCreated();
        $resB = $this->actingAs($b)->postJson('/api/tasks', ['client_token' => $token, 'title' => 'De B']);
        $resB->assertSuccessful();
        // B não enxerga a tarefa de A (sem permissão) → cria a própria
        $this->assertNotSame('De A', Task::find($resB->json('id'))->title);
    }
}
