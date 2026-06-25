<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Habilita o fluxo (no .env de teste as chaves podem estar vazias).
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);
    }

    private function mockGoogleUser(string $id, string $email, string $name): void
    {
        $googleUser = Mockery::mock('Laravel\Socialite\Two\User');
        $googleUser->shouldReceive('getId')->andReturn($id);
        $googleUser->shouldReceive('getEmail')->andReturn($email);
        $googleUser->shouldReceive('getName')->andReturn($name);
        Socialite::shouldReceive('driver->user')->andReturn($googleUser);
    }

    public function test_redirect_is_disabled_without_config(): void
    {
        config(['services.google.client_id' => null]);
        $this->get('/auth/google/redirect')->assertRedirect(route('login'));
    }

    public function test_callback_creates_logs_in_and_provisions_new_user(): void
    {
        $this->mockGoogleUser('google-123', 'novo@gmail.com', 'Novo Usuário');

        $res = $this->get('/auth/google/callback');
        $res->assertRedirect('/');

        $user = User::where('email', 'novo@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('google-123', $user->google_id);
        $this->assertAuthenticatedAs($user);
        // provisionou a área "Pessoal"
        $this->assertSame(1, Workspace::where('owner_id', $user->id)->count());
    }

    public function test_callback_links_existing_email_account(): void
    {
        $existing = User::factory()->create(['email' => 'ja@existe.com', 'google_id' => null]);
        $this->mockGoogleUser('google-999', 'ja@existe.com', 'Já Existe');

        $res = $this->get('/auth/google/callback');
        $res->assertRedirect('/');

        $existing->refresh();
        $this->assertSame('google-999', $existing->google_id);
        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'ja@existe.com')->count()); // não duplica
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
