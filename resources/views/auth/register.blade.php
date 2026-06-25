<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Criar conta · Tarefas Chat</title>
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
        <h1>Comece a organizar hoje.</h1>
        <p>Cada conta nasce com sua própria área de trabalho, projetos e assistente de IA. Sem cartão de crédito para começar.</p>
      </div>
      <div class="auth-left-foot">
        <span>© {{ date('Y') }} TaskAI</span><span>•</span><a href="{{ route('home') }}">Termos</a><span>•</span><a href="{{ route('home') }}">Privacidade</a>
      </div>
    </div>

    <div class="auth-right">
      <div class="auth-form-wrap">
        <div class="auth-mobile-brand"><span class="lp-logo">{!! $logo !!}</span><span class="lp-brand-name">Tarefas Chat</span></div>

        <div class="auth-head">
          <h2>Criar sua conta</h2>
          <p>Cada conta tem suas próprias tarefas, projetos e assistente.</p>
        </div>

        @if ($errors->any())
          <div class="auth-errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('register') }}">
          @csrf
          <div class="fld no-ico">
            <label for="name">Nome</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" placeholder="Seu nome" autocomplete="name" autofocus required />
          </div>
          <div class="fld no-ico">
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="seu@email.com" autocomplete="email" required />
          </div>
          <div class="fld no-ico">
            <label for="password">Senha</label>
            <input id="password" type="password" name="password" placeholder="Mínimo 6 caracteres" autocomplete="new-password" required />
          </div>
          <div class="fld no-ico">
            <label for="password_confirmation">Confirmar senha</label>
            <input id="password_confirmation" type="password" name="password_confirmation" placeholder="Repita a senha" autocomplete="new-password" required />
          </div>
          <button class="btn btn-primary btn-block btn-lg" type="submit" style="margin-top:4px">Criar conta</button>
        </form>

        @if (config('services.google.client_id'))
          <div class="auth-divider"><i></i><span>ou cadastre-se com</span><i></i></div>
          <a class="btn-oauth btn-oauth-on" href="{{ route('google.redirect') }}">
            <svg width="18" height="18" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
            Cadastrar com Google
          </a>
        @endif

        <p class="auth-foot">Já tem uma conta? <a href="{{ route('login') }}">Entrar</a></p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
