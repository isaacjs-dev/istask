/* Vanilla render layer. Reads window.state, returns HTML strings.
   window.Render: sidebarHTML, headerHTML, updateHeader, bodyHTML, chatHTML, suggestHTML */
(function () {
  const TD = window.TaskData;
  const { PRIORITY, STATUS, SECTIONS, PROJECTS, COLUMNS, MONTHS, TODAY, parseDue } = TD;
  const icon = window.icon;
  const U = window.UI;

  // Área virtual que reúne projetos compartilhados individualmente (área de origem inacessível).
  const VIRTUAL_SHARED_WS = "shared-projects";
  window.VIRTUAL_SHARED_WS = VIRTUAL_SHARED_WS;

  function sharedSoloProjects() {
    return ((window.state && window.state.projects) || []).filter((p) => p.sharedSolo);
  }
  function sharedSoloKeys() {
    return new Set(sharedSoloProjects().map((p) => p.slug + "@" + (p.workspaceId != null ? p.workspaceId : "")));
  }
  window.sharedSoloKeys = sharedSoloKeys;

  const STATUS_FILTERS = [
    { id: "pendente", label: "Pendentes", icon: "Inbox", color: "var(--s-pendente)" },
    { id: "andamento", label: "Em andamento", icon: "Clock2", color: "var(--s-andamento)" },
    { id: "aguardando", label: "Aguardando terceiros", icon: "Hourglass", color: "var(--s-aguardando)" },
    { id: "concluido", label: "Concluídas", icon: "Check", color: "var(--s-concluido)" },
    { id: "atrasada", label: "Atrasadas", icon: "Alert", color: "var(--p-urgente)" },
    { id: "arquivada", label: "Arquivadas", icon: "Archive", color: "var(--ink-3)" },
  ];
  const PROJ_ICON = { geral: "Folder", sistemas: "Settings", processos: "Refresh", integracoes: "Merge", comunicacao: "Comment" };

  const PAGES = [
    { id: "tarefas", label: "Tarefas", icon: "Checklist" },
    { id: "notas", label: "Notas", icon: "NotebookPen" },
    { id: "panel", label: "Painel", icon: "Kanban" },
    { id: "atividades", label: "Atividades", icon: "History" },
    { id: "diario", label: "Diário", icon: "BookOpen" },
  ];

  const SUGGESTIONS = [
    { icon: "Plus", text: "Adiciona tarefa Revisar contrato amanhã, prioridade alta" },
    { icon: "Merge", text: "Junta as tarefas duplicadas da API da EL" },
    { icon: "Check", text: "Conclui a tarefa do orçamento do Stenio" },
    { icon: "Flag", text: "Deixa o Eduardo como urgente" },
  ];
  window.SUGGESTIONS = SUGGESTIONS;

  // lista de projetos dinâmica (state.projects vem do backend; cai em PROJECTS se vazio)
  function projectList() {
    const sp = (window.state && window.state.projects) || [];
    if (sp.length) {
      const aw = window.state.activeWorkspaceId;
      if (aw === VIRTUAL_SHARED_WS) {
        return sharedSoloProjects().map((p) => ({ id: p.slug, name: p.name, icon: p.icon }));
      }
      return sp.filter((p) => !p.sharedSolo && (!aw || String(p.workspaceId) === String(aw))).map((p) => ({ id: p.slug, name: p.name, icon: p.icon }));
    }
    return PROJECTS.map((p) => ({ id: p.id, name: p.name, icon: null }));
  }

  function activeWorkspaceName() {
    if (window.state.activeWorkspaceId === VIRTUAL_SHARED_WS) return "Projetos compartilhados";
    const ws = (window.state.workspaces || []).find((w) => String(w.id) === String(window.state.activeWorkspaceId));
    return ws ? ws.name : "Workspace";
  }

  // ---------- SIDEBAR ----------
  function sidebarHTML() {
    const tasks = window.state.tasks;
    const live = tasks.filter((t) => !t.archivedAt); // arquivadas não contam nas demais visões
    const activeWs = window.state.activeWorkspaceId;
    const soloKeys = sharedSoloKeys();
    const inSolo = (t) => soloKeys.has(t.project + "@" + (t.workspaceId != null ? t.workspaceId : ""));
    const projCount = (id) => {
      if (activeWs === VIRTUAL_SHARED_WS) return (id === "geral" ? live.filter(inSolo) : live.filter((t) => inSolo(t) && t.project === id)).length;
      return id === "geral" ? live.length : live.filter((t) => t.project === id).length;
    };
    const statusCount = (id) => id === "arquivada" ? tasks.filter((t) => t.archivedAt).length
      : id === "atrasada" ? live.filter((t) => TD.isOverdue(t)).length
      : live.filter((t) => t.status === id).length;
    const prioCount = (id) => live.filter((t) => t.priority === id && t.status !== "concluido").length;
    const af = window.state.filter, ap = window.state.project, page = window.state.page || "tarefas";

    const workspaces = window.state.workspaces || [];
    const grouping = (window.state.prefs && window.state.prefs.workspaceGrouping) || "merged";
    const hasSolo = sharedSoloProjects().length > 0;
    const wsItemHTML = (w) => `
            <button class="sb-item sb-ws-item${String(activeWs) === String(w.id) ? " active" : ""}" data-act="workspace" data-id="${w.id}">
              <span class="sb-ico">${icon(w.icon || "Folder", 17)}</span>
              <span class="sb-item-label">${U.esc(w.name)}</span>
              ${w.isOwner === false ? `<span class="sb-ws-shared" title="Compartilhada por ${U.esc(w.ownerName || "")}">${icon("User", 12)}</span>` : ""}
            </button>`;
    const sharedWsItemHTML = `
            <button class="sb-item sb-ws-item sb-ws-virtual${activeWs === VIRTUAL_SHARED_WS ? " active" : ""}" data-act="workspace" data-id="${VIRTUAL_SHARED_WS}">
              <span class="sb-ico">${icon("Merge", 17)}</span>
              <span class="sb-item-label">Projetos compartilhados</span>
              <span class="sb-count">${sharedSoloProjects().length}</span>
            </button>`;
    let wsItems;
    if (grouping === "separated") {
      const mine = workspaces.filter((w) => w.isOwner !== false);
      const shared = workspaces.filter((w) => w.isOwner === false);
      wsItems = `
          <div class="sb-ws-sub-title">Minhas áreas</div>
          ${mine.map(wsItemHTML).join("")}
          ${shared.length || hasSolo ? `<div class="sb-ws-sub-title">Compartilhadas comigo</div>` : ""}
          ${shared.map(wsItemHTML).join("")}
          ${hasSolo ? sharedWsItemHTML : ""}`;
    } else {
      wsItems = `${workspaces.map(wsItemHTML).join("")}${hasSolo ? sharedWsItemHTML : ""}`;
    }
    const wsGroup = workspaces.length ? `
        <div class="sb-group sb-ws-group">
          <div class="sb-group-title">Área de Trabalho <button class="sb-add" data-act="new-workspace" title="Nova área">${icon("Plus", 13)}</button></div>
          ${wsItems}
          <button class="sb-item sb-ws-manage" data-act="manage-workspaces">
            <span class="sb-ico">${icon("Settings", 15)}</span>
            <span class="sb-item-label">Gerenciar áreas</span>
          </button>
        </div>` : "";

    const menuGroup = `
        <div class="sb-group">
          <div class="sb-group-title">Menu</div>
          ${PAGES.map((p) => `<button class="sb-item${page === p.id ? " active" : ""}" data-act="page" data-id="${p.id}">
            <span class="sb-ico">${icon(p.icon, 17)}</span>
            <span class="sb-item-label">${p.label}</span>
          </button>`).join("")}
        </div>`;

    const tarefasGroups = page !== "tarefas" ? "" : `
        <div class="sb-group">
          <div class="sb-group-title">Projetos <button class="sb-add" data-act="new-project" title="Novo projeto">${icon("Plus", 13)}</button></div>
          ${projectList().map((p) => `
            <button class="sb-item${ap === p.id ? " active" : ""}" data-act="project" data-id="${p.id}">
              <span class="sb-ico">${icon(PROJ_ICON[p.id] || p.icon || "Folder", 17)}</span>
              <span class="sb-item-label">${U.esc(p.name)}</span>
              <span class="sb-count">${projCount(p.id)}</span>
            </button>`).join("")}
        </div>
        <div class="sb-group">
          <div class="sb-group-title">Filtros</div>
          ${STATUS_FILTERS.map((f) => {
            const on = af === f.id;
            return `<button class="sb-item${on ? " active" : ""}" data-act="filter" data-id="${f.id}">
              <span class="sb-ico"${on ? "" : ` style="color:${f.color}"`}>${icon(f.icon, 16)}</span>
              <span class="sb-item-label">${f.label}</span>
              <span class="sb-count">${statusCount(f.id)}</span>
            </button>`;
          }).join("")}
        </div>
        <div class="sb-group">
          <div class="sb-group-title">Prioridades</div>
          ${Object.values(PRIORITY).map((p) => {
            const fid = "prio:" + p.id, on = af === fid;
            return `<button class="sb-item${on ? " active" : ""}" data-act="filter" data-id="${fid}">
              <span class="sb-dot" style="background:${p.color}"></span>
              <span class="sb-item-label">${p.label}</span>
              <span class="sb-count">${prioCount(p.id)}</span>
            </button>`;
          }).join("")}
        </div>`;

    return `
      <div class="sb-brand">
        <div class="sb-logo">${icon("Sparkles", 20)}</div>
        <div>
          <div class="sb-brand-name">Minhas Tarefas</div>
          <div class="sb-brand-sub">Organize com o assistente</div>
        </div>
      </div>
      <button class="sb-new" data-act="add-task">${icon("Plus", 17)} Nova Lista</button>
      <div class="sb-scroll scroll">
        ${wsGroup}
        ${menuGroup}
        ${tarefasGroups}
        <div class="sb-group">
          <div class="sb-group-title">Sistema</div>
          <button class="sb-item${page === "config" ? " active" : ""}" data-act="settings">
            <span class="sb-ico">${icon("Settings", 17)}</span>
            <span class="sb-item-label">Configurações</span>
          </button>
        </div>
      </div>
      <div class="sb-foot">
        ${U.avatarHTML(TD.me, "sb-ava")}
        <div style="flex:1;min-width:0">
          <div class="sb-foot-name">${U.esc(TD.me.name)}</div>
          <div class="sb-foot-mail">${U.esc(TD.me.email || "workspace pessoal")}</div>
        </div>
        <button class="sb-ico" data-act="logout" title="Sair" style="border:none;background:none;color:var(--ink-3)">${icon("Logout", 17)}</button>
      </div>`;
  }

  // ---------- HEADER ----------
  const VIEWS = [
    { id: "list", label: "Lista", icon: "List" },
    { id: "kanban", label: "Kanban", icon: "Kanban" },
    { id: "calendar", label: "Calendário", icon: "Calendar" },
    { id: "chat", label: "Chat", icon: "Comment" },
  ];

  function headerHTML() {
    const p = window.state.page;
    if (p === "notas") return notesHeaderHTML();
    if (p === "atividades") return atividadesHeaderHTML();
    if (p === "diario") return diarioHeaderHTML();
    if (p === "config") return configHeaderHTML();
    if ((p === "panel" || p === "edit-ws" || p === "edit-proj") && window.Panel) return window.Panel.headerHTML();
    return tarefasHeaderHTML();
  }

  function configHeaderHTML() {
    return `
      <div class="c-head-top">
        <button class="c-menu" data-act="nav" title="Menu" aria-label="Abrir menu">${icon("Menu", 20)}</button>
        <div class="c-title-wrap">
          <div class="c-bread"><span class="c-bread-proj">Preferências do sistema</span></div>
          <h1 class="c-title">Configurações</h1>
          <div class="c-sub">Aparência, comportamento, assistente e conta.</div>
        </div>
        <div class="c-actions">${bellHTML()}</div>
      </div>`;
  }

  function atividadesHeaderHTML() {
    return `
      <div class="c-head-top">
        <button class="c-menu" data-act="nav" title="Menu" aria-label="Abrir menu">${icon("Menu", 20)}</button>
        <div class="c-title-wrap">
          <div class="c-bread"><span class="c-bread-proj">Registro de atividades</span></div>
          <h1 class="c-title">Atividades</h1>
          <div class="c-sub"></div>
        </div>
        <div class="c-actions">
          <div class="search">
            <span class="sb-ico">${icon("Search", 16)}</span>
            <input id="searchInput" placeholder="Buscar atividades…" />
          </div>
          ${bellHTML()}
        </div>
      </div>`;
  }

  function bellHTML() {
    const n = (window.state.notifications || []).length;
    return `<button class="btn-ghost notif-bell" data-act="notifications" title="Avisos">${icon("Bell", 18)}${n ? `<span class="notif-badge">${n > 9 ? "9+" : n}</span>` : ""}</button>`;
  }

  function tarefasHeaderHTML() {
    return `
      <div class="c-head-top">
        <button class="c-menu" data-act="nav" title="Menu" aria-label="Abrir menu">${icon("Menu", 20)}</button>
        <div class="c-title-wrap">
          <div class="c-bread"><span class="c-bread-ws">${U.esc(activeWorkspaceName())}</span> ${icon("ChevRight", 13)} <span class="c-bread-proj">Geral</span></div>
          <h1 class="c-title">Minhas Tarefas</h1>
          <div class="c-sub"></div>
        </div>
        <div class="c-actions">
          <div class="search">
            <span class="sb-ico">${icon("Search", 16)}</span>
            <input id="searchInput" placeholder="Buscar tarefas…" />
          </div>
          ${bellHTML()}
          <button class="btn-primary" data-act="add-task">${icon("Plus", 16)} Nova tarefa</button>
        </div>
      </div>
      <div class="c-head-bottom">
        <div class="tabs">
          ${VIEWS.map((v) => `<button class="tab" data-act="view" data-id="${v.id}"><span class="sb-ico">${icon(v.icon, 16)}</span>${v.label}</button>`).join("")}
        </div>
        <div class="c-meta"><span class="c-meta-dyn"></span></div>
      </div>`;
  }

  function notesHeaderHTML() {
    return `
      <div class="c-head-top">
        <button class="c-menu" data-act="nav" title="Menu" aria-label="Abrir menu">${icon("Menu", 20)}</button>
        <div class="c-title-wrap">
          <div class="c-bread"><span class="c-bread-ws">${U.esc(activeWorkspaceName())}</span> ${icon("ChevRight", 13)} <span class="c-bread-proj">Notas</span></div>
          <h1 class="c-title">Notas</h1>
          <div class="c-sub"></div>
        </div>
        <div class="c-actions">
          <div class="search">
            <span class="sb-ico">${icon("Search", 16)}</span>
            <input id="searchInput" placeholder="Buscar notas…" />
          </div>
          <div class="note-view-toggle note-m3">
            <button class="${window.state.notesViewMode !== "list" ? "active" : ""}" data-note-act="set-view-mode" data-mode="grid" title="Visualizar em grade">
              <span class="material-symbols-outlined">grid_view</span>
            </button>
            <button class="${window.state.notesViewMode === "list" ? "active" : ""}" data-note-act="set-view-mode" data-mode="list" title="Visualizar em lista">
              <span class="material-symbols-outlined">view_list</span>
            </button>
          </div>
          ${bellHTML()}
          <button class="btn-primary" data-note-act="new">${icon("Plus", 16)} Nova nota</button>
        </div>
      </div>`;
  }

  function diarioHeaderHTML() {
    return `
      <div class="c-head-top">
        <button class="c-menu" data-act="nav" title="Menu" aria-label="Abrir menu">${icon("Menu", 20)}</button>
        <div class="c-title-wrap">
          <div class="c-bread"><span class="c-bread-ws">${U.esc(activeWorkspaceName())}</span> ${icon("ChevRight", 13)} <span class="c-bread-proj">Diário</span></div>
          <h1 class="c-title">Diário</h1>
          <div class="c-sub"></div>
        </div>
        <div class="c-actions">
          <div class="search">
            <span class="sb-ico">${icon("Search", 16)}</span>
            <input id="searchInput" placeholder="Buscar no diário…" />
          </div>
          ${bellHTML()}
          <button class="btn-primary" data-diary-act="new">${icon("Plus", 16)} Nova entrada</button>
        </div>
      </div>`;
  }

  function updateHeader() {
    const wsEl = document.querySelector(".c-bread-ws");
    if (wsEl) wsEl.textContent = activeWorkspaceName();
    const p = window.state.page;
    if (p === "notas") return updateNotesHeader();
    if (p === "atividades") return updateActivitiesHeader();
    if (p === "diario") return updateDiarioHeader();
    if (p === "config") return; // header estático
    if (p === "panel" || p === "edit-ws" || p === "edit-proj") return; // header gerido pelo Panel
    return updateTarefasHeader();
  }

  function updateActivitiesHeader() {
    const sub = document.querySelector(".c-sub");
    if (sub) sub.textContent = "Cada ação registrada nas suas tarefas, separada por dia.";
  }

  function updateTarefasHeader() {
    const s = window.state, tasks = s.tasks;
    const projName = ((window.state.projects || []).find((p) => (p.slug || p.id) === s.project) || {}).name || "Geral";
    const counts = {
      done: tasks.filter((t) => t.status === "concluido").length,
      open: tasks.filter((t) => t.status !== "concluido").length,
      overdue: tasks.filter((t) => TD.isOverdue(t)).length,
    };
    document.querySelector(".c-bread-proj").textContent = projName;
    document.querySelector(".c-title").textContent = s.project === "geral" ? "Minhas Tarefas" : projName;
    document.querySelector(".c-sub").textContent =
      `${counts.open} pendentes · ${counts.done} concluídas${counts.overdue ? ` · ${counts.overdue} atrasadas` : ""}`;
    document.querySelectorAll(".tab").forEach((t) => t.classList.toggle("active", t.dataset.id === s.view));
    const vis = window.visibleTasks();
    let chip = "";
    if (s.filter) chip = `<span class="chip-filter">${filterLabel(s.filter)}<button data-act="clear-filter">${icon("X", 13)}</button></span>`;
    document.querySelector(".c-meta-dyn").innerHTML =
      `${chip}<span><b>${vis.length}</b> ${vis.length === 1 ? "tarefa" : "tarefas"}</span>`;
  }

  function updateNotesHeader() {
    const view = window.state.notesView || "active";
    const notes = window.state.notes || [];
    let n, label;
    if (view === "archived") { n = notes.filter((x) => x.archivedAt).length; label = "no arquivo"; }
    else if (view === "labels") { n = (window.state.labels || []).length; label = n === 1 ? "etiqueta" : "etiquetas"; }
    else if (view === "trash") { n = null; label = null; }
    else { n = notes.filter((x) => !x.archivedAt).length; label = n === 1 ? "nota" : "notas"; }
    document.querySelector(".c-sub").textContent = (n === null) ? "Itens excluídos há mais de 7 dias são removidos automaticamente" : `${n} ${label}`;
    document.querySelectorAll(".note-view-toggle button").forEach((b) => b.classList.toggle("active", b.dataset.mode === (window.state.notesViewMode === "list" ? "list" : "grid")));
  }

  function updateDiarioHeader() {
    const n = (window.state.diaryEntries || []).length;
    document.querySelector(".c-sub").textContent = `${n} ${n === 1 ? "entrada" : "entradas"}`;
  }

  function filterLabel(f) {
    if (f === "atrasada") return "Atrasadas";
    if (f.startsWith("prio:")) return "Prioridade: " + PRIORITY[f.slice(5)].label;
    return STATUS[f] ? STATUS[f].label : f;
  }

  // ---------- BODY ----------
  function bodyHTML() {
    const s = window.state;
    if (s.page === "notas") return window.Render.notasPageHTML();
    if (s.page === "atividades") return window.Render.activitiesPageHTML();
    if (s.page === "diario") return window.Render.diarioPageHTML();
    if (s.page === "config") return configPageHTML();
    if (s.page === "panel") return window.Panel ? window.Panel.panelHTML() : "";
    if (s.page === "edit-ws") return window.Panel ? window.Panel.wsEditHTML() : "";
    if (s.page === "edit-proj") return window.Panel ? window.Panel.projEditHTML() : "";
    const v = s.view;
    if (v === "kanban") return kanbanHTML();
    if (v === "calendar") return calendarHTML();
    if (v === "chat") return chatFullScreenHTML();
    return listHTML();
  }

  function chatFullScreenHTML() {
    return `<div class="chat-fullscreen-placeholder"></div>`;
  }

  function taskCardHTML(t) {
    const done = t.status === "concluido";
    const flash = window.state.flashId === t.id ? " flash" : "";
    return `
      <div class="card${done ? " done" : ""}${flash}" data-act="open" data-id="${t.id}" tabindex="0" role="button" aria-label="Abrir tarefa: ${U.esc(t.title)}">
        <button class="card-check${done ? " checked" : ""}" data-act="toggle" data-id="${t.id}" title="${done ? "Reabrir" : "Concluir"}">${icon("CheckSmall", 13)}</button>
        <div class="card-main">
          <div class="card-title">${U.esc(t.title)}</div>
          ${t.description ? `<div class="card-desc">${U.esc(U.stripHtml(t.description))}</div>` : ""}
          <div class="card-foot">
            ${done ? U.statusBadge("concluido") : U.priorityBadge(t.priority)}
            ${!done ? U.statusBadge(t.status) : ""}
            ${U.duePill(t)}
          </div>
          ${U.labelChips(t.labels)}
        </div>
        <div class="card-side">
          <span class="card-proj">${U.esc(U.projectName(t.project))}</span>
          <div class="card-icons">${U.recurMini(t)}${U.remindMini(t)}${U.checklistMini(t.checklist)}${U.commentMini(t.comments)}</div>
        </div>
      </div>`;
  }

  function listHTML() {
    const tasks = window.visibleTasks();
    if (!tasks.length) return emptyHTML();
    const groups = SECTIONS.map((sec) => ({ sec, items: tasks.filter((t) => U.liveSection(t) === sec.id) })).filter((g) => g.items.length);
    groups.forEach((g) => {
      g.items.sort((a, b) => {
        const pr = PRIORITY[a.priority].rank - PRIORITY[b.priority].rank;
        if (pr !== 0) return pr;
        const da = a.due ? parseDue(a.due).getTime() : Infinity;
        const db = b.due ? parseDue(b.due).getTime() : Infinity;
        return da - db;
      });
    });
    return `<div class="list-view">${groups.map((g) => `
      <section class="section">
        <div class="section-head">
          <span class="section-bar" style="background:${g.sec.color}"></span>
          <span class="section-title">${g.sec.title}</span>
          <span class="section-count">${g.items.length}</span>
          <span class="section-line"></span>
        </div>
        <div class="section-cards">${g.items.map(taskCardHTML).join("")}</div>
      </section>`).join("")}</div>`;
  }

  function emptyHTML() {
    return `<div class="empty"><div class="empty-ico">${icon("Search", 24)}</div>
      <h3>Nenhuma tarefa encontrada</h3><p>Ajuste os filtros ou peça ao assistente para criar uma nova tarefa.</p></div>`;
  }

  // ---------- KANBAN ----------
  function kanbanCardHTML(t) {
    const flash = window.state.flashId === t.id ? " flash" : "";
    return `
      <div class="kcard${flash}" draggable="true" data-act="open" data-id="${t.id}" tabindex="0" role="button" aria-label="Abrir tarefa: ${U.esc(t.title)}">
        <div class="kcard-top">${U.priorityBadge(t.priority)}</div>
        <div class="kcard-title">${U.esc(t.title)}</div>
        ${t.description ? `<div class="kcard-desc">${U.esc(U.stripHtml(t.description))}</div>` : ""}
        ${U.labelChips(t.labels)}
        <div class="kcard-foot">
          ${U.duePill(t, true)}
          <span class="card-proj">${U.esc(U.projectName(t.project))}</span>
          <div class="kcard-icons">${U.recurMini(t)}${U.remindMini(t)}${U.checklistMini(t.checklist)}${U.commentMini(t.comments)}</div>
        </div>
      </div>`;
  }

  function kanbanHTML() {
    const tasks = window.visibleTasks();
    return `<div class="kanban">${COLUMNS.map((col) => {
      const items = tasks.filter((t) => t.status === col.id);
      return `<div class="kcol" data-col="${col.id}">
        <div class="kcol-head">
          <span class="kcol-dot" style="background:${col.dot}"></span>
          <span class="kcol-name">${col.name}</span>
          <span class="kcol-count">${items.length}</span>
          <button class="kcol-add" data-act="add-task" title="Adicionar">${icon("Plus", 15)}</button>
        </div>
        <div class="kcol-body scroll">
          ${items.length ? items.map(kanbanCardHTML).join("")
            : `<div style="padding:18px 8px;text-align:center;color:var(--ink-4);font-size:12px;font-weight:600">Arraste tarefas para cá</div>`}
        </div>
      </div>`;
    }).join("")}</div>`;
  }

  // ---------- CALENDAR ----------
  function calendarHTML() {
    const s = window.state;
    const year = s.calYear, month = s.calMonth;
    const first = new Date(year, month, 1);
    const startDow = first.getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysPrev = new Date(year, month, 0).getDate();
    const tasks = window.visibleTasks();

    const cells = [];
    for (let i = 0; i < startDow; i++) cells.push({ day: daysPrev - startDow + 1 + i, dim: true, m: month - 1, y: month === 0 ? year - 1 : year });
    for (let d = 1; d <= daysInMonth; d++) cells.push({ day: d, dim: false, m: month, y: year });
    let extra = 1;
    while (cells.length % 7 !== 0 || cells.length < 42) { cells.push({ day: extra++, dim: true, m: month + 1, y: month === 11 ? year + 1 : year }); }

    const tasksOn = (y, m, d) => tasks.filter((t) => { if (!t.due) return false; const dt = parseDue(t.due); return dt.getFullYear() === y && dt.getMonth() === m && dt.getDate() === d; });
    const isToday = (c) => !c.dim && c.y === TODAY.getFullYear() && c.m === TODAY.getMonth() && c.day === TODAY.getDate();
    const dows = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];
    const legend = (color, label) => `<span style="display:inline-flex;align-items:center;gap:5px"><span style="width:9px;height:9px;border-radius:3px;background:${color}"></span>${label}</span>`;

    return `<div class="cal">
      <div class="cal-head">
        <div class="cal-month">${MONTHS[month].charAt(0).toUpperCase() + MONTHS[month].slice(1)} ${year}</div>
        <div class="cal-nav">
          <button class="cal-navbtn" data-act="cal-prev">${icon("ChevLeft", 16)}</button>
          <button class="cal-navbtn" data-act="cal-next">${icon("ChevRight", 16)}</button>
        </div>
        <button class="cal-today" data-act="cal-today">Hoje</button>
        <div style="margin-left:auto;display:flex;gap:14px;font-size:12px;font-weight:600;color:var(--ink-3)">
          ${legend("var(--p-urgente)", "Urgente")}${legend("var(--p-alta)", "Alta")}${legend("var(--s-concluido)", "Concluído")}
        </div>
      </div>
      <div class="cal-dows">${dows.map((d) => `<div class="cal-dow">${d}</div>`).join("")}</div>
      <div class="cal-grid">${cells.map((c) => {
        const items = c.dim ? [] : tasksOn(c.y, c.m, c.day);
        return `<div class="cal-cell${c.dim ? " dim" : ""}">
          <div class="cal-daynum${isToday(c) ? " today" : ""}">${c.day}</div>
          ${items.slice(0, 3).map((t) => {
            const done = t.status === "concluido";
            const col = done ? STATUS.concluido.color : PRIORITY[t.priority].color;
            const bg = done ? STATUS.concluido.bg : PRIORITY[t.priority].bg;
            return `<div class="cal-ev" style="background:${bg};color:${col};border-left-color:${col}" data-act="open" data-id="${t.id}" title="${U.esc(t.title)}">${U.esc(t.title)}</div>`;
          }).join("")}
          ${items.length > 3 ? `<div style="font-size:10.5px;font-weight:700;color:var(--ink-3);padding-left:4px">+${items.length - 3} mais</div>` : ""}
        </div>`;
      }).join("")}</div>
    </div>`;
  }

  // ---------- CHAT ----------
  function chatHTML() {
    return window.state.messages.map(bubbleHTML).join("") +
      (window.state.typing ? `<div class="msg ai"><div class="msg-ava"><img src="${U.assistantAvatarUrl()}" alt=""></div><div class="bubble" style="padding:0"><div class="typing"><i></i><i></i><i></i></div></div></div>` : "");
  }

  function bubbleHTML(m) {
    if (m.role === "user") {
      return `<div class="msg user">${U.avatarHTML(TD.me, "msg-ava")}<div class="bubble">${U.esc(m.text)}</div></div>`;
    }
    return `<div class="msg ai">
      <div class="msg-ava"><img src="${U.assistantAvatarUrl()}" alt=""></div>
      <div class="bubble"><span>${m.text}</span>${m.card ? taskMiniHTML(m.card) : ""}${echoHTML(m.echo)}${m.action ? `<span class="b-action">${icon("Check", 13)}${U.esc(m.action)}</span>` : ""}</div>
    </div>`;
  }

  function echoHTML(echo) {
    if (!echo) return "";
    if (echo.canUndo) return `<button class="b-undo" data-act="undo">${icon("Logout", 13)} desfazer</button>`;
    if (echo.canRedo) return `<button class="b-undo" data-act="redo">${icon("Refresh", 13)} refazer</button>`;
    return "";
  }

  function taskMiniHTML(task) {
    const p = PRIORITY[task.priority];
    return `<span class="b-task" data-act="open" data-id="${task.id}" style="cursor:pointer">
      <span class="b-task-title"><span style="width:8px;height:8px;border-radius:3px;background:${p.color};flex-shrink:0"></span>${U.esc(task.title)}</span>
      <span class="b-task-meta">
        <span class="badge" style="color:${p.color};background:${p.bg}"><span class="bdot" style="background:${p.color}"></span>${p.label}</span>
        ${task.due ? `<span class="meta-pill" style="font-size:11px">${icon("Calendar", 12)}${TD.fmtDueShort(task.due)}</span>` : ""}
        <span class="card-proj">${U.esc(task.projectName || U.projectName(task.project))}</span>
      </span>
    </span>`;
  }

  function suggestHTML() {
    if (window.state.messages.length > 3) return "";
    return `<div class="chat-suggest-label">Experimente</div>
      <div class="suggest-row">${SUGGESTIONS.map((s, i) =>
        `<button class="suggest" data-act="suggest" data-i="${i}">${icon(s.icon, 13)}${U.esc(s.text.length > 32 ? s.text.slice(0, 30) + "…" : s.text)}</button>`).join("")}</div>`;
  }

  // ---------- HISTÓRICO DE CONVERSAS (drawer) ----------
  function convRowHTML(c) {
    const active = String(c.id) === String(window.state.activeConversationId);
    const when = c.updatedAt ? TD.relTime(c.updatedAt) : "";
    return `<div class="conv-item${active ? " active" : ""}">
      <button class="conv-main" data-act="conv-open" data-id="${c.id}">
        <span class="conv-ico">${icon("Comment", 15)}</span>
        <span class="conv-text">
          <span class="conv-title">${U.esc(c.title)}</span>
          <span class="conv-meta">${c.count} ${c.count === 1 ? "mensagem" : "mensagens"}${when ? " · " + when : ""}</span>
        </span>
      </button>
      <span class="conv-actions">
        <button class="conv-act" data-act="conv-rename" data-id="${c.id}" title="Renomear">${icon("Pencil", 14)}</button>
        <button class="conv-act" data-act="conv-archive" data-id="${c.id}" data-archived="${c.archived ? 1 : 0}" title="${c.archived ? "Restaurar" : "Arquivar"}">${icon(c.archived ? "Inbox" : "Archive", 14)}</button>
      </span>
    </div>`;
  }

  function conversationsHTML() {
    const convs = window.state.conversations || [];
    const active = convs.filter((c) => !c.archived);
    const archived = convs.filter((c) => c.archived);
    const section = (title, list) => list.length
      ? `<div class="conv-section-title">${title}</div>${list.map(convRowHTML).join("")}` : "";
    const empty = `<div class="conv-empty">Nenhuma conversa ainda.</div>`;
    return `
      <div class="conv-head">
        <div class="conv-head-title">${icon("History", 16)} Conversas</div>
        <button class="conv-new" data-act="new-chat">${icon("Plus", 15)} Nova</button>
        <button class="conv-close" data-act="history-close" title="Fechar">${icon("X", 16)}</button>
      </div>
      <div class="conv-list scroll">
        ${active.length || archived.length ? section("Ativas", active) + section("Arquivadas", archived) : empty}
      </div>`;
  }

  // ---------- CONFIGURAÇÕES (página completa) ----------
  const CONFIG_CATS = [
    { id: "aparencia", label: "Aparência", icon: "Sparkles" },
    { id: "geral", label: "Geral", icon: "Settings" },
    { id: "importar-tarefas", label: "Importação de Tarefas", icon: "Inbox" },
    { id: "importar-notas", label: "Importação de Notas", icon: "Inbox" },
    { id: "assistente", label: "Assistente", icon: "Comment" },
    { id: "conta", label: "Conta", icon: "User" },
  ];

  const THEME_OPTS = [
    { id: "claro", label: "Claro", sub: "Padrão", bg: "#f6f7fb", surface: "#ffffff", accent: "#4f46e5", ink: "#1c1d29", line: "#eaeaf0" },
    { id: "sepia", label: "Sépia", sub: "Claro quente", bg: "#f3ead9", surface: "#fffaf1", accent: "#c0641b", ink: "#36291a", line: "#e8dcc6" },
    { id: "oceano", label: "Oceano", sub: "Azul-petróleo", bg: "#eef4f6", surface: "#ffffff", accent: "#0e7490", ink: "#1c1d29", line: "#d9e6ea" },
    { id: "floresta", label: "Floresta", sub: "Verde", bg: "#eef4ef", surface: "#ffffff", accent: "#2f8f4e", ink: "#1c1d29", line: "#dbe8de" },
    { id: "rose", label: "Rosé", sub: "Rosa quente", bg: "#f8eef2", surface: "#ffffff", accent: "#d6336c", ink: "#1c1d29", line: "#efdce3" },
    { id: "ubuntu", label: "Ubuntu", sub: "Laranja quente", bg: "#f7f4f2", surface: "#ffffff", accent: "#dd4814", ink: "#1c1d29", line: "#e8e1db" },
    { id: "escuro", label: "Escuro", sub: "Dark mode", bg: "#15161c", surface: "#23252f", accent: "#6d63ff", ink: "#eceef5", line: "#2c2e39" },
    { id: "escuro-suave", label: "Escuro suave", sub: "Dark quente", bg: "#1b1a18", surface: "#242220", accent: "#7c74ff", ink: "#ece8e1", line: "#36332f" },
    { id: "meia-noite", label: "Meia-noite", sub: "Azul profundo", bg: "#0e1422", surface: "#161d2e", accent: "#5b8def", ink: "#e7ecf5", line: "#28324a" },
    { id: "carbono", label: "Carbono", sub: "Grafite", bg: "#131316", surface: "#1b1c20", accent: "#e06c75", ink: "#eceef2", line: "#2e3036" },
    { id: "ametista", label: "Ametista", sub: "Roxo escuro", bg: "#14111c", surface: "#1d1928", accent: "#b07ae6", ink: "#ece8f5", line: "#322c44" },
    { id: "ubuntu-escuro", label: "Ubuntu escuro", sub: "Aubergine", bg: "#2c001e", surface: "#3a1029", accent: "#ef5a28", ink: "#f3e9ef", line: "#5e2750" },
  ];

  function themeCardHTML(t, current) {
    const on = current === t.id;
    return `
      <button class="cfg-theme${on ? " on" : ""}" data-act="set-theme" data-theme="${t.id}" aria-pressed="${on}" title="${t.label}">
        <span class="cfg-theme-prev" style="background:${t.bg};border-color:${t.line}">
          <span class="cfg-theme-card" style="background:${t.surface};border-color:${t.line}">
            <span class="cfg-theme-dot" style="background:${t.accent}"></span>
            <span class="cfg-theme-lines"><i style="background:${t.ink}"></i><i style="background:${t.ink};opacity:.45"></i></span>
          </span>
        </span>
        <span class="cfg-theme-meta">
          <span class="cfg-theme-name">${t.label}</span>
          <span class="cfg-theme-sub">${t.sub}</span>
        </span>
        <span class="cfg-theme-check">${on ? icon("Check", 16) : ""}</span>
      </button>`;
  }

  function configPageHTML() {
    const prefs = window.state.prefs;
    const section = window.state.configSection || "aparencia";
    const base = window.__BASE__ || "";
    const pos = prefs.chatPosition || "side";
    const theme = THEME_OPTS.some((t) => t.id === prefs.theme) ? prefs.theme : "claro";
    const scheme = prefs.colorScheme || "";
    const customAccent = prefs.customAccent || "";
    const schemeBase = (window.ThemeColor && (window.ThemeColor.SCHEMES.find((s) => s.id === scheme) || {}).base) || "#4f46e5";
    const noteDefault = prefs.noteDefaultColor || "";
    const noteCOLORS = (window.Notes && window.Notes.COLORS) || {};
    const notePALETTE = (window.Notes && window.Notes.PALETTE) || [];
    const fontFamily = prefs.fontFamily || "";
    const fontScale = +prefs.fontScale || 1;
    const FONTS = (window.ThemeColor && window.ThemeColor.FONTS) || [];
    const opt = (id, label, sub, ic) => `
      <button class="set-opt${pos === id ? " on" : ""}" data-act="set-pos" data-pos="${id}" aria-pressed="${pos === id}">
        <span class="set-opt-ic">${icon(ic, 18)}</span>
        <span class="set-opt-tx"><span class="set-opt-label">${label}</span><span class="set-opt-sub">${sub}</span></span>
        <span class="set-opt-check">${pos === id ? icon("Check", 16) : ""}</span>
      </button>`;
    const groupOpt = (act, value, current, label, sub) => `
      <button class="set-opt${current === value ? " on" : ""}" data-act="${act}" data-value="${value}" aria-pressed="${current === value}">
        <span class="set-opt-tx"><span class="set-opt-label">${label}</span><span class="set-opt-sub">${sub}</span></span>
        <span class="set-opt-check">${current === value ? icon("Check", 16) : ""}</span>
      </button>`;
    const selectedAvatar = U.ASSISTANT_AVATARS.includes(prefs.assistantAvatar) ? prefs.assistantAvatar : "default";

    const sections = {
      "importar-tarefas": `<div class="set-block">${window.Imports ? window.Imports.sectionHTML("task") : ""}</div>`,
      "importar-notas": `<div class="set-block">${window.Imports ? window.Imports.sectionHTML("note") : ""}</div>`,
      aparencia: `
        <div class="set-block">
          <div class="set-block-label">Tema</div>
          <p class="set-hint">Escolha a aparência do aplicativo. A preferência fica salva e é aplicada nas próximas sessões.</p>
          <div class="cfg-theme-grid">${THEME_OPTS.map((t) => themeCardHTML(t, theme)).join("")}</div>
        </div>
        <div class="set-block">
          <div class="set-block-label">Esquema de cores</div>
          <p class="set-hint">Define a cor principal e todas as cores derivadas dela, dentro do tema selecionado.</p>
          <div class="cfg-scheme-grid">
            ${(window.ThemeColor ? window.ThemeColor.SCHEMES : []).map((s) => `
              <button class="cfg-scheme${scheme === s.id ? " on" : ""}" data-act="set-scheme" data-scheme="${s.id}" title="${s.name}" aria-pressed="${scheme === s.id}">
                <span class="cfg-scheme-dot" style="background:${s.base}"></span>
                <span class="cfg-scheme-name">${s.name}</span>
              </button>`).join("")}
          </div>
          <button class="set-reset" data-act="set-scheme" data-scheme="" aria-pressed="${scheme === ""}">${icon("Sparkles", 14)} Usar cores padrão do tema</button>
        </div>
        <div class="set-block">
          <div class="set-block-label">Cor principal personalizada</div>
          <p class="set-hint">Escolha manualmente a cor principal; as variações (hover, foco, ativo, etc.) são derivadas automaticamente, mantendo o contraste. Sobrepõe o esquema acima.</p>
          <div class="cfg-custom-row">
            <input type="color" class="cfg-color" value="${customAccent || schemeBase}" aria-label="Cor principal">
            <span class="cfg-custom-hex">${customAccent ? customAccent.toUpperCase() : "Automática (esquema/tema)"}</span>
            ${customAccent ? `<button class="set-reset" data-act="clear-custom-accent">Limpar</button>` : ""}
          </div>
        </div>
        <div class="set-block">
          <div class="set-block-label">Cores das notas</div>
          <p class="set-hint">Cor padrão das notas sem cor própria. "Variadas" mantém uma cor por nota; "Seguir o tema" usa a cor principal.</p>
          <div class="cfg-note-colors">
            <button class="cfg-note-opt${noteDefault === "" ? " on" : ""}" data-act="set-note-color" data-color="" title="Variadas"><span class="cfg-note-varied"></span> Variadas</button>
            <button class="cfg-note-opt${noteDefault === "accent" ? " on" : ""}" data-act="set-note-color" data-color="accent" title="Seguir o tema"><span class="cfg-note-swatch" style="background:var(--accent-soft)"></span> Seguir o tema</button>
            ${notePALETTE.map((c) => `<button class="cfg-note-swatchbtn${noteDefault === c ? " on" : ""}" data-act="set-note-color" data-color="${c}" title="${c}"><span class="cfg-note-swatch" style="background:${noteCOLORS[c]}"></span></button>`).join("")}
          </div>
        </div>
        <div class="set-block">
          <div class="set-block-label">Tipografia</div>
          <p class="set-hint">Fonte e tamanho aplicados às notas no modo de visualização (mural). Não altera a edição das notas nem o restante do sistema.</p>
          <div class="cfg-font-grid">
            ${FONTS.map((f) => `<button class="cfg-font${fontFamily === f.id ? " on" : ""}" data-act="set-font" data-font="${f.id}" style="font-family:${f.stack}">${f.name}</button>`).join("")}
          </div>
          <div class="set-sub-label">Tamanho</div>
          <div class="cfg-size-row">
            ${[["0.9", "Pequeno"], ["1", "Padrão"], ["1.08", "Grande"], ["1.16", "Maior"]].map(([v, l]) => `<button class="cfg-size${Math.abs(fontScale - (+v)) < 0.001 ? " on" : ""}" data-act="set-fontscale" data-scale="${v}">${l}</button>`).join("")}
          </div>
          <button class="set-reset" data-act="reset-typography">${icon("Sparkles", 14)} Restaurar tipografia padrão</button>
        </div>
        <div class="set-block">
          <div class="set-block-label">Posição da barra de comandos</div>
          <div class="set-options">
            ${opt("side", "Lateral", "Painel fixo à direita (padrão)", "Kanban")}
            ${opt("bottom", "Inferior", "Barra embaixo, estilo ChatGPT", "List")}
          </div>
        </div>
        <div class="set-block">
          <div class="set-block-label">Tamanho da barra</div>
          <p class="set-hint">Arraste a borda da barra de comandos para ajustar. Para voltar ao padrão:</p>
          <button class="set-reset" data-act="size-reset">${icon("Refresh", 15)} Restaurar tamanho padrão</button>
        </div>`,
      geral: `
        <div class="set-block">
          <div class="set-block-label">Expediente</div>
          <p class="set-hint">Horário usado pelo Diário de Atividades para fechar atividades em aberto no fim do dia e reabri-las no dia seguinte.</p>
          <div class="set-workday">
            <label>Início <input type="time" class="set-input set-time" data-field="workday-start" value="${U.esc(prefs.workdayStart || "09:00")}"></label>
            <label>Fim <input type="time" class="set-input set-time" data-field="workday-end" value="${U.esc(prefs.workdayEnd || "18:00")}"></label>
          </div>
        </div>
        <div class="set-block">
          <div class="set-block-label">Organização de áreas e cadernos</div>
          <p class="set-hint">Separe os itens compartilhados em grupos próprios ou exiba tudo junto, com um ícone indicando os que vieram de outra pessoa.</p>
          <div class="set-sub-label">Áreas de trabalho</div>
          <div class="set-options">
            ${groupOpt("set-ws-grouping", "merged", prefs.workspaceGrouping || "merged", "Tudo junto", "Compartilhadas com um ícone")}
            ${groupOpt("set-ws-grouping", "separated", prefs.workspaceGrouping || "merged", "Separar", "Locais e compartilhadas em grupos")}
          </div>
          <div class="set-sub-label">Cadernos (Notas)</div>
          <div class="set-options">
            ${groupOpt("set-nb-grouping", "merged", prefs.notebookGrouping || "merged", "Tudo junto", "Compartilhados com um ícone")}
            ${groupOpt("set-nb-grouping", "separated", prefs.notebookGrouping || "merged", "Separar", "Meus e compartilhados em grupos")}
          </div>
          <div class="set-sub-label">Atividades do time</div>
          <div class="set-options">
            ${groupOpt("set-team", "off", prefs.teamActivityEnabled ? "on" : "off", "Desativado", "Ver apenas minhas atividades")}
            ${groupOpt("set-team", "on", prefs.teamActivityEnabled ? "on" : "off", "Ativado", "Ver atividades por membro do time")}
          </div>
        </div>
        <div class="set-block">
          <div class="set-block-label">Registro de atividades com IA</div>
          <p class="set-hint">Usa o assistente para variar a redação dos lançamentos do diário em linguagem natural. Se a IA estiver indisponível, usa textos padrão. Pode acrescentar uma breve espera ao criar/concluir tarefas.</p>
          <div class="set-options">
            ${groupOpt("set-ai-log", "on", (prefs.aiActivityLog === false ? "off" : "on"), "Ativado", "Redação natural variada (padrão)")}
            ${groupOpt("set-ai-log", "off", (prefs.aiActivityLog === false ? "off" : "on"), "Desativado", "Textos padrão, instantâneo")}
          </div>
        </div>`,
      assistente: `
        <div class="set-block">
          <div class="set-block-label">Assistente</div>
          <p class="set-hint">Nome e avatar do assistente exibidos no chat e nos comentários de IA.</p>
          <input type="text" class="set-input" data-act="set-assistant-name" placeholder="Assistente" maxlength="40" value="${U.esc(prefs.assistantName || "")}">
          <div class="set-avatar-grid">
            ${U.ASSISTANT_AVATARS.map((id) => `
              <button class="set-avatar-opt${selectedAvatar === id ? " selected" : ""}" data-act="set-assistant-avatar" data-avatar="${id}" title="${id}">
                <img src="${base}/app/assets/avatars/${id}.svg" alt="">
              </button>`).join("")}
          </div>
        </div>`,
      conta: `
        <div class="set-block">
          <div class="set-block-label">Perfil</div>
          <p class="set-hint">Seu nome, foto e informações relevantes exibidos na barra lateral e nos comentários.</p>
          <div class="set-profile-row">
            ${U.avatarHTML(TD.me, "set-profile-ava")}
            <div>
              <button class="set-reset" data-act="profile-avatar-pick" type="button">${icon("Pencil", 14)} Alterar foto</button>
              <input type="file" class="set-avatar-file" accept="image/jpeg,image/png,image/webp" hidden>
            </div>
          </div>
          <input type="text" class="set-input" data-field="profile-name" placeholder="Seu nome" maxlength="255" value="${U.esc(TD.me.name || "")}">
          <textarea class="set-input set-textarea" data-field="profile-bio" placeholder="Bio / informações relevantes" maxlength="1000">${U.esc(TD.me.bio || "")}</textarea>
          <button class="set-reset" data-act="profile-save" type="button">${icon("Check", 15)} Salvar perfil</button>
        </div>
        <div class="set-block">
          <div class="set-block-label">Sessão</div>
          <button class="set-reset set-logout" data-act="logout" type="button">${icon("Logout", 15)} Sair da conta</button>
        </div>`,
    };

    const nav = CONFIG_CATS.map((c) => `
      <button class="cfg-nav-item${section === c.id ? " active" : ""}" data-act="cfg-nav" data-section="${c.id}">
        <span class="sb-ico">${icon(c.icon, 17)}</span>
        <span>${c.label}</span>
      </button>`).join("");

    return `
      <div class="cfg-page">
        <aside class="cfg-nav" role="tablist" aria-label="Categorias de configuração">${nav}</aside>
        <div class="cfg-content">${sections[section] || sections.aparencia}</div>
      </div>`;
  }

  // ---------- AVISOS (sino) ----------
  function notificationsHTML() {
    const list = window.state.notifications || [];
    const items = list.length
      ? list.map((n) => `
          <div class="notif-item">
            <span class="notif-ico">${icon("Bell", 15)}</span>
            <div class="notif-body">
              <div class="notif-msg">${U.esc((n.data && n.data.message) || "Aviso")}</div>
              <div class="notif-time">${TD.relTime(n.createdAt)}</div>
            </div>
            <button class="notif-read" data-act="notif-read" data-id="${n.id}" title="Marcar como lido">${icon("Check", 14)}</button>
          </div>`).join("")
      : `<div class="notif-empty">Sem avisos novos.</div>`;
    return `
      <div class="modal-overlay notif-overlay" data-act="notif-close">
        <div class="notif-panel" data-stop>
          <div class="notif-head">
            <span class="notif-head-title">${icon("Bell", 16)} Avisos</span>
            ${list.length ? `<button class="notif-readall" data-act="notif-read-all">Marcar tudo</button>` : ""}
            <button class="modal-x" data-act="notif-close">${icon("X", 16)}</button>
          </div>
          <div class="notif-list scroll">${items}</div>
        </div>
      </div>`;
  }

  window.Render = { sidebarHTML, headerHTML, updateHeader, bodyHTML, chatHTML, suggestHTML, conversationsHTML, configPageHTML, notificationsHTML };
})();
