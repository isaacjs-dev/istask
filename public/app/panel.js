/* Painel de acompanhamento de projetos + páginas dedicadas de edição de Área/Projeto (Fase 2).
   window.Panel.{panelHTML,wsEditHTML,projEditHTML,headerHTML}. Navegação por state.page
   (panel | edit-ws | edit-proj). Métricas calculadas no cliente a partir de state.tasks.
   Regra única de % de conclusão: concluídas ÷ total × 100 (total 0 → 0%). */
(function () {
  const U = window.UI;
  const TD = window.TaskData;
  const Api = TD.Api;
  const icon = window.icon;
  const PRIORITY = TD.PRIORITY;
  const S = () => window.state;

  const PROJ_STATUS = {
    nao_iniciado: { label: "Não iniciado", color: "var(--ink-3)" },
    em_andamento: { label: "Em andamento", color: "var(--s-andamento)" },
    concluido:    { label: "Concluído", color: "var(--s-concluido)" },
    pausado:      { label: "Pausado", color: "var(--s-aguardando)" },
  };
  const WS_STATUS = {
    ativo:      { label: "Ativa" },
    pausado:    { label: "Pausada" },
    concluido:  { label: "Concluída" },
    arquivado:  { label: "Arquivada" },
  };

  function render() { window.App.render(); }
  function startOfToday() { const d = new Date(); d.setHours(0, 0, 0, 0); return d; }
  function fmtDate(s) {
    if (!s) return "—";
    const d = new Date(s + "T00:00:00");
    return isNaN(d.getTime()) ? "—" : d.toLocaleDateString("pt-BR");
  }
  function workspaces() { return (S().workspaces || []); }
  function projectsOf(wsId) { return (S().projects || []).filter((p) => String(p.workspaceId) === String(wsId) && !p.sharedSolo); }
  function panelWsId() {
    const ws = workspaces();
    const cur = S().panelWs;
    if (cur && ws.some((w) => String(w.id) === String(cur))) return String(cur);
    if (S().activeWorkspaceId && ws.some((w) => String(w.id) === String(S().activeWorkspaceId))) return String(S().activeWorkspaceId);
    return ws.length ? String(ws[0].id) : null;
  }

  // ---------- métricas (cliente) ----------
  function taskOverdue(t) { return t.due && t.status !== "concluido" && new Date(t.due + "T00:00:00") < startOfToday(); }
  function projMetrics(slug) {
    const tasks = (S().tasks || []).filter((t) => t.project === slug);
    const total = tasks.length;
    const done = tasks.filter((t) => t.status === "concluido").length;
    const overdue = tasks.filter(taskOverdue).length;
    const pct = total ? Math.round((done / total) * 100) : 0; // regra única
    return { total, done, pending: total - done, overdue, pct };
  }
  function projOverdue(p) { return p.dueDate && p.status !== "concluido" && new Date(p.dueDate + "T00:00:00") < startOfToday(); }
  function wsSummary(wsId) {
    const projs = projectsOf(wsId);
    const byStatus = { nao_iniciado: 0, em_andamento: 0, concluido: 0, pausado: 0 };
    let tTotal = 0, tDone = 0, tOverdue = 0, pOverdue = 0;
    projs.forEach((p) => {
      const m = projMetrics(p.slug);
      tTotal += m.total; tDone += m.done; tOverdue += m.overdue;
      byStatus[p.status || "nao_iniciado"] = (byStatus[p.status || "nao_iniciado"] || 0) + 1;
      if (projOverdue(p)) pOverdue++;
    });
    return { projects: projs.length, byStatus, pOverdue, tTotal, tDone, tPending: tTotal - tDone, tOverdue, pct: tTotal ? Math.round((tDone / tTotal) * 100) : 0 };
  }

  // ---------- header ----------
  function headerHTML() {
    const p = S().page;
    const titles = { panel: "Painel de Projetos", "edit-ws": "Editar área de trabalho", "edit-proj": "Editar projeto" };
    const subs = { panel: "Acompanhamento por área de trabalho", "edit-ws": "Detalhes e configurações da área", "edit-proj": "Detalhes e configurações do projeto" };
    const back = p !== "panel" ? `<button class="btn-ghost" data-pan="back">${icon("ChevLeft", 16)} Voltar</button>` : "";
    return `
      <div class="c-head-top">
        <button class="c-menu" data-act="nav" title="Menu" aria-label="Abrir menu">${icon("Menu", 20)}</button>
        <div class="c-title-wrap">
          <div class="c-bread"><span class="c-bread-proj">${subs[p] || ""}</span></div>
          <h1 class="c-title">${titles[p] || "Painel"}</h1>
        </div>
        <div class="c-actions">${back}</div>
      </div>`;
  }

  // ---------- painel ----------
  function barHTML(pct, cls) { return `<div class="pan-bar ${cls || ""}"><i style="width:${pct}%"></i></div>`; }
  function projCardHTML(p) {
    const m = projMetrics(p.slug);
    const st = PROJ_STATUS[p.status] || PROJ_STATUS.nao_iniciado;
    const overdue = projOverdue(p);
    const owner = p.ownerName || (TD.me && TD.me.name) || "";
    return `
      <div class="pan-card${overdue ? " overdue" : ""}">
        <div class="pan-card-head">
          <div>
            <div class="pan-card-title">${icon("Folder", 15)} ${U.esc(p.name)}</div>
            ${p.description ? `<div class="pan-card-desc">${U.esc(U.stripHtml(p.description)).slice(0, 140)}</div>` : ""}
          </div>
          <button class="btn-ghost pan-edit" data-pan="edit-proj" data-id="${p.id}" title="Editar projeto">${icon("Pencil", 14)}</button>
        </div>
        <div class="pan-card-meta">
          <span class="pan-status" style="--st:${st.color}">${st.label}</span>
          ${owner ? `<span>${icon("User", 13)} ${U.esc(owner)}</span>` : ""}
          <span>${icon("Calendar", 13)} ${fmtDate(p.startDate)} → ${fmtDate(p.dueDate)}${overdue ? ' <strong class="pan-late">atrasado</strong>' : ""}</span>
        </div>
        <div class="pan-progress">
          ${barHTML(m.pct, m.pct >= 100 ? "done" : "")}
          <span class="pan-pct">${m.pct}%</span>
        </div>
        <div class="pan-counts">
          <span title="Total">${m.total} tarefas</span>
          <span class="ok" title="Concluídas">${m.done} concluídas</span>
          <span title="Pendentes">${m.pending} pendentes</span>
          <span class="late" title="Atrasadas">${m.overdue} atrasadas</span>
        </div>
      </div>`;
  }
  function panelHTML() {
    const ws = workspaces();
    if (!ws.length) return `<div class="pan-empty">${icon("Kanban", 28)}<p>Crie uma área de trabalho para acompanhar projetos.</p></div>`;
    const wsId = panelWsId();
    const cur = ws.find((w) => String(w.id) === String(wsId));
    const sum = wsSummary(wsId);
    const projs = projectsOf(wsId);
    const wsSel = `<div class="select-wrap pan-ws"><select data-pan-ws>${ws.map((w) => `<option value="${w.id}"${String(w.id) === String(wsId) ? " selected" : ""}>${U.esc(w.name)}</option>`).join("")}</select><span class="chev">${icon("ChevDown", 16)}</span></div>`;
    const stat = (n, l, cls) => `<div class="pan-stat ${cls || ""}"><b>${n}</b><span>${l}</span></div>`;
    return `
      <div class="pan">
        <div class="pan-top">
          ${wsSel}
          ${cur && cur.isOwner ? `<button class="btn-ghost" data-pan="edit-ws" data-id="${wsId}">${icon("Pencil", 14)} Editar área</button>` : ""}
          <span class="pan-spacer"></span>
          <div class="pan-overall"><span>Conclusão geral</span>${barHTML(sum.pct)}<b>${sum.pct}%</b></div>
        </div>
        <div class="pan-summary">
          ${stat(sum.projects, "Projetos")}
          ${stat(sum.byStatus.nao_iniciado, "Não iniciados")}
          ${stat(sum.byStatus.em_andamento, "Em andamento", "ok")}
          ${stat(sum.byStatus.concluido, "Concluídos", "ok")}
          ${stat(sum.pOverdue, "Atrasados", sum.pOverdue ? "late" : "")}
          ${stat(sum.tTotal, "Tarefas")}
          ${stat(sum.tDone, "Concluídas", "ok")}
          ${stat(sum.tPending, "Pendentes")}
          ${stat(sum.tOverdue, "Tarefas atrasadas", sum.tOverdue ? "late" : "")}
        </div>
        <div class="pan-cards">
          ${projs.length ? projs.map(projCardHTML).join("") : `<div class="pan-empty"><p>Nenhum projeto nesta área ainda.</p></div>`}
        </div>
      </div>`;
  }

  // ---------- páginas de edição ----------
  function field(label, inner) { return `<label class="pan-f"><span>${label}</span>${inner}</label>`; }
  function selectField(label, value, opts, fieldName, ro) {
    return field(label, `<div class="select-wrap"><select data-pan-field="${fieldName}"${ro ? " disabled" : ""}>${opts.map(([v, l]) => `<option value="${v}"${String(v) === String(value) ? " selected" : ""}>${U.esc(l)}</option>`).join("")}</select><span class="chev">${icon("ChevDown", 15)}</span></div>`);
  }
  function ensureDraft(kind, ent) {
    const d = S().panelDraft;
    if (d && d.kind === kind && String(d.id) === String(ent.id)) return d;
    if (kind === "ws") {
      S().panelDraft = { kind, id: ent.id, name: ent.name || "", description: ent.description || "", startDate: ent.startDate || "", endDate: ent.endDate || "", status: ent.status || "ativo" };
    } else {
      S().panelDraft = { kind, id: ent.id, name: ent.name || "", description: ent.description || "", startDate: ent.startDate || "", dueDate: ent.dueDate || "", completedAt: ent.completedAt || "", status: ent.status || "nao_iniciado", priority: ent.priority || "media" };
    }
    return S().panelDraft;
  }
  function wsEditHTML() {
    const ws = workspaces().find((w) => String(w.id) === String(S().editWorkspaceId));
    if (!ws) return `<div class="pan-empty"><p>Área não encontrada.</p></div>`;
    const ro = !ws.isOwner;
    const d = ensureDraft("ws", ws);
    const dis = ro ? " disabled" : "";
    return `
      <div class="pan-edit">
        ${ro ? `<div class="pan-ro">Somente leitura — apenas o dono da área pode editar.</div>` : ""}
        ${field("Nome", `<input class="pan-in" data-pan-field="name" value="${U.esc(d.name)}"${dis}>`)}
        ${field("Descrição", `<textarea class="pan-in" rows="3" data-pan-field="description"${dis}>${U.esc(d.description)}</textarea>`)}
        <div class="pan-grid">
          ${field("Data de início", `<input type="date" class="pan-in" data-pan-field="startDate" value="${U.esc(d.startDate)}"${dis}>`)}
          ${field("Data de término", `<input type="date" class="pan-in" data-pan-field="endDate" value="${U.esc(d.endDate)}"${dis}>`)}
          ${selectField("Status", d.status, Object.keys(WS_STATUS).map((k) => [k, WS_STATUS[k].label]), "status", ro)}
        </div>
        <div class="pan-actions">
          ${ws.isOwner ? `<button class="btn-ghost" data-pan="share-ws" data-id="${ws.id}">${icon("User", 14)} Participantes</button>` : ""}
          <span class="pan-spacer"></span>
          <button class="btn-ghost" data-pan="back">Cancelar</button>
          ${ro ? "" : `<button class="note-btn-save" data-pan="save-ws" data-id="${ws.id}">${icon("Check", 14)} Salvar</button>`}
        </div>
      </div>`;
  }
  function projEditHTML() {
    const p = (S().projects || []).find((x) => String(x.id) === String(S().editProjectId));
    if (!p) return `<div class="pan-empty"><p>Projeto não encontrado.</p></div>`;
    const ro = !p.isOwner;
    const d = ensureDraft("proj", p);
    const dis = ro ? " disabled" : "";
    const m = projMetrics(p.slug);
    const wsOpts = workspaces().map((w) => [String(w.id), w.name]);
    return `
      <div class="pan-edit">
        ${ro ? `<div class="pan-ro">Somente leitura — apenas o dono do projeto pode editar.</div>` : ""}
        <div class="pan-edit-prog"><span>Conclusão</span>${barHTML(m.pct, m.pct >= 100 ? "done" : "")}<b>${m.pct}%</b> <span class="pan-edit-counts">${m.done}/${m.total} tarefas · ${m.overdue} atrasadas</span></div>
        ${field("Nome", `<input class="pan-in" data-pan-field="name" value="${U.esc(d.name)}"${dis}>`)}
        ${field("Descrição", `<textarea class="pan-in" rows="3" data-pan-field="description"${dis}>${U.esc(d.description)}</textarea>`)}
        <div class="pan-grid">
          ${selectField("Área de trabalho", String(p.workspaceId), wsOpts, "__move__", ro)}
          ${selectField("Status", d.status, Object.keys(PROJ_STATUS).map((k) => [k, PROJ_STATUS[k].label]), "status", ro)}
          ${selectField("Prioridade", d.priority, ["urgente", "alta", "media", "baixa"].map((k) => [k, PRIORITY[k].label]), "priority", ro)}
          ${field("Data de início", `<input type="date" class="pan-in" data-pan-field="startDate" value="${U.esc(d.startDate)}"${dis}>`)}
          ${field("Previsão de término", `<input type="date" class="pan-in" data-pan-field="dueDate" value="${U.esc(d.dueDate)}"${dis}>`)}
          ${field("Conclusão efetiva", `<input type="date" class="pan-in" data-pan-field="completedAt" value="${U.esc(d.completedAt)}"${dis}>`)}
        </div>
        <div class="pan-actions">
          ${p.isOwner ? `<button class="btn-ghost" data-pan="share-proj" data-id="${p.id}">${icon("User", 14)} Participantes</button>` : ""}
          <span class="pan-spacer"></span>
          <button class="btn-ghost" data-pan="back">Cancelar</button>
          ${ro ? "" : `<button class="note-btn-save" data-pan="save-proj" data-id="${p.id}">${icon("Check", 14)} Salvar</button>`}
        </div>
      </div>`;
  }

  // ---------- navegação + persistência ----------
  function go(page, extra) { Object.assign(S(), extra || {}); S().panelDraft = null; S().page = page; render(); }

  document.addEventListener("change", (e) => {
    const t = e.target;
    if (t.matches && t.matches("[data-pan-ws]")) { S().panelWs = t.value; render(); return; }
    if (t.dataset && t.dataset.panField !== undefined) { setField(t, true); }
  });
  document.addEventListener("input", (e) => {
    const t = e.target;
    if (t.dataset && t.dataset.panField !== undefined && (t.tagName === "INPUT" && t.type !== "date" || t.tagName === "TEXTAREA")) setField(t, false);
  });
  document.addEventListener("click", (e) => {
    const el = e.target.closest("[data-pan]");
    if (!el) return;
    const act = el.dataset.pan; const id = el.dataset.id;
    if (act === "back") { go("panel"); }
    else if (act === "edit-ws") { go("edit-ws", { editWorkspaceId: id }); }
    else if (act === "edit-proj") { go("edit-proj", { editProjectId: id }); }
    else if (act === "share-ws") { if (window.openShareModal) window.openShareModal("workspace", id); }
    else if (act === "share-proj") { if (window.openShareModal) window.openShareModal("project", id); }
    else if (act === "save-ws") { saveWs(id); }
    else if (act === "save-proj") { saveProj(id); }
  });

  function setField(t, rerender) {
    const d = S().panelDraft; if (!d) return;
    const f = t.dataset.panField;
    if (f === "__move__") { moveProject(d.id, t.value); return; }
    d[f] = t.value;
    if (f === "status" && d.kind === "proj" && t.value === "concluido" && !d.completedAt) {
      d.completedAt = new Date().toISOString().slice(0, 10);
    }
    if (rerender) render();
  }
  function moveProject(id, wsId) {
    Api.moveProject(id, wsId).then((res) => { window.App.applyPayload(res); window.App.toast("Projeto movido"); })
      .catch(() => window.App.toast("Não foi possível mover o projeto."));
  }
  function saveWs(id) {
    const d = S().panelDraft; if (!d) return;
    Api.updateWorkspace(id, { name: d.name.trim(), description: d.description, startDate: d.startDate || null, endDate: d.endDate || null, status: d.status })
      .then((res) => { window.App.applyPayload(res); window.App.toast("Área salva"); go("panel"); })
      .catch((err) => window.App.toast((err.data && err.data.message) || "Não foi possível salvar a área."));
  }
  function saveProj(id) {
    const d = S().panelDraft; if (!d) return;
    Api.updateProject(id, { name: d.name.trim(), description: d.description, startDate: d.startDate || null, dueDate: d.dueDate || null, completedAt: d.completedAt || null, status: d.status, priority: d.priority })
      .then((res) => { window.App.applyPayload(res); window.App.toast("Projeto salvo"); go("panel"); })
      .catch((err) => window.App.toast((err.data && err.data.message) || "Não foi possível salvar o projeto."));
  }

  window.Panel = { panelHTML, wsEditHTML, projEditHTML, headerHTML };
})();
