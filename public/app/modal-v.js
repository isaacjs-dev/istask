/* Vanilla Trello-style task modal. window.Modal.open(id) / close() */
(function () {
  const TD = window.TaskData;
  const { PRIORITY, STATUS, relTime, nid } = TD;
  const Api = TD.Api;
  const icon = window.icon;
  const U = window.UI;

  const STATUS_OPTS = ["pendente", "andamento", "aguardando", "concluido", "cancelado"];
  const PRIO_OPTS = ["urgente", "alta", "media", "baixa"];

  let draft = null, host = null, initialDesc = "", initialSnapshot = "", editingCommentId = null, expandedStepId = null;
  const PRIO_LABELS = { urgente: "Urgente", alta: "Alta", media: "Média", baixa: "Baixa" };

  // serializa os campos editáveis para detectar alterações não salvas
  function snapshot() {
    if (!host) return "";
    const rte = host.querySelector(".rte");
    if (rte) draft.description = rte.innerHTML;
    return JSON.stringify({
      title: draft.title, description: draft.description, status: draft.status, priority: draft.priority,
      project: draft.project, due: draft.due, responsible: draft.responsible,
      startDate: draft.startDate, estimatedMinutes: draft.estimatedMinutes, recurrence: draft.recurrence, remindAt: draft.remindAt,
      labelIds: (draft.labelIds || []).slice().sort(),
      checklist: draft.checklist,
      // comentários e anexos persistem de imediato (não entram no "não salvo")
    });
  }
  function isDirty() { return !!host && snapshot() !== initialSnapshot; }
  function closeGuarded() {
    if (!isDirty()) { close(); return; }
    window.Modals.confirm({ title: "Descartar alterações?", message: "Você fez alterações que ainda não foram salvas. Deseja descartá-las?", okText: "Descartar", cancelText: "Continuar editando", danger: true })
      .then((ok) => { if (ok) close(); });
  }

  // Cascata Área→Projeto: o select de Projeto lista só os projetos da Área escolhida.
  function wsOptions() {
    const list = ((window.state && window.state.workspaces) || []).map((w) => [String(w.id), U.esc(w.name)]);
    if (((window.state && window.state.projects) || []).some((p) => p.sharedSolo)) list.push(["__shared__", "Projetos compartilhados"]);
    return list;
  }
  function projOptionsFor(wsId) {
    const all = (window.state && window.state.projects) || [];
    const list = (String(wsId) === "__shared__"
      ? all.filter((p) => p.sharedSolo)
      : all.filter((p) => String(p.workspaceId) === String(wsId) && !p.sharedSolo)
    ).map((p) => [p.slug, U.esc(p.name)]);
    list.push(["__new__", "+ Criar novo projeto…"]);
    return list;
  }
  function draftWsId(d) {
    const all = (window.state && window.state.projects) || [];
    const p = all.find((x) => x.slug === d.project);
    if (p) return p.sharedSolo ? "__shared__" : String(p.workspaceId);
    if (d.workspaceId != null) return String(d.workspaceId);
    if (window.state.activeWorkspaceId != null) return String(window.state.activeWorkspaceId);
    return (wsOptions()[0] || [""])[0];
  }

  function open(id) {
    const task = window.state.tasks.find((t) => t.id === id);
    if (!task) return;
    draft = JSON.parse(JSON.stringify(task));
    initialDesc = draft.description || "";
    editingCommentId = null;
    expandedStepId = null;
    host = document.getElementById("modalHost");
    host.innerHTML = shellHTML();
    const rte = host.querySelector(".rte");
    rte.innerHTML = draft.description || "";
    bind();
    renderChecklist();
    renderComments();
    renderAttachments();
    renderLabels();
    renderRelations();
    initialSnapshot = snapshot();
  }

  // --------- Etiquetas ---------
  function renderLabels() {
    const wrap = host.querySelector(".m-labels");
    if (!wrap) return;
    const chips = (draft.labels || []).map((l) =>
      `<span class="m-label-chip">${icon("Tag", 11)} ${U.esc(l.name)} <button class="m-label-x" data-m="label-remove" data-id="${l.id}" title="Remover">${icon("X", 11)}</button></span>`).join("");
    wrap.innerHTML = `${chips}<button class="m-label-add" data-m="label-menu" type="button">${icon("Plus", 13)} Etiqueta</button><div class="m-label-menu" hidden></div>`;
  }
  function showLabelMenu() {
    const menu = host.querySelector(".m-label-menu");
    if (!menu) return;
    const labels = window.state.labels || [];
    const selected = new Set((draft.labelIds || []).map(String));
    menu.innerHTML = `
      ${labels.length ? labels.map((l) => `<button class="m-label-opt${selected.has(String(l.id)) ? " on" : ""}" data-m="label-toggle" data-id="${l.id}" data-name="${U.esc(l.name)}" type="button">${icon("Tag", 13)} <span>${U.esc(l.name)}</span> ${selected.has(String(l.id)) ? icon("Check", 13) : ""}</button>`).join("") : `<div class="m-label-empty">Nenhuma etiqueta ainda.</div>`}
      <div class="m-label-new"><input class="m-label-new-input" placeholder="Nova etiqueta…" maxlength="40"><button data-m="label-create" type="button">${icon("Plus", 14)}</button></div>`;
    menu.hidden = false;
    const inp = menu.querySelector(".m-label-new-input");
    if (inp) { inp.focus(); inp.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); createLabel(); } }); }
  }
  function toggleLabelMenu() {
    const menu = host.querySelector(".m-label-menu");
    if (!menu) return;
    if (menu.hidden) showLabelMenu(); else menu.hidden = true;
  }
  function toggleLabel(id, name) {
    draft.labelIds = draft.labelIds || []; draft.labels = draft.labels || [];
    const sid = String(id);
    if (draft.labelIds.map(String).includes(sid)) {
      draft.labelIds = draft.labelIds.filter((x) => String(x) !== sid);
      draft.labels = draft.labels.filter((l) => String(l.id) !== sid);
    } else {
      draft.labelIds.push(id); draft.labels.push({ id: sid, name });
    }
    renderLabels(); showLabelMenu();
  }
  function removeLabel(id) {
    const sid = String(id);
    draft.labelIds = (draft.labelIds || []).filter((x) => String(x) !== sid);
    draft.labels = (draft.labels || []).filter((l) => String(l.id) !== sid);
    renderLabels();
  }
  function createLabel() {
    const inp = host.querySelector(".m-label-new-input");
    const name = (inp && inp.value || "").trim();
    if (!name) return;
    Api.createLabel(name).then((res) => {
      if (res && res.labels) window.state.labels = res.labels;
      const lbl = res && res.label;
      if (lbl && lbl.id) { draft.labelIds = draft.labelIds || []; draft.labels = draft.labels || []; draft.labelIds.push(lbl.id); draft.labels.push({ id: String(lbl.id), name: lbl.name }); }
      renderLabels(); showLabelMenu();
    }).catch((e) => window.App.toast((e.data && e.data.message) || "Não foi possível criar a etiqueta."));
  }

  function close() { if (host) host.innerHTML = ""; draft = null; document.removeEventListener("keydown", onKey); }

  function selectHTML(value, opts, cls) {
    return `<div class="select-wrap"><select${cls ? ` class="${cls}"` : ""}>${opts.map(([v, l]) => `<option value="${v}"${v === value ? " selected" : ""}>${l}</option>`).join("")}</select><span class="chev">${icon("ChevDown", 16)}</span></div>`;
  }

  /** ISO → valor de <input type="datetime-local"> (YYYY-MM-DDTHH:mm). */
  function localDT(iso) {
    if (!iso) return "";
    const d = new Date(iso);
    if (isNaN(d.getTime())) return "";
    const p = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
  }

  function shellHTML() {
    const done = draft.status === "concluido";
    const accent = PRIORITY[draft.priority] ? PRIORITY[draft.priority].color : "var(--accent)";
    const people = U.taskPeople(draft.project);
    const wsId = draftWsId(draft);
    return `
    <div class="modal-overlay">
      <div class="modal">
        <div class="modal-cover" style="background:${done ? STATUS.concluido.color : accent}"></div>
        <div class="modal-head">
          <button class="modal-headcheck${done ? " checked" : ""}" data-m="complete" title="${done ? "Reabrir" : "Concluir"}">${icon("CheckSmall", 15)}</button>
          <div class="modal-title-wrap">
            <input class="modal-title-input" value="${U.esc(draft.title)}" />
            <div class="modal-incat">na lista <b>${U.esc(U.sectionTitle(draft))}</b> · <span class="m-cat-label">${U.esc(U.projectName(draft.project))}</span></div>
          </div>
          <button class="modal-x" data-m="close">${icon("X", 18)}</button>
        </div>
        <div class="modal-body">
          <div class="modal-main scroll">
            <div class="m-block">
              <div class="m-label">${icon("List", 15)} Descrição <span class="rte-dirty" style="display:none">• não salvo</span></div>
              <div>
                <div class="rte-toolbar">
                  <button class="rte-btn" data-cmd="bold" title="Negrito">${icon("Bold", 16)}</button>
                  <button class="rte-btn" data-cmd="italic" title="Itálico">${icon("Italic", 16)}</button>
                  <button class="rte-btn" data-cmd="underline" title="Sublinhado">${icon("Underline", 16)}</button>
                  <button class="rte-btn" data-cmd="strikeThrough" title="Tachado">${icon("Strike", 16)}</button>
                  <span class="rte-sep"></span>
                  <button class="rte-btn" data-cmd="formatBlock" data-val="h3" title="Título">H</button>
                  <button class="rte-btn" data-cmd="insertUnorderedList" title="Lista">${icon("ListUl", 16)}</button>
                  <button class="rte-btn" data-cmd="insertOrderedList" title="Lista numerada">${icon("ListOl", 16)}</button>
                </div>
                <div class="rte" contenteditable="true" data-ph="Adicione uma descrição mais detalhada…"></div>
              </div>
            </div>
            <div class="m-block">
              <div class="m-label">${icon("Tag", 15)} Etiquetas</div>
              <div class="m-labels"></div>
            </div>
            <div class="m-block">
              <div class="m-label">${icon("Checklist", 15)} Checklist <span class="count cl-count"></span></div>
              <div class="cl-wrap"></div>
              <div class="cl-add">
                <input class="cl-input" placeholder="Adicionar item…" />
                <button data-m="cl-add">Adicionar</button>
              </div>
            </div>
            <div class="m-block">
              <div class="m-label">${icon("Comment", 15)} Comentários <span class="count cm-count"></span></div>
              <div class="cm-add">
                ${U.avatarHTML(TD.me, "cm-ava")}
                <div style="flex:1">
                  <textarea class="cm-input" placeholder="Escreva um comentário…"></textarea>
                  <div class="cm-add-btn" style="display:none;margin-top:8px"><button class="cl-add-btn" data-m="cm-add" style="padding:7px 13px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:12.5px;font-weight:700">Comentar</button></div>
                </div>
              </div>
              <div class="cm-list" style="margin-top:14px"></div>
            </div>
            <div class="m-block">
              <div class="m-label">${icon("Folder", 15)} Anexos <span class="count m-att-count"></span></div>
              <div class="m-att-list"></div>
              <div class="m-att-add">
                <button class="m-att-pick" data-m="att-pick" type="button">${icon("Plus", 14)} Anexar arquivo</button>
                <input type="file" class="m-att-file" hidden>
              </div>
            </div>
            <div class="m-block">
              <div class="m-label">${icon("Merge", 15)} Relacionamentos</div>
              <div class="m-rel"></div>
            </div>
          </div>
          <div class="modal-side scroll">
            <div class="fld"><div class="fld-label">Status</div>${selectHTML(draft.status, STATUS_OPTS.map((s) => [s, STATUS[s].label]))}</div>
            <div class="fld"><div class="fld-label">Prioridade</div>${selectHTML(draft.priority, PRIO_OPTS.map((p) => [p, PRIORITY[p].label]))}</div>
            <div class="fld"><div class="fld-label">Data de entrega</div><input type="date" class="m-due" value="${draft.due || ""}" /></div>
            <div class="fld"><div class="fld-label">Área de trabalho</div>${selectHTML(wsId, wsOptions(), "m-ws")}</div>
            <div class="fld"><div class="fld-label">Projeto</div>${selectHTML(draft.project, projOptionsFor(wsId), "m-proj")}</div>
            <div class="fld"><div class="fld-label">Responsável</div><div class="resp-row"><span class="resp-ava-wrap">${U.respAvatarHTML(draft.responsible, people, "resp-ava")}</span><input class="txt m-resp" list="resp-people-modal" value="${U.esc(draft.responsible || "")}" placeholder="Nome (ou responsável externo)…" /></div>${U.peopleDatalistHTML("resp-people-modal", people)}</div>
            <div style="border-top:1px solid var(--line);margin:8px 0 14px"></div>
            <div class="fld-label">${icon("Calendar", 13, 'style="vertical-align:-2px;margin-right:4px"')} Planejamento</div>
            <div class="fld"><div class="fld-label">Data de início</div><input type="date" class="m-start" value="${draft.startDate || ""}" /></div>
            <div class="fld"><div class="fld-label">Duração estimada (min)</div><input class="txt m-est" type="number" min="0" step="5" value="${draft.estimatedMinutes != null ? draft.estimatedMinutes : ""}" placeholder="—" /></div>
            <div class="fld"><div class="fld-label">Recorrência</div>${selectHTML(draft.recurrence || "none", [["none", "Não repete"], ["daily", "Diária"], ["weekly", "Semanal"], ["monthly", "Mensal"]], "m-recur")}</div>
            <div class="fld"><div class="fld-label">Lembrete</div><input type="datetime-local" class="m-remind txt" value="${localDT(draft.remindAt)}" /></div>
            <div style="border-top:1px solid var(--line);margin:8px 0 14px"></div>
            <div class="fld-label">${icon("Dots", 13, 'style="vertical-align:-2px;margin-right:4px"')} Ações</div>
            <div class="m-actions">
              <button class="m-action" data-m="duplicate">${icon("Plus", 14)} Duplicar</button>
              <button class="m-action" data-m="archive">${icon("Archive", 14)} ${draft.archivedAt ? "Restaurar" : "Arquivar"}</button>
            </div>
            <div style="border-top:1px solid var(--line);margin:14px 0 14px"></div>
            <div class="fld-label">${icon("History", 13, 'style="vertical-align:-2px;margin-right:4px"')} Histórico</div>
            <div class="hist">${histHTML()}</div>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn-save" data-m="save">Salvar alterações</button>
          <button class="btn-cancel" data-m="close">Cancelar</button>
          <button class="btn-del-task" data-m="delete" title="Excluir tarefa">${icon("Trash", 17)}</button>
          <button class="btn-complete${draft.status === "concluido" ? " is-done" : ""}" data-m="complete">${icon("Check", 16)} ${draft.status === "concluido" ? "Reabrir tarefa" : "Marcar como concluída"}</button>
        </div>
      </div>
    </div>`;
  }

  function histHTML() {
    return [...(draft.history || [])].reverse().map((h) =>
      `<div class="hist-item"><span class="hist-dot"${h.by === "IA" ? ' style="border-color:#7c3aed"' : ""}></span>
        <div class="hist-text"><b>${U.esc(h.by === "IA" ? "Assistente" : h.by)}</b> ${h.action}</div>
        <div class="hist-time">${relTime(h.at)}</div></div>`).join("");
  }

  function renderChecklist() {
    const wrap = host.querySelector(".cl-wrap");
    const done = draft.checklist.filter((c) => c.done).length;
    const pct = draft.checklist.length ? Math.round((done / draft.checklist.length) * 100) : 0;
    host.querySelector(".cl-count").textContent = draft.checklist.length ? `· ${done}/${draft.checklist.length}` : "";
    const people = U.taskPeople(draft.project);
    wrap.innerHTML =
      (draft.checklist.length ? `<div class="cl-prog"><div class="cl-bar"><i style="width:${pct}%"></i></div><span class="cl-pct">${pct}%</span></div>` : "") +
      draft.checklist.map((c) => {
        const meta = [];
        if (c.assignee) meta.push(`<span class="cl-chip">${U.respAvatarHTML(c.assignee, people, "cl-ava")} ${U.esc(c.assignee)}</span>`);
        if (c.due) meta.push(`<span class="cl-chip">${icon("Calendar", 11)} ${TD.fmtDueShort(c.due)}</span>`);
        if (c.priority) meta.push(`<span class="cl-chip" style="color:${(PRIORITY[c.priority] || {}).color || "var(--ink-2)"}">${icon("Flag", 11)} ${PRIO_LABELS[c.priority] || c.priority}</span>`);
        const expanded = String(expandedStepId) === String(c.id);
        return `<div class="cl-item${c.done ? " on" : ""}">
        <button class="cl-box${c.done ? " on" : ""}" data-m="cl-toggle" data-id="${c.id}">${icon("CheckSmall", 12)}</button>
        <span class="cl-text">${U.esc(c.text)}</span>
        ${meta.length ? `<span class="cl-meta">${meta.join("")}</span>` : ""}
        <button class="cl-more${expanded ? " on" : ""}" data-m="cl-details" data-id="${c.id}" title="Detalhes do item">${icon("ChevDown", 14)}</button>
        <button class="cl-del" data-m="cl-del" data-id="${c.id}">${icon("Trash", 14)}</button>
      </div>${expanded ? `<div class="cl-detail">
        <label>Responsável <input class="cl-f-assignee" list="resp-people-modal" data-id="${c.id}" value="${U.esc(c.assignee || "")}" placeholder="Nome ou externo…"></label>
        <label>Data <input type="date" class="cl-f-due" data-id="${c.id}" value="${c.due || ""}"></label>
        <label>Prioridade <select class="cl-f-prio" data-id="${c.id}"><option value="">—</option>${["urgente", "alta", "media", "baixa"].map((p) => `<option value="${p}"${c.priority === p ? " selected" : ""}>${PRIO_LABELS[p]}</option>`).join("")}</select></label>
      </div>` : ""}`;
      }).join("");
    bindStepDetailFields();
  }
  function bindStepDetailFields() {
    const find = (id) => draft.checklist.find((x) => String(x.id) === String(id));
    host.querySelectorAll(".cl-f-assignee").forEach((el) => el.addEventListener("input", () => { const c = find(el.dataset.id); if (c) c.assignee = el.value.trim() || null; }));
    host.querySelectorAll(".cl-f-due").forEach((el) => el.addEventListener("change", () => { const c = find(el.dataset.id); if (c) c.due = el.value || null; }));
    host.querySelectorAll(".cl-f-prio").forEach((el) => el.addEventListener("change", () => { const c = find(el.dataset.id); if (c) c.priority = el.value || null; }));
  }

  function renderComments() {
    host.querySelector(".cm-count").textContent = draft.comments.length ? "· " + draft.comments.length : "";
    host.querySelector(".cm-list").innerHTML = [...draft.comments].reverse().map((c) => {
      const isAi = c.ai || c.author === "IA";
      const ava = isAi
        ? `<div class="cm-ava"><img src="${U.assistantAvatarUrl()}" alt=""></div>`
        : (c.avatarUrl ? `<div class="cm-ava"><img src="${U.esc(c.avatarUrl)}" alt=""></div>` : `<div class="cm-ava" style="background:${c.color}">${c.initials}</div>`);
      const editing = String(editingCommentId) === String(c.id);
      const body = editing
        ? `<div class="cm-edit"><textarea class="cm-edit-input">${U.esc(c.text)}</textarea>
             <div class="cm-edit-actions">
               <button class="cm-edit-cancel" data-m="cm-edit-cancel">Cancelar</button>
               <button class="cm-edit-save" data-m="cm-edit-save" data-id="${c.id}">Salvar</button>
             </div></div>`
        : `<div class="cm-text${c.ai ? " ai" : ""}">${U.esc(c.text)}</div>`;
      const actions = (c.mine && !editing)
        ? `<span class="cm-actions"><button class="cm-act" data-m="cm-edit" data-id="${c.id}" title="Editar">${icon("Pencil", 13)}</button><button class="cm-act" data-m="cm-del" data-id="${c.id}" title="Excluir">${icon("Trash", 13)}</button></span>`
        : "";
      return `<div class="cm">${ava}
        <div class="cm-body">
          <div class="cm-head"><span class="cm-name">${U.esc(isAi ? U.assistantName() : c.author)}${c.ai ? ` <span style="color:var(--accent);font-size:11px;margin-left:5px">✦ ${U.esc(U.assistantName())}</span>` : ""}</span><span class="cm-time">${relTime(c.at)}</span>${actions}</div>
          ${body}
        </div></div>`;
    }).join("");
  }
  function syncStateComments() {
    const t = window.state.tasks.find((x) => String(x.id) === String(draft.id));
    if (t) t.comments = draft.comments;
  }
  function saveCommentEdit(id) {
    const ta = host.querySelector(".cm-edit-input");
    const text = (ta && ta.value || "").trim();
    if (!text) return;
    Api.updateTaskComment(draft.id, id, text).then((task) => {
      draft.comments = task.comments || draft.comments;
      editingCommentId = null;
      syncStateComments(); renderComments();
    }).catch(() => window.App.toast("Não foi possível editar o comentário."));
  }
  function deleteComment(id) {
    window.Modals.confirm({ title: "Excluir comentário", message: "Excluir este comentário?", okText: "Excluir", danger: true }).then((ok) => {
      if (!ok) return;
      Api.deleteTaskComment(draft.id, id).then((task) => {
        draft.comments = task.comments || [];
        syncStateComments(); renderComments();
      }).catch(() => window.App.toast("Não foi possível excluir o comentário."));
    });
  }

  let uploadingPct = null;
  function renderAttachments() {
    const list = host.querySelector(".m-att-list");
    const atts = draft.attachments || [];
    host.querySelector(".m-att-count").textContent = atts.length ? "· " + atts.length : "";
    const uploading = uploadingPct !== null
      ? `<div class="m-att-uploading"><span class="m-att-up-label">${icon("Folder", 14)} Enviando…</span><div class="m-att-up-bar"><i style="width:${uploadingPct}%"></i></div><span class="m-att-up-pct">${uploadingPct}%</span></div>`
      : "";
    list.innerHTML = uploading + (atts.length
      ? atts.map((a) => `<div class="m-att">
          <a class="m-att-link" href="${U.esc(a.url)}" target="_blank" rel="noopener">${icon("Folder", 14)} ${U.esc(a.name)}</a>
          <button class="m-att-del" data-m="att-del" data-id="${a.id}" title="Remover">${icon("Trash", 13)}</button>
        </div>`).join("")
      : (uploadingPct !== null ? "" : `<div class="m-att-empty">Nenhum anexo nesta tarefa.</div>`));
  }
  function syncStateAttachments() {
    const t = window.state.tasks.find((x) => String(x.id) === String(draft.id));
    if (t) t.attachments = draft.attachments;
  }
  function uploadAtt(file) {
    uploadingPct = 0; renderAttachments();
    Api.uploadAttachment("task", draft.id, file, null, (pct) => {
      uploadingPct = pct;
      const bar = host && host.querySelector(".m-att-up-bar i");
      if (bar) bar.style.width = pct + "%";
      const lbl = host && host.querySelector(".m-att-up-pct");
      if (lbl) lbl.textContent = pct + "%";
    }).then((res) => {
      uploadingPct = null;
      draft.attachments = [res.attachment, ...(draft.attachments || [])];
      syncStateAttachments(); renderAttachments();
    }).catch((e) => {
      uploadingPct = null; renderAttachments();
      window.App.toast((e && e.data && e.data.message) || "Não foi possível anexar o arquivo.");
    });
  }
  function deleteAtt(attId) {
    Api.deleteAttachment(attId).then(() => {
      draft.attachments = (draft.attachments || []).filter((a) => String(a.id) !== String(attId));
      syncStateAttachments(); renderAttachments();
    }).catch(() => window.App.toast("Não foi possível remover o anexo."));
  }

  const REL_TYPES = { relacionada: "Relacionada", bloqueia: "Bloqueia", depende: "Depende de" };
  function renderRelations() {
    const el = host.querySelector(".m-rel"); if (!el) return;
    const links = draft.links || [], rels = draft.relations || [];
    const others = (window.state.tasks || []).filter((t) => String(t.id) !== String(draft.id) && !t.archivedAt);
    el.innerHTML = `
      <div class="m-rel-group">
        <div class="m-rel-sub">Links externos</div>
        <div class="m-rel-list">${links.length ? links.map((l) => `<div class="m-rel-row"><a class="m-rel-link" href="${U.esc(l.url)}" target="_blank" rel="noopener">${icon("Merge", 13)} ${U.esc(l.label)}</a><button class="m-rel-del" data-m="link-del" data-id="${l.id}" title="Remover">${icon("Trash", 13)}</button></div>`).join("") : `<div class="m-rel-empty">Nenhum link.</div>`}</div>
        <div class="m-rel-add"><input class="m-link-url" placeholder="https://…"><button class="m-rel-addbtn" data-m="link-add" type="button">Adicionar</button></div>
      </div>
      <div class="m-rel-group">
        <div class="m-rel-sub">Tarefas relacionadas</div>
        <div class="m-rel-list">${rels.length ? rels.map((r) => `<div class="m-rel-row"><span class="m-rel-type">${REL_TYPES[r.type] || r.type}</span><button class="m-rel-open" data-m="rel-open" data-id="${r.relatedId}">${U.esc(r.title || "(tarefa)")}</button><button class="m-rel-del" data-m="rel-del" data-id="${r.id}" title="Remover">${icon("Trash", 13)}</button></div>`).join("") : `<div class="m-rel-empty">Nenhuma tarefa relacionada.</div>`}</div>
        ${others.length ? `<div class="m-rel-add">
          <div class="select-wrap"><select class="m-rel-type-sel">${Object.entries(REL_TYPES).map(([v, l]) => `<option value="${v}">${l}</option>`).join("")}</select><span class="chev">${icon("ChevDown", 16)}</span></div>
          <div class="select-wrap"><select class="m-rel-task-sel">${others.map((t) => `<option value="${t.id}">${U.esc(t.title)}</option>`).join("")}</select><span class="chev">${icon("ChevDown", 16)}</span></div>
          <button class="m-rel-addbtn" data-m="rel-add" type="button">Adicionar</button>
        </div>` : `<div class="m-rel-empty">Nenhuma outra tarefa para relacionar.</div>`}
      </div>`;
  }
  function applyRelResult(task) {
    draft.links = task.links || []; draft.relations = task.relations || [];
    const t = window.state.tasks.find((x) => String(x.id) === String(draft.id));
    if (t) { t.links = draft.links; t.relations = draft.relations; }
    renderRelations();
  }
  function addLink() {
    const inp = host.querySelector(".m-link-url");
    const url = (inp && inp.value || "").trim(); if (!url) return;
    Api.addTaskLink(draft.id, url).then(applyRelResult).catch(() => window.App.toast("Não foi possível adicionar o link."));
  }
  function addRelation() {
    const type = host.querySelector(".m-rel-type-sel").value;
    const rid = host.querySelector(".m-rel-task-sel").value;
    if (!rid) return;
    Api.addTaskRelation(draft.id, rid, type).then(applyRelResult).catch((e) => window.App.toast((e && e.data && e.data.message) || "Não foi possível relacionar."));
  }

  function setCover() {
    const done = draft.status === "concluido";
    const accent = PRIORITY[draft.priority] ? PRIORITY[draft.priority].color : "var(--accent)";
    host.querySelector(".modal-cover").style.background = done ? STATUS.concluido.color : accent;
    const hc = host.querySelector(".modal-headcheck");
    hc.classList.toggle("checked", done);
    const cbtn = host.querySelector(".btn-complete");
    cbtn.classList.toggle("is-done", done);
    cbtn.innerHTML = `${icon("Check", 16)} ${done ? "Reabrir tarefa" : "Marcar como concluída"}`;
  }

  function bind() {
    const overlay = host.querySelector(".modal-overlay");
    overlay.addEventListener("mousedown", (e) => { if (e.target === overlay) closeGuarded(); });

    // title
    host.querySelector(".modal-title-input").addEventListener("input", (e) => { draft.title = e.target.value; });

    // RTE toolbar
    const rte = host.querySelector(".rte");
    host.querySelectorAll(".rte-btn").forEach((b) => {
      b.addEventListener("mousedown", (e) => {
        e.preventDefault();
        document.execCommand(b.dataset.cmd, false, b.dataset.val || null);
        rte.focus();
        markDirty();
      });
    });
    rte.addEventListener("input", markDirty);
    rte.addEventListener("blur", markDirty);

    // responsável (combobox: sugere pessoas-com-acesso + permite externo)
    function refreshRespAvatar() {
      const wrap = host.querySelector(".resp-ava-wrap");
      if (wrap) wrap.innerHTML = U.respAvatarHTML(draft.responsible, U.taskPeople(draft.project), "resp-ava");
    }
    function refreshRespDatalist() {
      const dl = host.querySelector("#resp-people-modal");
      if (dl) dl.innerHTML = U.taskPeople(draft.project).map((p) => `<option value="${U.esc(p.name)}"></option>`).join("");
    }

    // selects: status/prioridade pela ordem; área/projeto por classe (cascata)
    const sels = host.querySelectorAll(".modal-side select");
    sels[0].addEventListener("change", (e) => { draft.status = e.target.value; draft.completedAt = e.target.value === "concluido" ? TD.nowISO() : null; setCover(); });
    sels[1].addEventListener("change", (e) => { draft.priority = e.target.value; setCover(); });
    const wsSel = host.querySelector(".m-ws");
    const projSel = host.querySelector(".m-proj");
    function fillProjects(ws, selectSlug) {
      const opts = projOptionsFor(ws);
      projSel.innerHTML = opts.map(([v, l]) => `<option value="${v}"${v === selectSlug ? " selected" : ""}>${l}</option>`).join("");
    }
    function pickProject(slug) {
      draft.project = slug;
      const p = (window.state.projects || []).find((x) => x.slug === slug);
      if (p) draft.workspaceId = p.workspaceId;
      host.querySelector(".m-cat-label").textContent = U.projectName(draft.project);
      refreshRespDatalist(); refreshRespAvatar();
    }
    wsSel.addEventListener("change", (e) => {
      const first = projOptionsFor(e.target.value).find(([v]) => v !== "__new__");
      fillProjects(e.target.value, first ? first[0] : "");
      pickProject(first ? first[0] : "");
    });
    projSel.addEventListener("change", (e) => {
      if (e.target.value === "__new__") {
        e.target.value = draft.project || "";
        window.openProjectModal({
          mode: "create",
          onCreated: (proj) => {
            const ws = proj.workspaceId != null ? String(proj.workspaceId) : wsSel.value;
            if (wsOptions().some(([v]) => v === ws)) wsSel.value = ws;
            fillProjects(wsSel.value, proj.slug);
            pickProject(proj.slug);
          },
        });
        return;
      }
      pickProject(e.target.value);
    });
    host.querySelector(".m-due").addEventListener("change", (e) => { draft.due = e.target.value; });
    host.querySelector(".m-resp").addEventListener("input", (e) => { draft.responsible = e.target.value; refreshRespAvatar(); });

    // planejamento (datas avançadas / recorrência / lembrete)
    host.querySelector(".m-start").addEventListener("change", (e) => { draft.startDate = e.target.value || null; });
    host.querySelector(".m-est").addEventListener("input", (e) => { const v = e.target.value; draft.estimatedMinutes = v === "" ? null : Math.max(0, parseInt(v, 10) || 0); });
    host.querySelector(".m-recur").addEventListener("change", (e) => { draft.recurrence = e.target.value; });
    host.querySelector(".m-remind").addEventListener("change", (e) => { draft.remindAt = e.target.value || null; });

    // checklist add
    const clInput = host.querySelector(".cl-input");
    clInput.addEventListener("keydown", (e) => { if (e.key === "Enter") addChk(); });

    // comment box show/hide button
    const cmInput = host.querySelector(".cm-input");
    cmInput.addEventListener("input", () => { host.querySelector(".cm-add-btn").style.display = cmInput.value.trim() ? "block" : "none"; });
    cmInput.addEventListener("keydown", (e) => { if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) addCmt(); });

    // anexos
    const attFile = host.querySelector(".m-att-file");
    attFile.addEventListener("change", () => { if (attFile.files && attFile.files[0]) { uploadAtt(attFile.files[0]); attFile.value = ""; } });

    // delegated modal actions
    overlay.addEventListener("click", (e) => {
      const el = e.target.closest("[data-m]");
      if (!el) return;
      const act = el.dataset.m;
      if (act === "close") closeGuarded();
      else if (act === "save") save();
      else if (act === "delete") { window.App.deleteTask(draft.id); close(); }
      else if (act === "complete") toggleComplete();
      else if (act === "cl-add") addChk();
      else if (act === "cl-toggle") { const c = draft.checklist.find((x) => x.id === el.dataset.id); if (c) { c.done = !c.done; renderChecklist(); } }
      else if (act === "cl-del") { draft.checklist = draft.checklist.filter((x) => x.id !== el.dataset.id); renderChecklist(); }
      else if (act === "cl-details") { expandedStepId = String(expandedStepId) === String(el.dataset.id) ? null : el.dataset.id; renderChecklist(); }
      else if (act === "cm-add") addCmt();
      else if (act === "cm-edit") { editingCommentId = el.dataset.id; renderComments(); const ta = host.querySelector(".cm-edit-input"); if (ta) { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); } }
      else if (act === "cm-edit-cancel") { editingCommentId = null; renderComments(); }
      else if (act === "cm-edit-save") saveCommentEdit(el.dataset.id);
      else if (act === "cm-del") deleteComment(el.dataset.id);
      else if (act === "att-pick") host.querySelector(".m-att-file").click();
      else if (act === "att-del") deleteAtt(el.dataset.id);
      else if (act === "link-add") addLink();
      else if (act === "link-del") Api.removeTaskLink(draft.id, el.dataset.id).then(applyRelResult).catch(() => window.App.toast("Não foi possível remover o link."));
      else if (act === "rel-add") addRelation();
      else if (act === "rel-del") Api.removeTaskRelation(draft.id, el.dataset.id).then(applyRelResult).catch(() => window.App.toast("Não foi possível remover."));
      else if (act === "rel-open") { const rid = el.dataset.id; if (isDirty()) { window.Modals.confirm({ title: "Descartar alterações?", message: "Abrir a tarefa relacionada vai descartar as alterações não salvas desta.", okText: "Abrir", cancelText: "Ficar", danger: true }).then((ok) => { if (ok) open(rid); }); } else open(rid); }
      else if (act === "label-menu") toggleLabelMenu();
      else if (act === "label-toggle") toggleLabel(el.dataset.id, el.dataset.name);
      else if (act === "label-remove") removeLabel(el.dataset.id);
      else if (act === "label-create") createLabel();
      else if (act === "duplicate") { window.App.saveTask(JSON.parse(JSON.stringify(draft))); window.App.duplicateTask(draft.id); close(); }
      else if (act === "archive") { window.App.archiveTask(draft.id); close(); }
    });

    // ESC to close
    document.addEventListener("keydown", onKey);
  }

  function onKey(e) { if (e.key === "Escape" && host && host.innerHTML) { closeGuarded(); } }

  function markDirty() {
    const rte = host.querySelector(".rte");
    draft.description = rte.innerHTML;
    host.querySelector(".rte-dirty").style.display = rte.innerHTML !== initialDesc ? "inline" : "none";
  }
  function addChk() {
    const inp = host.querySelector(".cl-input");
    const v = inp.value.trim(); if (!v) return;
    draft.checklist.push({ id: nid("c"), text: v, done: false });
    inp.value = ""; renderChecklist(); inp.focus();
  }
  function addCmt() {
    const inp = host.querySelector(".cm-input");
    const v = inp.value.trim(); if (!v) return;
    draft.comments.push({ id: nid("m"), author: TD.me.name, initials: TD.me.initials, color: TD.me.color, avatarUrl: TD.me.avatarUrl, text: v, at: TD.nowISO() });
    inp.value = ""; host.querySelector(".cm-add-btn").style.display = "none"; renderComments();
  }
  function toggleComplete() {
    const done = draft.status === "concluido";
    draft.status = done ? "pendente" : "concluido";
    draft.completedAt = done ? null : TD.nowISO();
    host.querySelector(".modal-side select").value = draft.status;
    setCover();
  }
  function save() {
    draft.description = host.querySelector(".rte").innerHTML;
    window.App.saveTask(JSON.parse(JSON.stringify(draft)));
    close();
  }

  window.Modal = { open, close };
})();
