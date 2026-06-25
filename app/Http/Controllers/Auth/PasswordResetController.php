<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Recuperação de senha (fluxo nativo do Laravel: broker "passwords.users" +
 * tabela password_reset_tokens + notificação ResetPassword). Mensagens em pt-BR.
 */
class PasswordResetController extends Controller
{
    /** Mensagens amigáveis (pt-BR) para os status do broker, sem depender do locale. */
    private function msg(string $status): string
    {
        return [
            Password::RESET_LINK_SENT => 'Enviamos um link de redefinição para o seu e-mail.',
            Password::INVALID_USER    => 'Não encontramos uma conta com esse e-mail.',
            Password::PASSWORD_RESET  => 'Senha redefinida com sucesso. Entre com a nova senha.',
            Password::INVALID_TOKEN   => 'O link de redefinição é inválido ou expirou.',
            Password::RESET_THROTTLED => 'Aguarde um momento antes de solicitar outro link.',
        ][$status] ?? __($status);
    }

    /** Etapa 1 — formulário "esqueci minha senha". */
    public function showLinkRequest()
    {
        return view('auth.forgot-password');
    }

    /** Etapa 1 — envia o link de redefinição. Não revela se o e-mail existe (anti-enumeração). */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        // Sempre devolve uma mensagem neutra de sucesso (não vaza existência da conta).
        if ($status === Password::RESET_LINK_SENT || $status === Password::INVALID_USER) {
            return back()->with('status', 'Se houver uma conta com esse e-mail, enviamos um link de redefinição.');
        }

        return back()->withErrors(['email' => $this->msg($status)])->onlyInput('email');
    }

    /** Etapa 2 — formulário de nova senha (link do e-mail). */
    public function showReset(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /** Etapa 2 — efetiva a redefinição. */
    public function reset(Request $request)
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(6)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])
                    ->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', $this->msg($status));
        }

        return back()->withErrors(['email' => $this->msg($status)])->onlyInput('email');
    }
}
