/* Modal de compartilhamento genérico (Fase 2): window.openShareModal(type, id)
   para type ∈ workspace | project | notebook. Adicionar/remover por e-mail,
   mudar permissão (editar/ver), transferir propriedade e sair. Reaproveita o
   visual de notes-collab (.note-modal/.note-collab-*). */
(function () {
  const Api = window.TaskData.Api;
  const U = window.UI;
  const me = window.TaskData.me || {};
  let overlay = null;
  let ctx = null; // { type, id }

  const TYPES = {
    workspace: { list: () => window.state.workspaces, add: Api.addWorkspaceMember, remove: Api.removeWorkspaceMember, transfer: Api.transferWorkspace, label: "área" },
    project:   { list: () => window.state.projects,   add: Api.addProjectMember,   remove: Api.removeProjectMember,   transfer: Api.transferProject,   label: "projeto" },
    notebook:  { list: () => window.state.notebooks,  add: Api.addNotebookMember,  remove: Api.removeNotebookMember,  transfer: null,                  label: "caderno" },
  };

  function cfg() { return TYPES[ctx.type]; }
  function entity() { return (cfg().list() || []).find((x) => String(x.id) === String(ctx.id)); }

  function applyRes(res) {
    ["workspaces", "projects", "notebooks"].forEach((k) => { if (res[k]) window.state[k] = res[k]; });
  }

  function close() {
    if (!overlay) return;
    document.removeEventListener("keydown", onKey);
    overlay.remove();
    overlay = null; ctx = null;
  }
  function onKey(e) { if (e.key === "Escape") close(); }

  function memberRow(n, isOwner) {
    const mine = n.email === me.email;
    const canRemove = isOwner || mine;
    const permSel = isOwner
      ? `<select class="note-collab-perm" data-act="share-perm" data-user="${n.id}">
           <option value="edit"${n.permission === "edit" ? " selected" : ""}>Pode editar</option>
           <option value="view"${n.permission === "view" ? " selected" : ""}>Pode ver</option>
         </select>`
      : `<span class="note-collab-perm-tag">${n.permission === "view" ? "Pode ver" : "Pode editar"}</span>`;
    return `
      <div class="note-collab-row">
        ${U.avatarHTML(n, "note-collab-avatar")}
        <div class="note-collab-info">
          <span class="note-collab-name">${U.esc(n.name)}${mine ? " (você)" : ""}</span>
          <span class="note-collab-email">${U.esc(n.email)}</span>
        </div>
        ${permSel}
        ${isOwner && cfg().transfer ? `<button class="note-collab-transfer" data-act="share-transfer" data-user="${n.id}" title="Tornar proprietário"><span class="material-symbols-outlined">workspace_premium</span></button>` : ""}
        ${canRemove ? `<button class="note-collab-remove" data-act="share-remove" data-user="${n.id}" title="${mine ? "Sair" : "Remover"}"><span class="material-symbols-outlined">close</span></button>` : ""}
      </div>`;
  }

  function bodyHTML() {
    const e = entity();
    if (!e) return `<p class="note-collab-empty">Item indisponível.</p>`;
    const isOwner = e.isOwner;
    const members = e.members || [];
    const head = isOwner
      ? `<div class="note-collab-add">
           <input type="email" class="note-collab-email-input" placeholder="E-mail de quem já usa o app" />
           <select class="note-collab-perm" id="share-new-perm"><option value="edit">Pode editar</option><option value="view">Pode ver</option></select>
           <button class="note-btn-save" data-act="share-add" title="Adicionar"><span class="material-symbols-outlined" style="font-size:18px">person_add</span></button>
         </div>`
      : `<p class="note-collab-shared-by">Compartilhado por <strong>${U.esc(e.ownerName || "")}</strong>.</p>`;
    const ownerRow = `<div class="note-collab-row"><span class="note-collab-owner-chip"><span class="material-symbols-outlined">star</span></span>
      <div class="note-collab-info"><span class="note-collab-name">${U.esc(e.ownerName || "Proprietário")}${isOwner ? " (você)" : ""}</span><span class="note-collab-email">Proprietário</span></div></div>`;
    const list = members.length ? members.map((m) => memberRow(m, isOwner)).join("") : `<p class="note-collab-empty">Ainda não compartilhado.</p>`;
    return head + `<div class="note-collab-list">${ownerRow}${list}</div>`;
  }

  function open(type, id) {
    if (!TYPES[type]) return;
    ctx = { type, id };
    if (overlay) close();
    ctx = { type, id };
    const e = entity();
    const name = e ? e.name : "";
    overlay = document.createElement("div");
    overlay.className = "note-modal-overlay";
    overlay.innerHTML = `
      <div class="note-modal note-collab-modal note-m3" role="dialog" aria-label="Compartilhar">
        <div class="note-modal-head"><span class="material-symbols-outlined">group</span> Compartilhar ${cfg().label}: ${U.esc(name)}
          <button class="note-modal-x" data-act="share-close" title="Fechar"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="share-body">${bodyHTML()}</div>
        <div class="note-modal-actions"><button class="note-btn-ghost" data-act="share-close">Fechar</button></div>
      </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener("click", onClick);
    overlay.addEventListener("change", onChange);
    overlay.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter" && ev.target.classList.contains("note-collab-email-input")) { ev.preventDefault(); add(); }
    });
    document.addEventListener("keydown", onKey);
    const input = overlay.querySelector(".note-collab-email-input");
    if (input) input.focus();
  }

  function refresh() {
    if (overlay) overlay.querySelector(".share-body").innerHTML = bodyHTML();
    window.App.render();
  }

  function onClick(e) {
    if (e.target === overlay) return close();
    const el = e.target.closest("[data-act]");
    if (!el) return;
    const act = el.dataset.act;
    if (act === "share-close") close();
    else if (act === "share-add") add();
    else if (act === "share-remove") remove(el.dataset.user);
    else if (act === "share-transfer") transfer(el.dataset.user);
  }
  function onChange(e) {
    if (e.target.dataset.act === "share-perm") {
      const email = (entity().members || []).find((m) => String(m.id) === String(e.target.dataset.user));
      if (email) addWith(email.email, e.target.value);
    }
  }

  function add() {
    const emailEl = overlay.querySelector(".note-collab-email-input");
    const permEl = overlay.querySelector("#share-new-perm");
    const email = (emailEl && emailEl.value || "").trim();
    if (!email) { window.App.toast("Informe um e-mail."); return; }
    addWith(email, permEl ? permEl.value : "edit", () => { if (emailEl) emailEl.value = ""; });
  }
  function addWith(email, permission, done) {
    cfg().add(ctx.id, email, permission).then((res) => {
      applyRes(res);
      if (done) done();
      refresh();
    }).catch((err) => window.App.toast((err.data && err.data.message) || "Não foi possível compartilhar."));
  }

  function remove(userId) {
    const mine = (entity().members || []).some((m) => String(m.id) === String(userId) && m.email === me.email);
    cfg().remove(ctx.id, userId).then((res) => {
      applyRes(res);
      if (mine) { close(); window.App.render(); window.App.toast("Você saiu do compartilhamento."); return; }
      refresh();
    }).catch(() => window.App.toast("Não foi possível remover."));
  }

  function transfer(userId) {
    const m = (entity().members || []).find((x) => String(x.id) === String(userId));
    if (!cfg().transfer || !m) return;
    if (!window.confirm(`Transferir a propriedade desta ${cfg().label} para ${m.name}? Você passará a ser editor.`)) return;
    cfg().transfer(ctx.id, userId).then((res) => {
      applyRes(res);
      refresh();
      window.App.toast("Propriedade transferida");
    }).catch((err) => window.App.toast((err.data && err.data.message) || "Não foi possível transferir."));
  }

  window.openShareModal = open;
})();
