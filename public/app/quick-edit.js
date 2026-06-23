/* Edição rápida de tarefas (etapa 1 do fluxo de 2 etapas).
   Clicar numa tarefa abre este painel contextual (popover amplo ancorado ao
   card — não um modal apertado nem uma página nova) com os campos mais usados
   e salvamento automático. "Mais opções" abre a edição completa (window.Modal),
   preservando as alterações já feitas aqui. window.QuickEdit.open(id, anchorEl) */
(function () {
  const TD = window.TaskData;
  const { PRIORITY, STATUS, fmtDue } = TD;
  const Api = TD.Api;
  const icon = window.icon;
  const U = window.UI;

  const STATUS_OPTS = ["pendente", "andamento", "aguardando", "concluido", "cancelado"];
  const PRIO_OPTS = ["urgente", "alta", "media", "baixa"];

  let draft = null, host = null, anchor = null, saveTimer = null, dirty = false, readonly = false, clickPt = null;

  function ensureHost() {
    host = document.getElementById("quickHost");
    if (!host) { host = document.createElement("div"); host.id = "quickHost"; document.body.appendChild(host); }
    return host;
  }

  // Cascata Área→Projeto: o select de Projeto lista só os projetos da Área escolhida.
  function wsOptions() {
    const list = ((window.state && window.state.workspaces) || []).map((w) => [String(w.id), w.name]);
    if (((window.state && window.state.projects) || []).some((p) => p.sharedSolo)) list.push(["__shared__", "Projetos compartilhados"]);
    return list.length ? list : [[String(window.state.activeWorkspaceId || ""), "Pessoal"]];
  }
  function projOptionsFor(wsId) {
    const all = (window.state && window.state.projects) || [];
    const list = (String(wsId) === "__shared__"
      ? all.filter((p) => p.sharedSolo)
      : all.filter((p) => String(p.workspaceId) === String(wsId) && !p.sharedSolo)
    ).map((p) => [p.slug, p.name]);
    return list.length ? list : [["geral", "Geral"]];
  }
  function draftWsId(d) {
    const all = (window.state && window.state.projects) || [];
    const p = all.find((x) => x.slug === d.project);
    if (p) return p.sharedSolo ? "__shared__" : String(p.workspaceId);
    if (d.workspaceId != null) return String(d.workspaceId);
    return String(window.state.activeWorkspaceId || (wsOptions()[0] || [""])[0]);
  }
  function selectHTML(cls, value, opts) {
    return `<div class="select-wrap"><select class="${cls}"${readonly ? " disabled" : ""}>${opts.map(([v, l]) => `<option value="${v}"${v === value ? " selected" : ""}>${U.esc(l)}</option>`).join("")}</select><span class="chev">${icon("ChevDown", 16)}</span></div>`;
  }

  function open(id, anchorEl, ev) {
    const task = window.state.tasks.find((t) => String(t.id) === String(id));
    if (!task) return;
    draft = JSON.parse(JSON.stringify(task));
    readonly = draft.permission === "view";
    anchor = anchorEl || null;
    // ponto do clique (mouse) — abre o popover ao lado do cursor; teclado/IA caem no card
    clickPt = (ev && (ev.clientX || ev.clientY)) ? { x: ev.clientX, y: ev.clientY } : null;
    dirty = false;
    ensureHost();
    host.innerHTML = shellHTML();
    position();
    bind();
    const ti = host.querySelector(".qe-title");
    if (ti && !readonly) { ti.focus(); ti.setSelectionRange(ti.value.length, ti.value.length); }
  }

  function close(flush) {
    if (flush !== false) flushSave();
    if (host) host.innerHTML = "";
    draft = null; anchor = null; clickPt = null; dirty = false;
    document.removeEventListener("keydown", onKey);
  }

  function shellHTML() {
    if (readonly) return readonlyHTML();
    const done = draft.status === "concluido";
    const accent = PRIORITY[draft.priority] ? PRIORITY[draft.priority].color : "var(--accent)";
    const cl = draft.checklist || [];
    const clDone = cl.filter((c) => c.done).length;
    const people = U.taskPeople(draft.project);
    const wsId = draftWsId(draft);
    return `
      <div class="qe-backdrop" data-qe="close"></div>
      <div class="qe-pop" role="dialog" aria-label="Edição rápida da tarefa">
        <div class="qe-bar" style="background:${done ? "var(--s-concluido)" : accent}"></div>
        <div class="qe-head">
          <button class="qe-check${done ? " on" : ""}" data-qe="complete" title="${done ? "Reabrir" : "Concluir"}" aria-pressed="${done}">${icon("CheckSmall", 14)}</button>
          <input class="qe-title" value="${U.esc(draft.title)}" placeholder="Título da tarefa" aria-label="Título" />
          <button class="qe-x" data-qe="close" aria-label="Fechar">${icon("X", 16)}</button>
        </div>
        <div class="qe-incat">na lista <b>${U.esc(U.sectionTitle(draft))}</b></div>
        <div class="qe-grid">
          <div class="fld"><div class="fld-label">Status</div>${selectHTML("qe-status", draft.status, STATUS_OPTS.map((s) => [s, STATUS[s].label]))}</div>
          <div class="fld"><div class="fld-label">Prioridade</div>${selectHTML("qe-prio", draft.priority, PRIO_OPTS.map((p) => [p, PRIORITY[p].label]))}</div>
          <div class="fld"><div class="fld-label">Data de entrega</div><input type="date" class="qe-due" value="${draft.due || ""}" aria-label="Data de entrega" /></div>
          <div class="fld"><div class="fld-label">Área</div>${selectHTML("qe-ws", wsId, wsOptions())}</div>
          <div class="fld"><div class="fld-label">Projeto</div>${selectHTML("qe-project", draft.project, projOptionsFor(wsId))}</div>
          <div class="fld qe-col2"><div class="fld-label">Responsável</div><div class="resp-row"><span class="resp-ava-wrap qe-resp-ava-wrap">${U.respAvatarHTML(draft.responsible, people, "resp-ava qe-resp-ava")}</span><input class="txt qe-resp" list="resp-people-qe" value="${U.esc(draft.responsible || "")}" placeholder="Nome (ou externo)…" aria-label="Responsável" /></div>${U.peopleDatalistHTML("resp-people-qe", people)}</div>
        </div>
        ${(draft.labels && draft.labels.length) ? `<div class="qe-labels">${U.labelChips(draft.labels)}</div>` : ""}
        ${cl.length ? `<div class="qe-cl"><span class="mini-prog"><span class="bar"><i style="width:${cl.length ? Math.round((clDone / cl.length) * 100) : 0}%"></i></span>${clDone}/${cl.length} no checklist</span></div>` : ""}
        <div class="qe-foot">
          <button class="qe-more" data-qe="more">${icon("List", 15)} Mais opções</button>
          <span class="qe-status-label" data-qe-status></span>
          <button class="qe-del" data-qe="delete" title="Excluir tarefa" aria-label="Excluir tarefa">${icon("Trash", 15)}</button>
        </div>
      </div>`;
  }

  function readonlyHTML() {
    return `
      <div class="qe-backdrop" data-qe="close"></div>
      <div class="qe-pop" role="dialog" aria-label="Tarefa (somente leitura)">
        <div class="qe-bar"></div>
        <div class="qe-head">
          <div class="qe-title qe-title-ro">${U.esc(draft.title)}</div>
          <button class="qe-x" data-qe="close" aria-label="Fechar">${icon("X", 16)}</button>
        </div>
        <div class="qe-incat qe-ro-tag">Somente leitura${draft.ownerName ? ` · de ${U.esc(draft.ownerName)}` : ""}</div>
        <div class="qe-ro-badges">
          ${U.statusBadge(draft.status)} ${U.priorityBadge(draft.priority)}
        </div>
        <div class="qe-ro-meta">
          ${draft.due ? `<span>${icon("Calendar", 14)} ${fmtDue(draft.due)}</span>` : ""}
          <span>${icon("Folder", 14)} ${U.esc(U.projectName(draft.project))}</span>
          ${draft.responsible ? `<span>${icon("User", 14)} ${U.esc(draft.responsible)}</span>` : ""}
        </div>
        <div class="qe-foot">
          <button class="qe-more" data-qe="more">${icon("List", 15)} Ver detalhes</button>
        </div>
      </div>`;
  }

  function position() {
    const pop = host.querySelector(".qe-pop");
    if (!pop) return;
    if (window.matchMedia("(max-width: 640px)").matches) return; // mobile: bottom-sheet via CSS
    const w = pop.offsetWidth || 380, h = pop.offsetHeight || 320, gap = 12, m = 12;
    let left, top;
    if (clickPt) {
      // ao lado do cursor (à direita; vira para a esquerda se faltar espaço), clampado à tela
      left = clickPt.x + gap;
      if (left + w > window.innerWidth - m) left = clickPt.x - w - gap;
      left = Math.max(m, Math.min(left, window.innerWidth - w - m));
      top = clickPt.y - 24; // título logo acima do ponto clicado
      top = Math.max(m, Math.min(top, window.innerHeight - h - m));
    } else if (anchor) {
      const r = anchor.getBoundingClientRect();
      left = r.right + gap;
      if (left + w > window.innerWidth - m) left = r.left - w - gap;
      if (left < m) left = Math.max(m, (window.innerWidth - w) / 2);
      top = Math.max(m, Math.min(r.top, window.innerHeight - h - m));
    } else {
      left = Math.max(m, (window.innerWidth - w) / 2);
      top = Math.max(m, (window.innerHeight - h) / 2);
    }
    pop.style.left = left + "px";
    pop.style.top = top + "px";
  }

  // --------- persistência (autosave) ---------
  function setSaveLabel(state) {
    const el = host && host.querySelector("[data-qe-status]");
    if (!el) return;
    if (state === "saving") el.innerHTML = `${icon("Refresh", 12)} Salvando…`;
    else if (state === "saved") el.innerHTML = `${icon("Check", 12)} Salvo`;
    else if (state === "error") el.innerHTML = `<span class="qe-err">Erro ao salvar</span>`;
    else el.innerHTML = "";
    el.className = "qe-status-label" + (state === "saved" ? " ok" : state === "error" ? " bad" : "");
  }
  function applyToState() {
    if (!draft) return;
    const i = window.state.tasks.findIndex((t) => String(t.id) === String(draft.id));
    if (i >= 0) {
      window.state.tasks[i] = Object.assign({}, window.state.tasks[i], {
        title: draft.title, status: draft.status, priority: draft.priority,
        project: draft.project, due: draft.due, responsible: draft.responsible,
      });
    }
  }
  function buildPayload(d) {
    return {
      title: d.title, description: d.description || "", status: d.status, priority: d.priority,
      project: d.project || "geral", due: d.due || null, responsible: d.responsible || "Você",
      section: d.section || d._section || null,
      checklist: (d.checklist || []).map((c) => ({ id: c.id, text: c.text, done: !!c.done })),
      comments: (d.comments || []).map((c) => ({ id: c.id, text: c.text, author: c.author, initials: c.initials, color: c.color, ai: !!c.ai })),
    };
  }
  function scheduleSave() {
    dirty = true; setSaveLabel("dirty");
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flushSave, 600);
  }
  function saveNow() { dirty = true; clearTimeout(saveTimer); flushSave(); }
  function flushSave() {
    clearTimeout(saveTimer);
    if (!dirty || !draft || readonly) return;
    dirty = false;
    const d = draft, payload = buildPayload(d);
    applyToState();
    setSaveLabel("saving");
    Api.saveTask(d.id, payload).then((res) => {
      const task = res && res.task ? res.task : res;
      if (task && task.id) {
        const i = window.state.tasks.findIndex((t) => String(t.id) === String(task.id));
        if (i >= 0) window.state.tasks[i] = task;
      }
      if (res && res.diaryEntries) window.state.diaryEntries = res.diaryEntries;
      setSaveLabel("saved");
      if (window.App) window.App.render();
    }).catch((e) => {
      console.error(e);
      setSaveLabel("error");
      if (window.App) window.App.toast("Não foi possível salvar a tarefa.");
    });
  }

  function reflectComplete() {
    const done = draft.status === "concluido";
    const accent = PRIORITY[draft.priority] ? PRIORITY[draft.priority].color : "var(--accent)";
    const bar = host.querySelector(".qe-bar"); if (bar) bar.style.background = done ? "var(--s-concluido)" : accent;
    const chk = host.querySelector(".qe-check"); if (chk) { chk.classList.toggle("on", done); chk.setAttribute("aria-pressed", String(done)); }
    const sel = host.querySelector(".qe-status"); if (sel) sel.value = draft.status;
  }

  function moreOptions() {
    applyToState();
    flushSave();
    const id = draft.id;
    close(false);
    if (window.Modal) window.Modal.open(id);
  }

  // Handler ÚNICO e estável: addEventListener com a mesma referência é deduplicado
  // pelo navegador, então reabrir o popover não acumula listeners no #quickHost.
  function onHostClick(e) {
    if (!draft) return;
    const el = e.target.closest("[data-qe]");
    if (!el) return;
    const act = el.dataset.qe;
    if (act === "close") { if (el.classList.contains("qe-backdrop") && e.target !== el) return; close(); }
    else if (act === "more") moreOptions();
    else if (act === "delete") { const id = draft.id; close(false); if (window.App) window.App.deleteTask(id); }
    else if (act === "complete") { draft.status = draft.status === "concluido" ? "pendente" : "concluido"; reflectComplete(); saveNow(); }
  }

  function bind() {
    host.addEventListener("click", onHostClick);
    if (readonly) { document.addEventListener("keydown", onKey); return; }

    const title = host.querySelector(".qe-title");
    if (title) title.addEventListener("input", (e) => { draft.title = e.target.value; scheduleSave(); });
    const status = host.querySelector(".qe-status");
    if (status) status.addEventListener("change", (e) => { draft.status = e.target.value; reflectComplete(); saveNow(); });
    const prio = host.querySelector(".qe-prio");
    if (prio) prio.addEventListener("change", (e) => { draft.priority = e.target.value; reflectComplete(); saveNow(); });
    const due = host.querySelector(".qe-due");
    if (due) due.addEventListener("change", (e) => { draft.due = e.target.value || null; saveNow(); });
    function refreshRespAvatar() {
      const wrap = host.querySelector(".qe-resp-ava-wrap");
      if (wrap) wrap.innerHTML = U.respAvatarHTML(draft.responsible, U.taskPeople(draft.project), "resp-ava qe-resp-ava");
    }
    function refreshRespDl() {
      const dl = host.querySelector("#resp-people-qe");
      if (dl) dl.innerHTML = U.taskPeople(draft.project).map((p) => `<option value="${U.esc(p.name)}"></option>`).join("");
    }
    const wsSel = host.querySelector(".qe-ws");
    const project = host.querySelector(".qe-project");
    if (wsSel) wsSel.addEventListener("change", (e) => {
      const opts = projOptionsFor(e.target.value);
      const first = (opts[0] || [])[0] || "";
      if (project) project.innerHTML = opts.map(([v, l]) => `<option value="${v}"${v === first ? " selected" : ""}>${U.esc(l)}</option>`).join("");
      draft.project = first;
      const p = (window.state.projects || []).find((x) => x.slug === first); if (p) draft.workspaceId = p.workspaceId;
      refreshRespDl(); refreshRespAvatar(); saveNow();
    });
    if (project) project.addEventListener("change", (e) => {
      draft.project = e.target.value;
      const p = (window.state.projects || []).find((x) => x.slug === draft.project); if (p) draft.workspaceId = p.workspaceId;
      refreshRespDl(); refreshRespAvatar(); saveNow();
    });
    const resp = host.querySelector(".qe-resp");
    if (resp) resp.addEventListener("input", (e) => {
      draft.responsible = e.target.value;
      refreshRespAvatar();
      scheduleSave();
    });
    // Enter no título salva e fecha
    if (title) title.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); close(); } });
    document.addEventListener("keydown", onKey);
    window.addEventListener("resize", position);
  }

  function onKey(e) { if (e.key === "Escape" && host && host.innerHTML) close(); }

  window.QuickEdit = { open, close };
})();
