<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_public_landing_at_root(): void
    {
        $res = $this->get('/');
        $res->assertOk();
        $res->assertSee('Começar grátis');                 // marcador da landing
        $res->assertDontSee('window.__BOOT__', false);      // não é o shell do app
    }

    public function test_authenticated_user_sees_app_shell_at_root(): void
    {
        $this->actingAs(User::factory()->create());
        $res = $this->get('/');
        $res->assertOk();
        $res->assertSee('window.__BOOT__', false);          // shell do SPA
    }

    public function test_guest_can_view_login_page(): void
    {
        $this->get('/login')->assertOk()->assertSee('Bem-vindo de volta');
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/login')->assertRedirect('/');
    }

    public function test_login_succeeds_and_redirects_to_root(): void
    {
        $user = User::factory()->create(['password' => Hash::make('segredo123')]);
        $res = $this->post('/login', ['email' => $user->email, 'password' => 'segredo123']);
        $res->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('segredo123')]);
        $res = $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'errado']);
        $res->assertRedirect('/login');
        $res->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_redirects_to_landing_and_clears_session(): void
    {
        $this->actingAs(User::factory()->create());
        $res = $this->post('/logout');
        $res->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_token_mismatch_redirects_web_to_login_instead_of_419_page(): void
    {
        Route::middleware('web')->get('/__throw_419', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        $res = $this->get('/__throw_419');
        $res->assertRedirect(route('login'));
        $res->assertSessionHasErrors('email'); // aviso "Sua sessão expirou"
    }

    public function test_token_mismatch_returns_419_json_for_api_requests(): void
    {
        Route::middleware('web')->get('/__throw_419_json', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        $this->getJson('/__throw_419_json')->assertStatus(419);
    }
}
