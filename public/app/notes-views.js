/* Telas extras de Notas (Fase 1 — Organização): Arquivo, Lixeira e Etiquetas.
   Renderizadas por notasPageHTML() quando window.state.notesView !== 'active',
   consumindo window.Notes (cards/cores/wrap) e window.TaskData.Api. */
(function () {
  const U = window.UI;
  const Api = window.TaskData.Api;

  function emptyBlock(materialIcon, title, text) {
    return `<div class="empty"><div class="empty-ico"><span class="material-symbols-outlined" style="font-size:28px">${materialIcon}</span></div>
      <h3>${title}</h3>${text ? `<p>${text}</p>` : ""}</div>`;
  }

  // ---------- Arquivo ----------
  function archivedHTML() {
    const N = window.Notes;
    const notes = N.filterByQuery((window.state.notes || []).filter((n) => n.archivedAt && N.inActiveWorkspace(n)));
    if (!notes.length) return emptyBlock("archive", "Nenhuma nota arquivada", "Notas arquivadas saem do mural principal, mas continuam aqui.");
    return N.wrapCards(notes.map((n) => N.readCardHTML(n)).join(""));
  }

  // ---------- Lixeira ----------
  let trashCache = null;
  let trashLoading = false;

  function loadTrash() {
    if (trashLoading) return;
    trashLoading = true;
    trashCache = null;
    Api.trashNotes().then((res) => {
      trashCache = res.notes || [];
    }).catch((e) => {
      console.error(e);
      trashCache = [];
    }).finally(() => {
      trashLoading = false;
      window.App.render();
    });
  }

  function daysLeft(n) {
    if (!n.deletedAt) return 7;
    const expires = new Date(n.deletedAt).getTime() + 7 * 86400000;
    return Math.max(0, Math.ceil((expires - Date.now()) / 86400000));
  }

  function trashCardHTML(n) {
    const N = window.Notes;
    const ck = N.colorKey(n);
    const tags = N.tagList(n.tags);
    const left = daysLeft(n);
    return `
      <div class="masonry-item">
        <div class="note-postit" style="--note-bg:${N.COLORS[ck]};--rot:${N.rotOf(n)}deg">
          <span class="note-tape"></span>
          <div class="note-trash-badge note-m3"><span class="material-symbols-outlined">schedule</span>${left <= 0 ? "Expira hoje" : `Expira em ${left} ${left === 1 ? "dia" : "dias"}`}</div>
          ${n.title ? `<h3 class="note-title">${U.esc(n.title)}</h3>` : ""}
          ${n.body ? `<div class="note-body">${U.esc(n.body)}</div>` : ""}
          ${tags.length ? `<div class="note-tags">${tags.map((t) => `<span class="note-tag">#${U.esc(t)}</span>`).join("")}</div>` : ""}
          <div class="note-edit-actions">
            <button class="note-btn-ghost" data-note-act="trash-restore" data-id="${n.id}">
              <span class="material-symbols-outlined" style="font-size:16px">restore_from_trash</span> Restaurar
            </button>
            <button class="note-btn-save note-btn-danger" data-note-act="trash-delete" data-id="${n.id}">
              <span class="material-symbols-outlined" style="font-size:16px">delete_forever</span> Excluir
            </button>
          </div>
        </div>
      </div>`;
  }

  function trashHTML() {
    if (trashCache === null) {
      if (!trashLoading) loadTrash();
      return emptyBlock("hourglass_top", "Carregando lixeira…");
    }
    if (!trashCache.length) return emptyBlock("delete", "Lixeira vazia", "Notas excluídas ficam aqui por 7 dias antes de serem removidas definitivamente.");
    return window.Notes.wrapCards(trashCache.map(trashCardHTML).join(""));
  }

  function restoreNote(id) {
    Api.restoreNote(id).then((res) => {
      window.state.notes = [res.note, ...(window.state.notes || [])];
      trashCache = (trashCache || []).filter((n) => String(n.id) !== String(id));
      window.App.render();
      window.App.toast("Nota restaurada");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível restaurar a nota."); });
  }

  function forceDeleteNote(id) {
    if (!window.confirm("Excluir definitivamente esta nota? Esta ação não pode ser desfeita.")) return;
    Api.forceDeleteNote(id).then(() => {
      trashCache = (trashCache || []).filter((n) => String(n.id) !== String(id));
      window.App.render();
      window.App.toast("Nota excluída definitivamente");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível excluir a nota."); });
  }

  // ---------- Etiquetas (gestão) ----------
  function labelsHTML() {
    const labels = window.state.labels || [];
    const notes = window.state.notes || [];
    const countFor = (labelId) => notes.filter((n) => (n.labels || []).some((l) => String(l.id) === String(labelId))).length;

    const createRow = `
      <div class="note-label-row note-m3">
        <span class="material-symbols-outlined">add</span>
        <input class="note-label-input note-m3" id="note-label-create-input" type="text" placeholder="Nova etiqueta" maxlength="50" style="flex:1" />
        <div class="note-label-row-actions">
          <button data-note-act="label-create" title="Criar etiqueta"><span class="material-symbols-outlined">check</span></button>
        </div>
      </div>`;

    const rows = labels.length ? labels.map((l) => {
      const n = countFor(l.id);
      return `
        <div class="note-label-row note-m3">
          <span class="material-symbols-outlined">label</span>
          <span class="note-label-row-name">${U.esc(l.name)}</span>
          <span class="note-label-row-count">${n} ${n === 1 ? "nota" : "notas"}</span>
          <div class="note-label-row-actions">
            <button data-note-act="label-rename" data-id="${l.id}" title="Renomear"><span class="material-symbols-outlined">edit</span></button>
            <button data-note-act="label-delete" data-id="${l.id}" title="Excluir"><span class="material-symbols-outlined">delete</span></button>
          </div>
        </div>`;
    }).join("") : `<p class="note-label-picker-empty">Nenhuma etiqueta criada ainda. Crie acima ou pelo menu "Etiquetas" de uma nota.</p>`;

    return `<div class="note-label-mgmt">${createRow}${rows}</div>`;
  }

  function createLabelFromInput() {
    const input = document.getElementById("note-label-create-input");
    if (!input) return;
    const name = (input.value || "").trim();
    if (!name) return;
    input.value = "";
    Api.createLabel(name).then((res) => {
      window.state.labels = res.labels;
      window.App.render();
      window.App.toast("Etiqueta criada");
    }).catch((e) => {
      console.error(e);
      const msg = (e.data && e.data.errors && e.data.errors.name && e.data.errors.name[0]) || "Não foi possível criar a etiqueta.";
      window.App.toast(msg);
    });
  }

  function renameLabel(id) {
    const label = (window.state.labels || []).find((l) => String(l.id) === String(id));
    if (!label) return;
    const name = window.prompt("Renomear etiqueta", label.name);
    if (name === null) return;
    const trimmed = name.trim();
    if (!trimmed || trimmed === label.name) return;
    Api.updateLabel(id, trimmed).then((res) => {
      window.state.labels = res.labels;
      window.state.notes = (window.state.notes || []).map((n) => ({
        ...n,
        labels: (n.labels || []).map((l) => String(l.id) === String(id) ? { ...l, name: trimmed } : l),
      }));
      window.App.render();
    }).catch((e) => {
      console.error(e);
      const msg = (e.data && e.data.errors && e.data.errors.name && e.data.errors.name[0]) || "Não foi possível renomear a etiqueta.";
      window.App.toast(msg);
    });
  }

  function deleteLabel(id) {
    if (!window.confirm('Excluir esta etiqueta? Ela será removida de todas as notas.')) return;
    Api.deleteLabel(id).then((res) => {
      window.state.labels = res.labels;
      window.state.notes = (window.state.notes || []).map((n) => ({
        ...n,
        labels: (n.labels || []).filter((l) => String(l.id) !== String(id)),
      }));
      window.App.render();
      window.App.toast("Etiqueta excluída");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível excluir a etiqueta."); });
  }

  // ---------- Cadernos (gestão) ----------
  function notebooksHTML() {
    const aw = window.state.activeWorkspaceId;
    const nbs = (window.state.notebooks || []).filter((b) => !aw || String(b.workspaceId) === String(aw));
    const createRow = `
      <div class="note-label-row note-m3">
        <span class="material-symbols-outlined">add</span>
        <input class="note-label-input note-m3" id="note-notebook-create-input" type="text" placeholder="Novo caderno" maxlength="120" style="flex:1" />
        <div class="note-label-row-actions">
          <button data-note-act="notebook-create" title="Criar caderno"><span class="material-symbols-outlined">check</span></button>
        </div>
      </div>`;
    const rows = nbs.length ? nbs.map((b) => {
      const n = b.count || 0;
      const ro = b.isOwner === false;
      const shared = (b.members || []).length;
      return `
        <div class="note-label-row note-m3">
          <span class="material-symbols-outlined">menu_book</span>
          <span class="note-label-row-name">${U.esc(b.name)}</span>
          <span class="note-label-row-count">${n} ${n === 1 ? "nota" : "notas"}${shared ? ` · ${shared} 👥` : ""}${ro ? ` · de ${U.esc(b.ownerName || "")}` : ""}</span>
          <div class="note-label-row-actions">
            <button data-note-act="notebook-share" data-id="${b.id}" title="${ro ? "Compartilhamento / Sair" : "Compartilhar"}"><span class="material-symbols-outlined">group</span></button>
            ${ro ? "" : `<button data-note-act="notebook-rename" data-id="${b.id}" title="Renomear"><span class="material-symbols-outlined">edit</span></button>`}
            ${ro ? "" : `<button data-note-act="notebook-delete" data-id="${b.id}" title="Excluir"><span class="material-symbols-outlined">delete</span></button>`}
          </div>
        </div>`;
    }).join("") : `<p class="note-label-picker-empty">Nenhum caderno nesta área. Crie acima.</p>`;
    return `<div class="note-label-mgmt">${createRow}${rows}</div>`;
  }

  function createNotebookFromInput() {
    const input = document.getElementById("note-notebook-create-input");
    const aw = window.state.activeWorkspaceId;
    if (!input || !aw) return;
    const name = (input.value || "").trim();
    if (!name) return;
    input.value = "";
    Api.createNotebook(name, +aw).then((res) => {
      window.state.notebooks = res.notebooks;
      window.App.render();
      window.App.toast("Caderno criado");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível criar o caderno."); });
  }

  function renameNotebook(id) {
    const nb = (window.state.notebooks || []).find((b) => String(b.id) === String(id));
    if (!nb) return;
    const name = window.prompt("Renomear caderno", nb.name);
    if (name === null) return;
    const trimmed = name.trim();
    if (!trimmed || trimmed === nb.name) return;
    Api.updateNotebook(id, { name: trimmed }).then((res) => {
      window.state.notebooks = res.notebooks;
      window.App.render();
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível renomear o caderno."); });
  }

  function deleteNotebook(id) {
    if (!window.confirm("Excluir este caderno? As notas vão para outro caderno da área.")) return;
    Api.deleteNotebook(id).then((res) => {
      window.state.notebooks = res.notebooks;
      if (res.notes) window.state.notes = res.notes;
      if (String(window.state.noteNotebook) === String(id)) window.state.noteNotebook = null;
      window.App.render();
      window.App.toast("Caderno excluído");
    }).catch((e) => { console.error(e); window.App.toast((e.data && e.data.message) || "Não foi possível excluir o caderno."); });
  }

  document.addEventListener("click", (e) => {
    const el = e.target.closest("[data-note-act]");
    if (!el) return;
    const act = el.dataset.noteAct, id = el.dataset.id;
    if (act === "trash-restore") restoreNote(id);
    else if (act === "trash-delete") forceDeleteNote(id);
    else if (act === "label-create") createLabelFromInput();
    else if (act === "label-rename") renameLabel(id);
    else if (act === "label-delete") deleteLabel(id);
    else if (act === "notebook-create") createNotebookFromInput();
    else if (act === "notebook-rename") renameNotebook(id);
    else if (act === "notebook-delete") deleteNotebook(id);
    else if (act === "notebook-share" && window.openShareModal) window.openShareModal("notebook", id);
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && e.target.id === "note-label-create-input") {
      e.preventDefault();
      createLabelFromInput();
    }
    if (e.key === "Enter" && e.target.id === "note-notebook-create-input") {
      e.preventDefault();
      createNotebookFromInput();
    }
  });

  // ---------- Lembretes ----------
  function byRemind(a, b) { return new Date(a.remindAt) - new Date(b.remindAt); }

  function remindersHTML() {
    const N = window.Notes;
    const all = (window.state.notes || []).filter((n) => !n.archivedAt && n.remindAt);
    if (!all.length) return emptyBlock("notifications", "Nenhum lembrete", "Defina um lembrete pelo menu de uma nota para acompanhá-lo aqui.");
    const now = Date.now();
    const overdue = all.filter((n) => new Date(n.remindAt).getTime() <= now).sort(byRemind);
    const upcoming = all.filter((n) => new Date(n.remindAt).getTime() > now).sort(byRemind);
    let html = "";
    if (overdue.length) html += `<div class="note-section-title">Atrasados</div>` + N.wrapCards(overdue.map((n) => N.readCardHTML(n)).join(""));
    if (upcoming.length) html += `<div class="note-section-title">Próximos</div>` + N.wrapCards(upcoming.map((n) => N.readCardHTML(n)).join(""));
    return html;
  }

  // Atualiza (cross-sessão) os lembretes das notas já presentes no estado.
  function loadReminders() {
    Api.noteReminders().then((res) => {
      const N = window.Notes;
      (res.notes || []).forEach((note) => {
        if ((window.state.notes || []).some((x) => String(x.id) === String(note.id))) N.applyNoteUpdate(note);
      });
      window.App.render();
    }).catch(() => {});
  }

  window.NotesViews = { archivedHTML, trashHTML, labelsHTML, remindersHTML, notebooksHTML, loadTrash, loadReminders };
})();
