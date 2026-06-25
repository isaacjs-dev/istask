<?php

namespace App\Http\Controllers\Auth;

use App\Actions\ProvisionWorkspace;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Login com Google (OAuth via Socialite). Funciona apenas quando GOOGLE_CLIENT_ID
 * estiver configurado no .env; caso contrário, devolve ao login com aviso.
 */
class GoogleController extends Controller
{
    private function configured(): bool
    {
        return ! empty(config('services.google.client_id')) && ! empty(config('services.google.client_secret'));
    }

    /** Envia o usuário para a tela de consentimento do Google. */
    public function redirect()
    {
        if (! $this->configured()) {
            return redirect()->route('login')->withErrors(['email' => 'Login com Google ainda não está configurado.']);
        }

        return Socialite::driver('google')->redirect();
    }

    /** Retorno do Google: acha/cria o usuário, provisiona a área e autentica. */
    public function callback(ProvisionWorkspace $provision)
    {
        if (! $this->configured()) {
            return redirect()->route('login')->withErrors(['email' => 'Login com Google ainda não está configurado.']);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            return redirect()->route('login')->withErrors(['email' => 'Não foi possível autenticar com o Google. Tente novamente.']);
        }

        $email = $googleUser->getEmail();
        if (! $email) {
            return redirect()->route('login')->withErrors(['email' => 'Sua conta Google não tem um e-mail acessível.']);
        }

        // Acha por google_id; senão por e-mail (vincula a conta existente).
        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $email)->first();

        $isNew = $user === null;
        if ($isNew) {
            $user = User::create([
                'name'      => $googleUser->getName() ?: Str::before($email, '@'),
                'email'     => $email,
                'google_id' => $googleUser->getId(),
                'password'  => Hash::make(Str::random(40)), // conta só-Google: senha aleatória
            ]);
            $provision->for($user); // área "Pessoal" + projetos + boas-vindas
        } elseif (! $user->google_id) {
            $user->forceFill(['google_id' => $googleUser->getId()])->save();
        }

        Auth::login($user, remember: true);
        $request = request();
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
