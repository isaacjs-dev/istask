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

        <p class="auth-foot">Já tem uma conta? <a href="{{ route('login') }}">Entrar</a></p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
