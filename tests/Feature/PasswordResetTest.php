<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_renders(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Esqueci minha senha');
    }

    public function test_request_sends_reset_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $res = $this->post('/forgot-password', ['email' => $user->email]);
        $res->assertRedirect();
        $res->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_request_is_neutral_for_unknown_email(): void
    {
        Notification::fake();
        $res = $this->post('/forgot-password', ['email' => 'naoexiste@example.com']);
        $res->assertRedirect();
        $res->assertSessionHas('status');       // mensagem neutra (não revela existência)
        $res->assertSessionHasNoErrors();
        Notification::assertNothingSent();
    }

    public function test_reset_updates_password_and_allows_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('antiga123')]);
        $token = Password::createToken($user);

        $res = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ]);
        $res->assertRedirect('/login');
        $res->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('novaSenha123', $user->password));

        // login com a nova senha funciona
        $this->post('/login', ['email' => $user->email, 'password' => 'novaSenha123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_reset_with_invalid_token_fails(): void
    {
        $user = User::factory()->create(['password' => Hash::make('antiga123')]);

        $res = $this->from('/reset-password/tok')->post('/reset-password', [
            'token' => 'token-invalido',
            'email' => $user->email,
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ]);
        $res->assertSessionHasErrors('email');

        $user->refresh();
        $this->assertTrue(Hash::check('antiga123', $user->password)); // senha intacta
    }
}
