/* Modal de colaboradores das Notas (Fase 5). window.NotesCollab.open(noteId)
   permite ao dono adicionar/remover por e-mail; o colaborador pode sair da nota.
   Sincronização da edição é por polling (em notes-page.js). Visual M3 (.note-m3). */
(function () {
  const Api = window.TaskData.Api;
  const U = window.UI;
  const me = window.TaskData.me || {};
  let overlay = null;

  function close() {
    if (!overlay) return;
    document.removeEventListener("keydown", onKey);
    overlay.remove();
    overlay = null;
  }
  function onKey(e) { if (e.key === "Escape") close(); }

  function rowHTML(c, canRemove) {
    return `
      <div class="note-collab-row">
        ${U.avatarHTML(c, "note-collab-avatar")}
        <div class="note-collab-info">
          <span class="note-collab-name">${U.esc(c.name)}${c.email === me.email ? " (você)" : ""}</span>
          <span class="note-collab-email">${U.esc(c.email)}</span>
        </div>
        <span class="note-collab-perm-tag">${c.permission === "view" ? "Pode ver" : "Pode editar"}</span>
        ${canRemove ? `<button class="note-collab-remove" data-act="remove" data-user="${c.id}" title="Remover"><span class="material-symbols-outlined">close</span></button>` : ""}
      </div>`;
  }

  function bodyHTML(note) {
    const isOwner = note.isOwner;
    const collabs = note.collaborators || [];
    const head = isOwner
      ? `<div class="note-collab-add">
           <input type="email" class="note-collab-email-input" placeholder="E-mail de quem já usa o app" />
           <select class="note-collab-perm">
             <option value="edit">Pode editar</option>
             <option value="view">Pode ver</option>
           </select>
           <button class="note-btn-save" data-act="add" title="Adicionar"><span class="material-symbols-outlined" style="font-size:18px">person_add</span></button>
         </div>`
      : `<p class="note-collab-shared-by">Compartilhada por <strong>${U.esc(note.ownerName || "")}</strong>.</p>`;
    const list = collabs.length
      ? collabs.map((c) => rowHTML(c, isOwner || c.email === me.email)).join("")
      : `<p class="note-collab-empty">Ninguém além de você tem acesso a esta nota.</p>`;
    return head + `<div class="note-collab-list">${list}</div>`;
  }

  function open(noteId) {
    const note = window.Notes && window.Notes.findNote(noteId);
    if (!note) return;
    if (overlay) close();
    overlay = document.createElement("div");
    overlay.className = "note-modal-overlay";
    overlay.innerHTML = `
      <div class="note-modal note-collab-modal note-m3" role="dialog" aria-label="Colaboradores">
        <div class="note-modal-head"><span class="material-symbols-outlined">group</span> Colaboradores
          <button class="note-modal-x" data-act="cancel" title="Fechar"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="note-collab-body">${bodyHTML(note)}</div>
        <div class="note-modal-actions"><button class="note-btn-ghost" data-act="cancel">Fechar</button></div>
      </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) return close();
      const t = e.target.closest("[data-act]");
      if (!t) return;
      if (t.dataset.act === "cancel") close();
      else if (t.dataset.act === "add") add(noteId);
      else if (t.dataset.act === "remove") remove(noteId, t.dataset.user);
    });
    overlay.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && e.target.classList.contains("note-collab-email-input")) { e.preventDefault(); add(noteId); }
    });
    document.addEventListener("keydown", onKey);
    const input = overlay.querySelector(".note-collab-email-input");
    if (input) input.focus();
  }

  function refresh(noteId) {
    const note = window.Notes.findNote(noteId);
    if (overlay && note) overlay.querySelector(".note-collab-body").innerHTML = bodyHTML(note);
    window.App.render(); // atualiza avatares no card
  }

  function add(noteId) {
    const emailEl = overlay.querySelector(".note-collab-email-input");
    const permEl = overlay.querySelector(".note-collab-perm");
    const email = (emailEl && emailEl.value || "").trim();
    if (!email) { window.App.toast("Informe um e-mail."); return; }
    Api.addCollaborator(noteId, email, permEl ? permEl.value : "edit").then((res) => {
      window.Notes.applyNoteUpdate(res.note);
      if (emailEl) emailEl.value = "";
      refresh(noteId);
      window.App.toast("Colaborador adicionado");
    }).catch((e) => {
      console.error(e);
      const msg = (e.data && (e.data.message)) || "Não foi possível adicionar o colaborador.";
      window.App.toast(msg);
    });
  }

  function remove(noteId, userId) {
    Api.removeCollaborator(noteId, userId).then((res) => {
      const note = res.note;
      // Se eu saí de uma nota compartilhada que não é minha, ela deixa de existir no meu estado.
      if (note && note.isOwner === false && !(note.collaborators || []).some((c) => c.email === me.email)) {
        window.state.notes = (window.state.notes || []).filter((n) => String(n.id) !== String(noteId));
        close();
        window.App.render();
        window.App.toast("Você saiu da nota");
        return;
      }
      window.Notes.applyNoteUpdate(note);
      refresh(noteId);
      window.App.toast("Colaborador removido");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível remover o colaborador."); });
  }

  window.NotesCollab = { open };
})();
