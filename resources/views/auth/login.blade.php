<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Entrar · Minhas Tarefas</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('app/auth.css') }}" />
</head>
<body>
  <div class="auth-card">
    <div class="auth-brand">
      <div class="auth-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3z"/><path d="M19 14l.8 2.2L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.8L19 14z"/></svg>
      </div>
      <div>
        <div class="auth-brand-name">Minhas Tarefas</div>
        <div class="auth-brand-sub">Organize com o assistente</div>
      </div>
    </div>

    <h1 class="auth-title">Bem-vindo de volta</h1>
    <p class="auth-sub">Entre para acessar suas tarefas.</p>

    @if ($errors->any())
      <div class="auth-errors">
        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
      </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
      @csrf
      <div class="auth-field">
        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="voce@exemplo.com" autocomplete="email" autofocus required />
      </div>
      <div class="auth-field">
        <label for="password">Senha</label>
        <input id="password" type="password" name="password" placeholder="••••••••" autocomplete="current-password" required />
      </div>
      <div class="auth-row">
        <label class="auth-remember"><input type="checkbox" name="remember" /> Lembrar de mim</label>
      </div>
      <button class="auth-btn" type="submit">Entrar</button>
    </form>

    <p class="auth-foot">Não tem conta? <a href="{{ route('register') }}">Criar conta</a></p>
    <div class="auth-demo">Conta de demonstração: <b>demo@taskai.test</b> · senha <b>password</b></div>
  </div>
</body>
</html>
