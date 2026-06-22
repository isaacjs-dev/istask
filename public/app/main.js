/* App controller (Laravel edition).
   Mesma UI/eventos do protótipo; mutações persistem via API REST e a IA é
   processada no servidor. Acrescenta: histórico de conversas, preferências de
   layout (barra lateral/inferior + redimensionável) e responsividade mobile. */
(function () {
  const TD = window.TaskData;
  const icon = window.icon;
  const U = window.UI;
  const Api = TD.Api;

  // ---------- STATE ----------
  const boot = TD.boot || {};
  const initialOpen = (function () {
    const m = (location.hash || "").match(/open=([a-z0-9]+)/i);
    return m ? m[1] : null;
  })();

  window.state = {
    tasks: (boot.tasks || []).slice(),
    notes: (boot.notes || []).slice(),
    labels: (boot.labels || []).slice(),
    diaryEntries: (boot.diaryEntries || []).slice(),
    messages: (boot.messages && boot.messages.length ? boot.messages : TD.initialChat()).slice(),
    conversations: (boot.conversations || []).slice(),
    projects: (boot.projects || []).slice(),
    activeConversationId: boot.activeConversationId || null,
    prefs: Object.assign({ theme: "claro", chatPosition: "side", chatWidth: 372, chatHeight: 320, chatCollapsed: false, assistantName: "Assistente", assistantAvatar: "default" }, boot.prefs || {}),
    page: "tarefas",
    view: "list",
    notesView: "active",
    notesViewMode: (boot.prefs && boot.prefs.notesViewMode) || "grid",
    noteFilters: { color: null, type: null, labelId: null, hasReminder: false, shared: false },
    workspaces: (boot.workspaces || []).slice(),
    notebooks: (boot.notebooks || []).slice(),
    notifications: (boot.notifications || []).slice(),
    activeWorkspaceId: boot.activeWorkspaceId || ((boot.workspaces || [])[0] && boot.workspaces[0].id) || null,
    noteNotebook: null,
    project: "geral",
    filter: null,
    query: "",
    typing: false,
    flashId: null,
    calYear: 2026,
    calMonth: 5,
    dragId: null,
    navOpen: false,
    chatExpanded: false,
    historyOpen: false,
    configSection: "aparencia",
  };

  let els = {};

  // ---------- DERIVED ----------
  window.visibleTasks = function () {
    const s = window.state;
    let list = s.tasks.slice();
    // escopo pela Área de Trabalho ativa (pela área da própria tarefa — slugs colidem entre donos, ex.: "geral")
    if (s.activeWorkspaceId === (window.VIRTUAL_SHARED_WS || "shared-projects")) {
      // Área virtual "Projetos compartilhados": só tarefas de projetos compartilhados avulsos.
      const keys = window.sharedSoloKeys ? window.sharedSoloKeys() : new Set();
      list = list.filter((t) => keys.has(t.project + "@" + (t.workspaceId != null ? t.workspaceId : "")));
    } else if (s.activeWorkspaceId) {
      list = list.filter((t) => t.workspaceId == null || String(t.workspaceId) === String(s.activeWorkspaceId));
    }
    // arquivadas só aparecem no filtro dedicado; nas demais visões ficam ocultas
    const archivedFilter = s.filter === "arquivada";
    list = list.filter((t) => (archivedFilter ? !!t.archivedAt : !t.archivedAt));
    if (s.project !== "geral") list = list.filter((t) => t.project === s.project);
    if (s.filter && !archivedFilter) {
      if (s.filter === "atrasada") list = list.filter((t) => TD.isOverdue(t));
      else if (s.filter.startsWith("prio:")) { const p = s.filter.slice(5); list = list.filter((t) => t.priority === p && t.status !== "concluido"); }
      else list = list.filter((t) => t.status === s.filter);
    }
    if (s.query.trim()) {
      const q = s.query.toLowerCase();
      list = list.filter((t) => t.title.toLowerCase().includes(q) || (U.projectName(t.project) || "").toLowerCase().includes(q) || U.stripHtml(t.description || "").toLowerCase().includes(q));
    }
    return list;
  };

  // ---------- RENDER ----------
  function renderSidebar() { els.sidebar.innerHTML = window.Render.sidebarHTML(); }
  function renderBody() { els.body.innerHTML = window.Render.bodyHTML(); }
  function updateHeader() { window.Render.updateHeader(); }
  function renderChat() {
    els.chatScroll.innerHTML = window.Render.chatHTML();
    els.suggest.innerHTML = window.Render.suggestHTML();
    els.chatScroll.scrollTop = els.chatScroll.scrollHeight;
  }
  function renderConversations() {
    els.convDrawer.innerHTML = window.Render.conversationsHTML();
    els.convDrawer.classList.toggle("open", window.state.historyOpen);
  }
  function render() { renderSidebar(); updateHeader(); renderBody(); }
  function bindSearch() {
    if (!els.search) return;
    els.search.addEventListener("input", (e) => { window.state.query = e.target.value; renderBody(); updateHeader(); });
  }
  function renderHeader() {
    els.head.innerHTML = window.Render.headerHTML();
    els.search = document.getElementById("searchInput");
    bindSearch();
    window.Render.updateHeader();
  }

  // ---------- PREFERÊNCIAS / LAYOUT ----------
  const THEMES = ["claro", "sepia", "escuro", "escuro-suave"];
  function applyPrefs() {
    const p = window.state.prefs;
    document.documentElement.dataset.theme = THEMES.includes(p.theme) ? p.theme : "claro";
    document.body.classList.toggle("cmd-bottom", p.chatPosition === "bottom");
    document.body.classList.toggle("cmd-side", p.chatPosition !== "bottom");
    document.body.classList.toggle("chat-collapsed", !!p.chatCollapsed);
    document.body.style.setProperty("--chat-w", (p.chatWidth || 372) + "px");
    document.body.style.setProperty("--chat-h", (p.chatHeight || 320) + "px");
  }
  function savePrefs(patch) {
    window.state.prefs = Object.assign({}, window.state.prefs, patch);
    applyPrefs();
    if ("assistantName" in patch || "assistantAvatar" in patch) applyAssistantPrefs();
    Api.savePrefs(patch).then((res) => { if (res && res.prefs) window.state.prefs = Object.assign(window.state.prefs, res.prefs); }).catch(() => {});
  }

  // ---------- ÁREAS DE TRABALHO ----------
  function setActiveWorkspace(id) {
    if (!id || String(window.state.activeWorkspaceId) === String(id)) return;
    window.state.activeWorkspaceId = id;
    window.state.project = "geral";   // os projetos diferem por área
    window.state.noteNotebook = null; // reseta o filtro de caderno
    render();
    if (/^\d+$/.test(String(id))) savePrefs({ activeWorkspaceId: +id }); // área virtual não persiste
  }
  window.setActiveWorkspace = setActiveWorkspace;

  function createWorkspacePrompt() {
    window.Modals.prompt({ title: "Nova área de trabalho", label: "Nome", placeholder: "Ex.: Fiscalização", okText: "Criar", maxlength: 120 }).then((name) => {
      if (!name || !name.trim()) return;
      Api.createWorkspace(name.trim()).then((res) => {
        window.state.workspaces = res.workspaces;
        window.state.projects = res.projects;
        window.state.notebooks = res.notebooks;
        if (res.workspace) setActiveWorkspace(res.workspace.id);
        else render();
      }).catch((e) => window.App.toast((e.data && e.data.message) || "Não foi possível criar a área."));
    });
  }
  function applyAssistantPrefs() {
    const ico = document.querySelector(".chat-ai-ico");
    if (ico) ico.innerHTML = `<img src="${U.assistantAvatarUrl()}" alt="">`;
    const title = document.querySelector(".chat-title");
    if (title) {
      const live = title.querySelector(".chat-live");
      title.textContent = U.assistantName() + " ";
      if (live) title.appendChild(live);
    }
    renderChat();
  }
  function toggleChatCollapsed() {
    savePrefs({ chatCollapsed: !window.state.prefs.chatCollapsed });
  }
  function applyChatFullscreen() {
    const s = window.state;
    document.body.classList.toggle("chat-fullscreen", s.page === "tarefas" && s.view === "chat");
  }

  // ---------- HELPERS ----------
  function toast(text) {
    const div = document.createElement("div");
    div.className = "toast";
    div.innerHTML = `${icon("Check", 16)}${U.esc(text)}`;
    els.toastWrap.appendChild(div);
    setTimeout(() => { div.remove(); }, 2600);
  }
  function flash(id) {
    window.state.flashId = id;
    render();
    setTimeout(() => { if (window.state.flashId === id) { window.state.flashId = null; render(); } }, 1500);
  }
  function replaceTask(task) {
    const s = window.state;
    const i = s.tasks.findIndex((t) => String(t.id) === String(task.id));
    if (i >= 0) s.tasks[i] = task; else s.tasks.unshift(task);
  }
  function removeTask(id) { window.state.tasks = window.state.tasks.filter((t) => String(t.id) !== String(id)); }
  // move/toggle/sync devolvem { task, diaryEntries }; createTask devolve a tarefa direta.
  function applyTaskResult(res) {
    const task = res && res.task ? res.task : res;
    if (task && task.id) replaceTask(task);
    if (res && res.diaryEntries) window.state.diaryEntries = res.diaryEntries;
    if (res && res.spawnedTask && res.spawnedTask.id) { replaceTask(res.spawnedTask); toast("Tarefa recorrente recriada"); }
  }
  function apiError(e) { console.error(e); toast("Não foi possível salvar. Tente novamente."); }
  async function reloadFromServer() {
    try { const b = await Api.bootstrap(); window.state.tasks = b.tasks; render(); } catch (e) {}
  }

  // ---------- MUTATIONS (tarefas) ----------
  function toggleComplete(id) {
    const t = window.state.tasks.find((x) => String(x.id) === String(id));
    if (t) { t.status = t.status === "concluido" ? "pendente" : "concluido"; render(); }
    Api.toggleTask(id).then((res) => { applyTaskResult(res); render(); }).catch((e) => { apiError(e); reloadFromServer(); });
  }
  function moveStatus(id, status) {
    const t = window.state.tasks.find((x) => String(x.id) === String(id));
    if (t) { t.status = status; render(); }
    Api.moveTask(id, status).then((res) => { applyTaskResult(res); render(); }).catch((e) => { apiError(e); reloadFromServer(); });
  }
  function saveTask(updated) {
    const payload = {
      title: updated.title,
      description: updated.description || "",
      status: updated.status,
      priority: updated.priority,
      project: updated.project || "geral",
      due: updated.due || null,
      responsible: updated.responsible || "Você",
      section: updated.section || updated._section || null,
      startDate: updated.startDate || null,
      estimatedMinutes: (updated.estimatedMinutes === "" || updated.estimatedMinutes == null) ? null : +updated.estimatedMinutes,
      recurrence: updated.recurrence || "none",
      remindAt: updated.remindAt || null,
      labelIds: (updated.labelIds || []).map((x) => +x),
      checklist: (updated.checklist || []).map((c) => ({ id: c.id, text: c.text, done: !!c.done, assignee: c.assignee || null, priority: c.priority || null, due: c.due || null })),
      comments: (updated.comments || []).map((c) => ({ id: c.id, text: c.text, author: c.author, initials: c.initials, color: c.color, ai: !!c.ai })),
    };
    Api.saveTask(updated.id, payload).then((res) => { applyTaskResult(res); render(); toast("Tarefa atualizada"); }).catch(apiError);
  }
  function deleteTask(id) {
    removeTask(id); render();
    Api.deleteTask(id).then(() => toast("Tarefa excluída")).catch((e) => { apiError(e); reloadFromServer(); });
  }
  function addBlankTask() {
    Api.createTask({}).then((task) => { window.state.tasks = [task, ...window.state.tasks]; render(); window.Modal.open(task.id); }).catch(apiError);
  }
  function duplicateTask(id) {
    Api.duplicateTask(id).then((task) => { window.state.tasks = [task, ...window.state.tasks]; render(); flash(task.id); toast("Tarefa duplicada"); }).catch(apiError);
  }
  function archiveTask(id) {
    Api.archiveTask(id).then((task) => { replaceTask(task); render(); toast(task.archivedAt ? "Tarefa arquivada" : "Tarefa restaurada"); }).catch(apiError);
  }
  // dispara lembretes de tarefa vencidos (cria avisos no sino); só com a aba visível
  function pollTaskReminders() {
    if (document.visibilityState !== "visible") return;
    Api.tasksRemindersDue().then((res) => { if (res && res.fired && res.fired.length) pollNotifications(); }).catch(() => {});
  }

  // ---------- IA / CHAT ----------
  function sendCommand(text) {
    window.state.messages = [...window.state.messages, { id: TD.nid("u"), role: "user", text }];
    window.state.typing = true;
    renderChat();
    Api.command(text, window.state.activeConversationId).then((res) => {
      window.state.typing = false;
      window.state.tasks = res.tasks;
      const aiMsg = res.aiMessage || {};
      if (res.echo) aiMsg.echo = res.echo; // botão [desfazer]/[refazer] (efêmero)
      window.state.messages = [...window.state.messages, aiMsg];
      window.state.activeConversationId = res.conversationId;
      if (res.conversation) upsertConversation(res.conversation);
      if (res.projects) window.state.projects = res.projects;
      if (res.notes) window.state.notes = res.notes;
      if (res.diaryEntries) window.state.diaryEntries = res.diaryEntries;
      renderChat();
      if (window.state.historyOpen) renderConversations();
      const card = aiMsg.card;
      if (card && card.id) flash(card.id); else render();
      if (res.open) window.Modal.open(res.open);
      if (res.changed) toast("Atualizado pelo assistente");
    }).catch((e) => {
      console.error(e);
      window.state.typing = false;
      window.state.messages = [...window.state.messages, { id: TD.nid("ai"), role: "ai", text: "Tive um problema para processar esse comando. Pode tentar de novo?" }];
      renderChat();
    });
  }

  // ---------- CONVERSAS ----------
  function upsertConversation(conv) {
    const list = window.state.conversations.filter((c) => String(c.id) !== String(conv.id));
    window.state.conversations = [conv, ...list];
  }
  function toggleHistory(force) {
    window.state.historyOpen = force === undefined ? !window.state.historyOpen : force;
    if (window.state.historyOpen) openChatSheet();
    renderConversations();
  }
  function newConversation() {
    Api.newConversation().then((conv) => {
      upsertConversation(conv);
      window.state.activeConversationId = conv.id;
      window.state.messages = [];
      toggleHistory(false);
      renderChat();
      openChatSheet();
      if (els.chatInput) els.chatInput.focus();
    }).catch(apiError);
  }
  function switchConversation(id) {
    Api.conversationMessages(id).then((res) => {
      window.state.activeConversationId = res.conversationId;
      window.state.messages = res.messages;
      toggleHistory(false);
      renderChat();
      openChatSheet();
    }).catch(apiError);
  }
  function archiveConversation(id, archived) {
    Api.updateConversation(id, { archived }).then((conv) => {
      window.state.conversations = window.state.conversations.map((c) => String(c.id) === String(conv.id) ? conv : c);
      if (archived && String(window.state.activeConversationId) === String(id)) {
        const next = window.state.conversations.find((c) => !c.archived);
        if (next) { switchConversation(next.id); return; }
        newConversation(); return;
      }
      renderConversations();
      toast(archived ? "Conversa arquivada" : "Conversa restaurada");
    }).catch(apiError);
  }
  function renameConversation(id) {
    const cur = window.state.conversations.find((c) => String(c.id) === String(id));
    window.Modals.prompt({ title: "Renomear conversa", label: "Título", value: cur ? cur.title : "", okText: "Salvar", maxlength: 120 }).then((title) => {
      if (title === null) return;
      Api.updateConversation(id, { title: title.trim() }).then((conv) => {
        window.state.conversations = window.state.conversations.map((c) => String(c.id) === String(conv.id) ? conv : c);
        renderConversations();
      }).catch(apiError);
    });
  }

  // ---------- CONFIGURAÇÕES (página completa) ----------
  function openSettings() {
    const s = window.state;
    s.page = "config";
    s.query = "";
    renderHeader();
    render();
    applyChatFullscreen();
    closeNavMobile();
  }
  // re-renderiza só o corpo (a página de config vive nele) — preserva foco do header
  function refreshSettings() { if (window.state.page === "config") renderBody(); }

  // ---------- AVISOS (sino) ----------
  function openNotifications() { els.settingsHost.innerHTML = window.Render.notificationsHTML(); }
  function closeNotifications() { els.settingsHost.innerHTML = ""; }
  // atualiza só o badge (sem reconstruir o header → não atrapalha a busca/foco)
  function updateBell() {
    const n = (window.state.notifications || []).length;
    document.querySelectorAll(".notif-bell").forEach((b) => {
      let badge = b.querySelector(".notif-badge");
      if (n) {
        const txt = n > 9 ? "9+" : String(n);
        if (badge) badge.textContent = txt; else b.insertAdjacentHTML("beforeend", `<span class="notif-badge">${txt}</span>`);
      } else if (badge) { badge.remove(); }
    });
  }
  function markNotifRead(id) {
    Api.markNotificationRead(id).then((res) => {
      window.state.notifications = res.notifications || [];
      if (els.settingsHost.querySelector(".notif-panel")) openNotifications();
      updateBell();
    }).catch(() => {});
  }
  function markAllNotifRead() {
    Api.markAllNotificationsRead().then((res) => {
      window.state.notifications = res.notifications || [];
      closeNotifications();
      updateBell();
    }).catch(() => {});
  }
  // polling leve (só com a aba visível): traz avisos novos sem recarregar a página
  function pollNotifications() {
    if (document.visibilityState !== "visible") return;
    Api.notifications().then((res) => {
      const next = res.notifications || [];
      const cur = window.state.notifications || [];
      const changed = next.length !== cur.length || (next[0] && cur[0] && String(next[0].id) !== String(cur[0].id)) || (next.length > 0 && cur.length === 0);
      if (!changed) return;
      window.state.notifications = next;
      updateBell();
      if (els.settingsHost.querySelector(".notif-panel")) openNotifications();
    }).catch(() => {});
  }

  // ---------- PERFIL ----------
  function saveProfile() {
    const nameEl = document.querySelector('[data-field="profile-name"]');
    const bioEl = document.querySelector('[data-field="profile-bio"]');
    if (!nameEl) return;
    const name = (nameEl.value || "").trim();
    const bio = (bioEl && bioEl.value) || "";
    Api.updateProfile({ name, bio }).then((res) => {
      if (res && res.me) Object.assign(TD.me, res.me);
      render();
      toast("Perfil salvo");
    }).catch(apiError);
  }
  function uploadAvatar(file) {
    Api.uploadAvatar(file).then((res) => {
      if (res && res.avatarUrl) {
        TD.me.avatarUrl = res.avatarUrl;
        const ava = document.querySelector(".set-profile-ava");
        if (ava) ava.outerHTML = U.avatarHTML(TD.me, "set-profile-ava");
        render();
        renderChat();
      }
    }).catch(apiError);
  }

  // ---------- MOBILE / SHEET ----------
  function toggleNav(force) {
    window.state.navOpen = force === undefined ? !window.state.navOpen : force;
    document.body.classList.toggle("nav-open", window.state.navOpen);
  }
  function toggleChatSheet(force) {
    window.state.chatExpanded = force === undefined ? !window.state.chatExpanded : force;
    document.body.classList.toggle("chat-open", window.state.chatExpanded);
    if (window.state.chatExpanded) els.chatScroll.scrollTop = els.chatScroll.scrollHeight;
  }
  function openChatSheet() { if (window.matchMedia("(max-width: 900px)").matches) toggleChatSheet(true); }

  // ---------- RESIZE (barra de comandos) ----------
  function startResize(e) {
    e.preventDefault();
    const bottom = window.state.prefs.chatPosition === "bottom";
    document.body.classList.add("resizing");
    const move = (ev) => {
      const point = ev.touches ? ev.touches[0] : ev;
      if (bottom) {
        const h = Math.max(200, Math.min(720, window.innerHeight - point.clientY));
        window.state.prefs.chatHeight = Math.round(h);
        document.body.style.setProperty("--chat-h", window.state.prefs.chatHeight + "px");
      } else {
        const w = Math.max(300, Math.min(640, window.innerWidth - point.clientX));
        window.state.prefs.chatWidth = Math.round(w);
        document.body.style.setProperty("--chat-w", window.state.prefs.chatWidth + "px");
      }
    };
    const up = () => {
      document.body.classList.remove("resizing");
      document.removeEventListener("mousemove", move);
      document.removeEventListener("mouseup", up);
      document.removeEventListener("touchmove", move);
      document.removeEventListener("touchend", up);
      savePrefs(bottom ? { chatHeight: window.state.prefs.chatHeight } : { chatWidth: window.state.prefs.chatWidth });
    };
    document.addEventListener("mousemove", move);
    document.addEventListener("mouseup", up);
    document.addEventListener("touchmove", move, { passive: false });
    document.addEventListener("touchend", up);
  }

  // ---------- EVENTS ----------
  function bindEvents() {
    document.getElementById("root").addEventListener("click", (e) => {
      const el = e.target.closest("[data-act]");
      if (!el) return;
      const act = el.dataset.act, id = el.dataset.id, s = window.state;
      if (act === "project") { s.project = id; render(); closeNavMobile(); }
      else if (act === "page") { s.page = id; s.query = ""; if (id === "atividades" && window.ActivitiesPage) window.ActivitiesPage.reset(); renderHeader(); render(); applyChatFullscreen(); closeNavMobile(); }
      else if (act === "filter") { s.filter = s.filter === id ? null : id; render(); closeNavMobile(); }
      else if (act === "view") { s.view = id; render(); applyChatFullscreen(); }
      else if (act === "clear-filter") { s.filter = null; render(); }
      else if (act === "add-task") addBlankTask();
      else if (act === "toggle") {
        const t = s.tasks.find((x) => String(x.id) === String(id));
        if (t && t.permission === "view") window.App.toast("Somente leitura nesta tarefa.");
        else toggleComplete(id);
      }
      else if (act === "open") window.QuickEdit.open(id, el, e);
      else if (act === "suggest") sendCommand(window.SUGGESTIONS[+el.dataset.i].text);
      else if (act === "cal-prev") { calShift(-1); }
      else if (act === "cal-next") { calShift(1); }
      else if (act === "cal-today") { s.calYear = 2026; s.calMonth = 5; renderBody(); }
      else if (act === "logout") logout();
      else if (act === "settings") openSettings();
      else if (act === "notifications") openNotifications();
      else if (act === "notif-close") { if (el.classList.contains("modal-overlay") && e.target !== el) return; closeNotifications(); }
      else if (act === "notif-read") markNotifRead(el.dataset.id);
      else if (act === "notif-read-all") markAllNotifRead();
      else if (act === "cfg-nav") { window.state.configSection = el.dataset.section; renderBody(); }
      else if (act === "set-theme") { savePrefs({ theme: el.dataset.theme }); refreshSettings(); }
      else if (act === "set-ws-grouping") { savePrefs({ workspaceGrouping: el.dataset.value }); render(); }
      else if (act === "set-nb-grouping") { savePrefs({ notebookGrouping: el.dataset.value }); render(); }
      else if (act === "set-team") { savePrefs({ teamActivityEnabled: el.dataset.value === "on" }); render(); }
      else if (act === "set-ai-log") { savePrefs({ aiActivityLog: el.dataset.value === "on" }); refreshSettings(); }
      else if (act === "set-pos") { savePrefs({ chatPosition: el.dataset.pos }); refreshSettings(); }
      else if (act === "size-reset") { savePrefs({ chatWidth: 372, chatHeight: 320 }); toast("Tamanho restaurado"); }
      else if (act === "set-assistant-avatar") { savePrefs({ assistantAvatar: el.dataset.avatar }); refreshSettings(); }
      else if (act === "profile-avatar-pick") { const f = document.querySelector(".set-avatar-file"); if (f) f.click(); }
      else if (act === "profile-save") saveProfile();
      else if (act === "new-chat") newConversation();
      else if (act === "history") toggleHistory();
      else if (act === "history-close") toggleHistory(false);
      else if (act === "conv-open") switchConversation(id);
      else if (act === "conv-archive") archiveConversation(id, el.dataset.archived !== "1");
      else if (act === "conv-rename") renameConversation(id);
      else if (act === "nav") toggleNav();
      else if (act === "nav-close") toggleNav(false);
      else if (act === "chat-toggle") toggleChatSheet();
      else if (act === "chat-collapse-toggle") toggleChatCollapsed();
      else if (act === "new-project") window.openProjectModal({ mode: "manage" });
      else if (act === "workspace") setActiveWorkspace(id);
      else if (act === "new-workspace") createWorkspacePrompt();
      else if (act === "manage-workspaces") { if (window.openWorkspacesModal) window.openWorkspacesModal(); }
      else if (act === "undo") sendCommand("desfazer");
      else if (act === "redo") sendCommand("refazer");
    });

    // Acessibilidade: ativa por teclado (Enter/Espaço) os elementos clicáveis que
    // não são botões nativos (cards de tarefa têm tabindex/role="button").
    document.getElementById("root").addEventListener("keydown", (e) => {
      if (e.key !== "Enter" && e.key !== " ") return;
      const el = e.target.closest("[data-act]");
      if (!el || el !== e.target) return;
      const tag = el.tagName;
      if (tag === "BUTTON" || tag === "A" || tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return;
      e.preventDefault();
      el.click();
    });

    document.getElementById("root").addEventListener("change", (e) => {
      const nameEl = e.target.closest('[data-act="set-assistant-name"]');
      if (nameEl) savePrefs({ assistantName: nameEl.value.trim() });
      const wStart = e.target.closest('[data-field="workday-start"]');
      if (wStart && wStart.value) savePrefs({ workdayStart: wStart.value });
      const wEnd = e.target.closest('[data-field="workday-end"]');
      if (wEnd && wEnd.value) savePrefs({ workdayEnd: wEnd.value });
      const fileEl = e.target.closest(".set-avatar-file");
      if (fileEl && fileEl.files && fileEl.files[0]) uploadAvatar(fileEl.files[0]);
    });

    // drag & drop (kanban)
    document.addEventListener("dragstart", (e) => {
      const c = e.target.closest(".kcard"); if (!c) return;
      window.state.dragId = c.dataset.id;
      e.dataTransfer.effectAllowed = "move";
      try { e.dataTransfer.setData("text/plain", c.dataset.id); } catch (x) {}
      setTimeout(() => c.classList.add("dragging"), 0);
    });
    document.addEventListener("dragend", (e) => {
      const c = e.target.closest(".kcard"); if (c) c.classList.remove("dragging");
      document.querySelectorAll(".kcol.dragover").forEach((x) => x.classList.remove("dragover"));
      window.state.dragId = null;
    });
    document.addEventListener("dragover", (e) => { if (e.target.closest(".kcol")) e.preventDefault(); });
    document.addEventListener("dragenter", (e) => {
      const col = e.target.closest(".kcol"); if (!col) return;
      document.querySelectorAll(".kcol.dragover").forEach((x) => x.classList.remove("dragover"));
      col.classList.add("dragover");
    });
    document.addEventListener("drop", (e) => {
      const col = e.target.closest(".kcol"); if (!col || !window.state.dragId) return;
      e.preventDefault();
      const id = window.state.dragId;
      document.querySelectorAll(".kcol.dragover").forEach((x) => x.classList.remove("dragover"));
      moveStatus(id, col.dataset.col);
    });

    // resize handle
    els.chatResize.addEventListener("mousedown", startResize);
    els.chatResize.addEventListener("touchstart", startResize, { passive: false });

    // backdrop mobile (fecha sidebar)
    els.backdrop.addEventListener("click", () => toggleNav(false));

    // search
    bindSearch();

    // chat input
    const ta = els.chatInput;
    const grow = () => { ta.style.height = "auto"; ta.style.height = Math.min(ta.scrollHeight, 120) + "px"; els.chatSend.disabled = !ta.value.trim(); };
    ta.addEventListener("input", grow);
    ta.addEventListener("focus", openChatSheet);
    ta.addEventListener("keydown", (e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); doSend(); } });
    els.chatSend.addEventListener("click", doSend);
    function doSend() { const v = ta.value.trim(); if (!v) return; sendCommand(v); ta.value = ""; ta.style.height = "auto"; els.chatSend.disabled = true; }
  }

  function closeNavMobile() { if (window.state.navOpen) toggleNav(false); }

  function logout() {
    const base = window.__BASE__ || "";
    const form = document.createElement("form");
    form.method = "POST";
    form.action = base + "/logout";
    const csrf = (TD.boot && TD.boot.csrf) || "";
    form.innerHTML = `<input type="hidden" name="_token" value="${csrf}">`;
    document.body.appendChild(form);
    form.submit();
  }

  function calShift(dir) {
    const s = window.state;
    let m = s.calMonth + dir, y = s.calYear;
    if (m < 0) { m = 11; y--; } if (m > 11) { m = 0; y++; }
    s.calMonth = m; s.calYear = y; renderBody();
  }

  // ---------- INIT ----------
  function init() {
    const root = document.getElementById("root");
    root.innerHTML = `
      <div class="m-backdrop" id="mBackdrop"></div>
      <div class="app">
        <aside class="sidebar" id="sidebar"></aside>
        <main class="center">
          <div class="c-head" id="chead"></div>
          <div class="c-body scroll" id="cbody"></div>
        </main>
        <aside class="chat" id="chatPanel">
          <div class="chat-resize" id="chatResize" title="Arraste para redimensionar"></div>
          <button class="chat-grab" data-act="chat-toggle" aria-label="Expandir/recolher conversa"></button>
          <button class="chat-reopen" data-act="chat-collapse-toggle" title="Mostrar assistente" aria-label="Mostrar assistente">${icon("Sparkles", 20)}</button>
          <div class="chat-head">
            <div class="chat-ai-ico"><img src="${U.assistantAvatarUrl()}" alt=""></div>
            <div style="flex:1;min-width:0">
              <div class="chat-title">${U.esc(U.assistantName())} <span class="chat-live"><span class="pulse"></span>ativo</span></div>
              <div class="chat-sub">Controla suas tarefas por comando</div>
            </div>
            <button class="btn-ghost chat-hbtn" data-act="new-chat" title="Nova conversa">${icon("Plus", 18)}</button>
            <button class="btn-ghost chat-hbtn" data-act="history" title="Histórico de conversas">${icon("History", 18)}</button>
            <button class="btn-ghost chat-hbtn" data-act="chat-collapse-toggle" title="Recolher assistente">${icon("ChevRight", 18)}</button>
          </div>
          <div class="chat-scroll scroll" id="chatScroll"></div>
          <div class="chat-suggest" id="chatSuggest"></div>
          <div class="chat-input-wrap">
            <div class="chat-input">
              <textarea id="chatInput" rows="1" placeholder="Digite seu comando…  ex: Adiciona tarefa…"></textarea>
              <button class="chat-send" id="chatSend" disabled>${icon("Send", 17)}</button>
            </div>
          </div>
          <div class="conv-drawer" id="convDrawer"></div>
        </aside>
      </div>
      <div id="modalHost"></div>
      <div id="settingsHost"></div>
      <div class="toast-wrap" id="toastWrap"></div>`;

    document.getElementById("chead").innerHTML = window.Render.headerHTML();
    els = {
      sidebar: document.getElementById("sidebar"),
      head: document.getElementById("chead"),
      body: document.getElementById("cbody"),
      chatScroll: document.getElementById("chatScroll"),
      suggest: document.getElementById("chatSuggest"),
      convDrawer: document.getElementById("convDrawer"),
      settingsHost: document.getElementById("settingsHost"),
      toastWrap: document.getElementById("toastWrap"),
      search: document.getElementById("searchInput"),
      chatInput: document.getElementById("chatInput"),
      chatSend: document.getElementById("chatSend"),
      chatResize: document.getElementById("chatResize"),
      backdrop: document.getElementById("mBackdrop"),
    };
    applyPrefs();
    render();
    applyChatFullscreen();
    renderChat();
    renderConversations();
    bindEvents();
    setTimeout(pollNotifications, 8000);    // primeira checagem após o carregamento
    setInterval(pollNotifications, 60000);  // a cada 60s
    setTimeout(pollTaskReminders, 9000);    // lembretes de tarefa vencidos
    setInterval(pollTaskReminders, 60000);
    if (initialOpen) window.Modal.open(initialOpen);
  }

  window.App = { saveTask, deleteTask, duplicateTask, archiveTask, render, toast };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
