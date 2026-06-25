<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tarefas Chat — Organize suas tarefas com a ajuda da IA</title>
  <meta name="description" content="Tarefas, notas, projetos e calendário em um só lugar, com um assistente de IA que cria, conclui e organiza tudo por comandos em linguagem natural." />
  <link rel="icon" href="{{ asset('favicon.ico') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('landing.css') }}" />
</head>
<body>
@php($logo = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3z"/><path d="M19 14l.8 2.2L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.8L19 14z"/></svg>')

<!-- HEADER -->
<header class="lp-header">
  <div class="container">
    <a class="lp-brand" href="{{ route('home') }}">
      <span class="lp-logo">{!! $logo !!}</span>
      <span class="lp-brand-name">Tarefas Chat</span>
    </a>
    <nav class="lp-nav">
      <a href="#recursos">Recursos</a>
      <a href="#precos">Preços</a>
      <a href="#sobre">Sobre</a>
    </nav>
    <div class="lp-actions">
      <a class="link-entrar" href="{{ route('login') }}">Entrar</a>
      <a class="btn btn-primary" href="{{ route('register') }}">Começar grátis</a>
      <button class="lp-burger" type="button" aria-label="Abrir menu" onclick="document.querySelector('.lp-nav').style.display='flex';this.remove();">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
    </div>
  </div>
</header>

<!-- HERO -->
<section class="lp-hero">
  <div class="lp-hero-grid">
    <div style="animation:fadeUp .7s ease both;">
      <div class="lp-badge"><span class="dot"></span> Novo · Assistente de IA por comando</div>
      <h1>Organize suas tarefas com a ajuda da <span class="hl">IA</span></h1>
      <p class="lp-lead">Tarefas, notas, projetos e calendário em um só lugar — e um assistente que cria, conclui e organiza tudo a partir de comandos em linguagem natural.</p>
      <div class="lp-hero-cta">
        <a class="btn btn-primary btn-lg" href="{{ route('register') }}">Começar grátis</a>
        <a class="btn btn-ghost btn-lg" href="{{ route('login') }}">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="var(--accent)"><path d="M8 5v14l11-7z"/></svg> Ver demonstração
        </a>
      </div>
      <div class="lp-hero-trust">
        <div class="lp-avatars">
          <span style="background:#c7d2fe"></span><span style="background:#a5b4fc"></span><span style="background:#818cf8"></span><span style="background:var(--accent)"></span>
        </div>
        <span>Sem cartão de crédito · grátis para começar</span>
      </div>
    </div>
    <div class="lp-hero-img">
      <img src="{{ asset('landing/hero.png') }}" alt="Tarefas Chat em ação" />
    </div>
  </div>
</section>

<!-- TRUST -->
<section class="lp-trust">
  <div class="lp-trust-grid">
    <div><div class="num">+50 mil</div><div class="lbl">tarefas concluídas por dia</div></div>
    <div><div class="num">4,9/5</div><div class="lbl">avaliação média dos times</div></div>
    <div><div class="num">12 mil+</div><div class="lbl">equipes organizadas</div></div>
    <div><div class="num">99,9%</div><div class="lbl">de disponibilidade</div></div>
  </div>
</section>

<!-- FEATURES -->
<section class="lp-section" id="recursos">
  <div class="lp-section-head">
    <div class="lp-eyebrow">Recursos</div>
    <h2>Tudo o que você precisa para fluir</h2>
    <p>Um espaço de trabalho completo — das tarefas do dia a dia aos projetos da equipe — com a IA cuidando da organização.</p>
  </div>
  <div class="lp-features">
    <div class="lp-card">
      <div class="ico"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6l1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2"/></svg></div>
      <h3>Tarefas em lista e Kanban</h3>
      <p>Visualize do seu jeito: listas agrupadas por prioridade ou um quadro Kanban com arrastar e soltar.</p>
    </div>
    <div class="lp-card">
      <div class="ico"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h11l3 3v15H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg></div>
      <h3>Notas e cadernos</h3>
      <p>Registre ideias, atas e lembretes em notas coloridas, organizadas por cadernos e etiquetas.</p>
    </div>
    <div class="lp-card">
      <div class="ico"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg></div>
      <h3>Painel de projetos</h3>
      <p>Acompanhe o andamento de cada projeto com filtros por status, prioridade e área de trabalho.</p>
    </div>
    <div class="lp-card">
      <div class="ico"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v5l3 2"/><path d="M3.5 9a9 9 0 1 1 .5 5"/><path d="M3 5v4h4"/></svg></div>
      <h3>Diário de atividades</h3>
      <p>Um histórico automático de tudo que foi criado, concluído e alterado — perfeito para retomar o contexto.</p>
    </div>
    <div class="lp-card feat-ai">
      <div class="ico"><svg width="24" height="24" fill="#fff"><path d="M12 2l1.9 5.6L19.5 9.5 14 11.4 12 17l-2-5.6L4.5 9.5 10 7.6 12 2z"/><circle cx="19" cy="5" r="1.4"/></svg></div>
      <h3>Assistente de IA</h3>
      <p>Crie, conclua, priorize e reorganize tarefas com comandos em linguagem natural. A IA faz o trabalho repetitivo.</p>
    </div>
    <div class="lp-card">
      <div class="ico"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><circle cx="17" cy="9" r="2.4"/><path d="M15 20a5 5 0 0 1 6-3"/></svg></div>
      <h3>Áreas compartilhadas</h3>
      <p>Separe o pessoal do trabalho e compartilhe áreas com a equipe, mantendo cada contexto no seu lugar.</p>
    </div>
  </div>
</section>

<!-- AI HIGHLIGHT -->
<section class="lp-ai" id="sobre">
  <div class="lp-ai-grid">
    <div>
      <div class="lp-eyebrow">Assistente de IA</div>
      <h2>Fale com suas tarefas como você fala com uma pessoa</h2>
      <p class="lp-lead">Sem menus, sem formulários. Descreva o que precisa e o assistente cuida do resto — criando, concluindo, priorizando e juntando duplicadas automaticamente.</p>
      <div class="lp-cmds">
        <div class="lp-cmd"><span class="q">“</span>Crie uma tarefa para revisar o contrato amanhã</div>
        <div class="lp-cmd"><span class="q">“</span>O que eu tenho para hoje?</div>
        <div class="lp-cmd"><span class="q">“</span>Junte as tarefas duplicadas e priorize as atrasadas</div>
      </div>
    </div>
    <div class="lp-chat">
      <div class="lp-chat-head">
        <span class="lp-chat-ava"><svg width="16" height="16" fill="#fff"><path d="M12 3l1.6 4.8L18 9.2 13.6 10.8 12 16l-1.6-5.2L6 9.2l4.4-1.4L12 3z"/></svg></span>
        <div><div style="font-weight:800;font-size:14px;">Assistente <span style="color:#16a34a;font-size:11px;font-weight:700;">● ativo</span></div><div style="font-size:11px;color:var(--muted);">Controla suas tarefas por comando</div></div>
      </div>
      <div class="lp-chat-body">
        <div class="bubble ai">Olá! Escreva comandos em linguagem natural — posso <b>criar</b>, <b>concluir</b>, <b>priorizar</b>, <b>juntar duplicadas</b> e reorganizar tudo automaticamente.</div>
        <div class="bubble me">Junte as tarefas duplicadas da API</div>
        <div class="bubble res">Encontrei <b>13 tarefas</b> relacionadas. Posso juntar em uma só e manter a de maior prioridade?
          <div class="acts"><span class="chip-acc">Juntar todas</span><span class="chip-line">Revisar</span></div>
        </div>
        <div class="lp-chat-input">
          <span class="ph">Digite seu comando… ex: Adiciona tarefa</span>
          <span class="send"><svg width="15" height="15" fill="#fff"><path d="M3 20l18-8L3 4v6l12 2-12 2z"/></svg></span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- PRODUCT SHOTS -->
<section class="lp-section">
  <div class="lp-section-head">
    <div class="lp-eyebrow">O produto</div>
    <h2>Bonito de ver, fácil de usar</h2>
    <p>Uma interface limpa que coloca seu trabalho em primeiro plano — da lista de tarefas ao quadro Kanban e às notas.</p>
  </div>
  <div class="lp-shots">
    <figure class="shot">
      <div class="shot-bar"><i style="background:#ff6058"></i><i style="background:#ffbd2e"></i><i style="background:#28c840"></i><span class="t">Tarefas</span></div>
      <img src="{{ asset('landing/shot-list.png') }}" alt="Lista de tarefas do Tarefas Chat" onerror="this.src='{{ asset('landing/hero.png') }}'" />
    </figure>
    <div class="col">
      <figure class="shot">
        <div class="shot-bar"><i style="background:#ff6058"></i><i style="background:#ffbd2e"></i><i style="background:#28c840"></i><span class="t">Painel</span></div>
        <img src="{{ asset('landing/shot-panel.png') }}" alt="Painel de projetos do Tarefas Chat" onerror="this.src='{{ asset('landing/hero.png') }}'" />
      </figure>
      <figure class="shot">
        <div class="shot-bar"><i style="background:#ff6058"></i><i style="background:#ffbd2e"></i><i style="background:#28c840"></i><span class="t">Notas</span></div>
        <img src="{{ asset('landing/shot-notes.png') }}" alt="Notas do Tarefas Chat" onerror="this.src='{{ asset('landing/hero.png') }}'" />
      </figure>
    </div>
  </div>
</section>

<!-- TESTIMONIALS -->
<section class="lp-section">
  <div class="lp-section-head">
    <div class="lp-eyebrow">Depoimentos</div>
    <h2>Quem usa, recomenda</h2>
  </div>
  <div class="lp-quotes">
    <div class="quote">
      <p>“O assistente economiza meu tempo todos os dias. Eu só digito o que preciso e ele organiza tudo.”</p>
      <div class="who"><span class="av">MR</span><div><div class="nm">Marina Reis</div><div class="rl">Gerente de Projetos</div></div></div>
    </div>
    <div class="quote">
      <p>“Finalmente um lugar só para tarefas, notas e projetos. As áreas compartilhadas mudaram nosso fluxo.”</p>
      <div class="who"><span class="av">CS</span><div><div class="nm">Carlos Souza</div><div class="rl">Tech Lead</div></div></div>
    </div>
    <div class="quote">
      <p>“Simples, bonito e rápido. O Kanban com IA é exatamente o que eu procurava há anos.”</p>
      <div class="who"><span class="av">AL</span><div><div class="nm">Ana Lima</div><div class="rl">Designer de Produto</div></div></div>
    </div>
  </div>
</section>

<!-- PRICING -->
<section class="lp-section" id="precos">
  <div class="lp-section-head">
    <div class="lp-eyebrow">Preços</div>
    <h2>Comece grátis, evolua quando quiser</h2>
    <p>Sem surpresas. Cancele a qualquer momento.</p>
  </div>
  @php($check = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>')
  <div class="lp-prices">
    <div class="price">
      <div class="pn">Grátis</div>
      <div class="pv">R$0<small>/mês</small></div>
      <div class="pd">Para uso pessoal e para começar.</div>
      <ul>
        <li>{!! $check !!} Tarefas, notas e calendário</li>
        <li>{!! $check !!} 1 área de trabalho</li>
        <li>{!! $check !!} Assistente de IA (limite diário)</li>
      </ul>
      <a class="btn btn-ghost btn-block" href="{{ route('register') }}">Criar conta grátis</a>
    </div>
    <div class="price pop">
      <span class="tag">Recomendado</span>
      <div class="pn">Pro</div>
      <div class="pv">R$29<small>/mês</small></div>
      <div class="pd">Para profissionais que querem mais.</div>
      <ul>
        <li>{!! $check !!} Tudo do Grátis</li>
        <li>{!! $check !!} Áreas e projetos ilimitados</li>
        <li>{!! $check !!} Assistente de IA sem limites</li>
        <li>{!! $check !!} Painel de acompanhamento</li>
      </ul>
      <a class="btn btn-primary btn-block" href="{{ route('register') }}">Começar agora</a>
    </div>
    <div class="price">
      <div class="pn">Times</div>
      <div class="pv">R$99<small>/mês</small></div>
      <div class="pd">Para equipes que colaboram.</div>
      <ul>
        <li>{!! $check !!} Tudo do Pro</li>
        <li>{!! $check !!} Compartilhamento por área e projeto</li>
        <li>{!! $check !!} Permissões e colaboração em tempo real</li>
      </ul>
      <a class="btn btn-ghost btn-block" href="{{ route('register') }}">Falar com vendas</a>
    </div>
  </div>
</section>

<!-- FINAL CTA -->
<section class="lp-final">
  <div class="container">
    <h2>Pronto para elevar sua produtividade?</h2>
    <p>Crie sua conta gratuita e deixe a IA cuidar da organização.</p>
    <a class="btn btn-primary btn-lg" href="{{ route('register') }}">Criar conta grátis</a>
  </div>
</section>

<!-- FOOTER -->
<footer class="lp-footer">
  <div class="container">
    <div class="lp-foot-grid">
      <div>
        <a class="lp-brand" href="{{ route('home') }}"><span class="lp-logo">{!! $logo !!}</span><span class="lp-brand-name">Tarefas Chat</span></a>
        <p>Tarefas, notas e projetos com um assistente de IA por comando. Organize com clareza.</p>
      </div>
      <div class="ft-col"><h4>Produto</h4><a href="#recursos">Recursos</a><a href="#precos">Preços</a><a href="{{ route('login') }}">Entrar</a></div>
      <div class="ft-col"><h4>Empresa</h4><a href="#sobre">Sobre</a><a href="#">Blog</a><a href="#">Carreiras</a></div>
      <div class="ft-col"><h4>Contato</h4><a href="#">Suporte</a><a href="#">Termos</a><a href="#">Privacidade</a></div>
    </div>
    <div class="lp-foot-bottom">
      <span>© {{ date('Y') }} Tarefas Chat. Todos os direitos reservados.</span>
      <span>Feito com foco em produtividade.</span>
    </div>
  </div>
</footer>
</body>
</html>
