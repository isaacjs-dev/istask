<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Nova senha · Tarefas Chat</title>
  <link rel="icon" href="{{ asset('favicon.ico') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('landing.css') }}" />
</head>
<body>
@php($logo = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3z"/><path d="M19 14l.8 2.2L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.8L19 14z"/></svg>')
<div class="auth">
  <div class="auth-split">
    <div class="auth-left">
      <a class="lp-brand" href="{{ route('home') }}"><span class="lp-logo">{!! $logo !!}</span><span class="lp-brand-name">Tarefas Chat</span></a>
      <div class="auth-illus">
        <img src="{{ asset('landing/login-illustration.png') }}" alt="Assistente de IA do Tarefas Chat" />
        <h1>Quase lá.</h1>
        <p>Crie uma nova senha para a sua conta e volte a organizar suas tarefas com o assistente.</p>
      </div>
      <div class="auth-left-foot">
        <span>© {{ date('Y') }} TaskAI</span><span>•</span><a href="{{ route('home') }}">Termos</a><span>•</span><a href="{{ route('home') }}">Privacidade</a>
      </div>
    </div>

    <div class="auth-right">
      <div class="auth-form-wrap">
        <div class="auth-mobile-brand"><span class="lp-logo">{!! $logo !!}</span><span class="lp-brand-name">Tarefas Chat</span></div>

        <div class="auth-head">
          <h2>Definir nova senha</h2>
          <p>Escolha uma nova senha para a sua conta.</p>
        </div>

        @if ($errors->any())
          <div class="auth-errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
          @csrf
          <input type="hidden" name="token" value="{{ $token }}" />
          <div class="fld">
            <label for="email">E-mail</label>
            <div class="input">
              <span class="mi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span>
              <input id="email" type="email" name="email" value="{{ old('email', $email) }}" placeholder="seu@email.com" autocomplete="email" required />
            </div>
          </div>
          <div class="fld">
            <label for="password">Nova senha</label>
            <div class="input">
              <span class="mi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></span>
              <input id="password" type="password" name="password" placeholder="Mínimo 6 caracteres" autocomplete="new-password" required autofocus />
            </div>
          </div>
          <div class="fld">
            <label for="password_confirmation">Confirmar nova senha</label>
            <div class="input">
              <span class="mi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></span>
              <input id="password_confirmation" type="password" name="password_confirmation" placeholder="Repita a nova senha" autocomplete="new-password" required />
            </div>
          </div>
          <button class="btn btn-primary btn-block btn-lg" type="submit">Redefinir senha</button>
        </form>

        <p class="auth-foot"><a href="{{ route('login') }}">Voltar para entrar</a></p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
