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
          ${n.body ? `<div class="note-body note-body-rich">${U.sanitizeHtml(n.body)}</div>` : ""}
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
    window.Modals.confirm({ title: "Excluir definitivamente", message: "Excluir definitivamente esta nota? Esta ação não pode ser desfeita.", okText: "Excluir", danger: true }).then((ok) => {
    if (!ok) return;
    Api.forceDeleteNote(id).then(() => {
      trashCache = (trashCache || []).filter((n) => String(n.id) !== String(id));
      window.App.render();
      window.App.toast("Nota excluída definitivamente");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível excluir a nota."); });
    });
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
    window.Modals.prompt({ title: "Renomear etiqueta", label: "Nome", value: label.name, okText: "Salvar", maxlength: 50 }).then((name) => {
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
    });
  }

  function deleteLabel(id) {
    window.Modals.confirm({ title: "Excluir etiqueta", message: "Excluir esta etiqueta? Ela será removida de todas as notas.", okText: "Excluir", danger: true }).then((ok) => {
    if (!ok) return;
    Api.deleteLabel(id).then((res) => {
      window.state.labels = res.labels;
      window.state.notes = (window.state.notes || []).map((n) => ({
        ...n,
        labels: (n.labels || []).filter((l) => String(l.id) !== String(id)),
      }));
      window.App.render();
      window.App.toast("Etiqueta excluída");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível excluir a etiqueta."); });
    });
  }

  // ---------- Cadernos (capas estilo Zoho) ----------
  const NB_GRADIENTS = {
    sunset: "linear-gradient(135deg,#fb923c,#ef4444)",
    ocean:  "linear-gradient(135deg,#3b82f6,#06b6d4)",
    forest: "linear-gradient(135deg,#10b981,#22c55e)",
    grape:  "linear-gradient(135deg,#8b5cf6,#ec4899)",
    slate:  "linear-gradient(135deg,#64748b,#94a3b8)",
    candy:  "linear-gradient(135deg,#ec4899,#f59e0b)",
    night:  "linear-gradient(135deg,#1e293b,#4338ca)",
    mintsea:"linear-gradient(135deg,#14b8a6,#84cc16)",
  };
  const NB_PATTERNS = ["dots", "lines", "grid"];

  function nbHash(id) { const s = String(id); let h = 0; for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return h; }
  function nbDefaultGradient(id) { const k = Object.keys(NB_GRADIENTS); return NB_GRADIENTS[k[nbHash(id) % k.length]]; }

  // Estilo CSS inline da capa, conforme coverType/coverValue (fallback: cor da nota ou gradiente padrão).
  function coverStyle(b) {
    const C = (window.Notes && window.Notes.COLORS) || {};
    const t = b.coverType, v = b.coverValue;
    if (t === "image" && v) return `background-image:url('${U.esc(v)}');background-size:cover;background-position:center;`;
    if (t === "gradient" && NB_GRADIENTS[v]) return `background:${NB_GRADIENTS[v]};`;
    if (t === "color") return `background:${C[v] || v};`;
    if (t === "pattern") return `background:${C[b.color] || "#e8eaed"};`;
    if (b.color && C[b.color]) return `background:${C[b.color]};`;
    return `background:${nbDefaultGradient(b.id)};`;
  }
  function coverPatternClass(b) { return b.coverType === "pattern" && NB_PATTERNS.includes(b.coverValue) ? ` note-pattern-${b.coverValue}` : ""; }

  function coverCardHTML(b) {
    const n = b.count || 0;
    const ro = b.isOwner === false;
    const shared = (b.members || []).length;
    return `
      <div class="nb-cover-card">
        <button class="nb-cover${coverPatternClass(b)}" style="${coverStyle(b)}" data-note-act="open-notebook" data-id="${b.id}" title="Abrir caderno">
          <span class="nb-cover-spine"></span>
          <span class="nb-cover-name">${U.esc(b.name)}</span>
          ${shared ? `<span class="nb-cover-badge" title="Compartilhado"><span class="material-symbols-outlined">group</span></span>` : ""}
          ${ro ? `<span class="nb-cover-owner">de ${U.esc(b.ownerName || "")}</span>` : ""}
        </button>
        <div class="nb-cover-foot">
          <span class="nb-cover-count">${n} ${n === 1 ? "nota" : "notas"}</span>
          <div class="nb-cover-actions">
            <button data-note-act="notebook-cover" data-id="${b.id}" title="Trocar capa"><span class="material-symbols-outlined">wallpaper</span></button>
            <button data-note-act="notebook-share" data-id="${b.id}" title="${ro ? "Compartilhamento / Sair" : "Compartilhar"}"><span class="material-symbols-outlined">group</span></button>
            ${ro ? "" : `<button data-note-act="notebook-rename" data-id="${b.id}" title="Renomear"><span class="material-symbols-outlined">edit</span></button>`}
            ${ro ? "" : `<button data-note-act="notebook-delete" data-id="${b.id}" title="Excluir"><span class="material-symbols-outlined">delete</span></button>`}
          </div>
        </div>
      </div>`;
  }

  function notebooksHTML() {
    const aw = window.state.activeWorkspaceId;
    const grouping = (window.state.prefs && window.state.prefs.notebookGrouping) || "merged";
    const nbs = (window.state.notebooks || []).filter((b) => !aw || aw === window.VIRTUAL_SHARED_WS || String(b.workspaceId) === String(aw));
    const createCard = `<button class="nb-cover-card nb-cover-new" data-note-act="new-notebook"><span class="material-symbols-outlined">add</span><span>Novo caderno</span></button>`;
    if (!nbs.length) return `<div class="nb-cover-grid">${createCard}</div>`;
    if (grouping === "separated") {
      const mine = nbs.filter((b) => b.isOwner !== false);
      const shared = nbs.filter((b) => b.isOwner === false);
      return `
        <div class="nb-cover-section-title">Meus cadernos</div>
        <div class="nb-cover-grid">${mine.map(coverCardHTML).join("")}${createCard}</div>
        ${shared.length ? `<div class="nb-cover-section-title">Compartilhados comigo</div><div class="nb-cover-grid">${shared.map(coverCardHTML).join("")}</div>` : ""}`;
    }
    return `<div class="nb-cover-grid">${nbs.map(coverCardHTML).join("")}${createCard}</div>`;
  }

  // ---------- seletor de capa ----------
  let coverOverlay = null;
  function coverPickerHTML(b) {
    const C = (window.Notes && window.Notes.COLORS) || {};
    const cur = b.coverType, curv = b.coverValue;
    const grads = Object.keys(NB_GRADIENTS).map((g) => `<button class="nb-pick nb-pick-grad${cur === "gradient" && curv === g ? " on" : ""}" style="background:${NB_GRADIENTS[g]}" data-note-act="set-cover" data-id="${b.id}" data-ctype="gradient" data-cval="${g}" title="${g}"></button>`).join("");
    const colors = Object.keys(C).map((c) => `<button class="nb-pick nb-pick-color${cur === "color" && curv === c ? " on" : ""}" style="background:${C[c]}" data-note-act="set-cover" data-id="${b.id}" data-ctype="color" data-cval="${c}" title="${c}"></button>`).join("");
    const patts = NB_PATTERNS.map((p) => `<button class="nb-pick nb-pick-pat note-pattern-${p}${cur === "pattern" && curv === p ? " on" : ""}" style="background:${C[b.color] || "#e8eaed"}" data-note-act="set-cover" data-id="${b.id}" data-ctype="pattern" data-cval="${p}" title="${p}"></button>`).join("");
    return `
      <div class="note-modal note-m3 nb-cover-modal" role="dialog" aria-label="Trocar capa">
        <div class="note-modal-head"><span class="material-symbols-outlined">wallpaper</span> Capa do caderno: ${U.esc(b.name)}
          <button class="note-modal-x" data-note-act="cover-close" title="Fechar"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="nb-cover-picker">
          <div class="nb-pick-label">Gradientes</div>
          <div class="nb-pick-grid">${grads}</div>
          <div class="nb-pick-label">Cores</div>
          <div class="nb-pick-grid">${colors}</div>
          <div class="nb-pick-label">Padrões</div>
          <div class="nb-pick-grid">${patts}</div>
        </div>
        <div class="note-modal-actions">
          <button class="note-btn-ghost" data-note-act="cover-upload" data-id="${b.id}"><span class="material-symbols-outlined" style="font-size:18px">image</span> Enviar imagem</button>
          <button class="note-btn-ghost" data-note-act="set-cover" data-id="${b.id}" data-ctype="" data-cval="">Capa padrão</button>
          <button class="note-btn-ghost" data-note-act="cover-close">Fechar</button>
        </div>
      </div>`;
  }
  function openCoverPicker(id) {
    const b = (window.state.notebooks || []).find((x) => String(x.id) === String(id));
    if (!b) return;
    closeCoverPicker();
    coverOverlay = document.createElement("div");
    coverOverlay.className = "note-modal-overlay";
    coverOverlay.innerHTML = coverPickerHTML(b);
    document.body.appendChild(coverOverlay);
    coverOverlay.addEventListener("click", (ev) => { if (ev.target === coverOverlay) closeCoverPicker(); });
  }
  function refreshCoverPicker(id) {
    if (!coverOverlay) return;
    const b = (window.state.notebooks || []).find((x) => String(x.id) === String(id));
    if (b) coverOverlay.innerHTML = coverPickerHTML(b);
  }
  function closeCoverPicker() { if (coverOverlay) { coverOverlay.remove(); coverOverlay = null; } }
  function setCover(id, type, value) {
    Api.updateNotebook(id, { cover_type: type || null, cover_value: value || null }).then((res) => {
      window.state.notebooks = res.notebooks;
      window.App.render();
      refreshCoverPicker(id);
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível trocar a capa."); });
  }
  function uploadCover(id) {
    const input = document.createElement("input");
    input.type = "file"; input.accept = "image/jpeg,image/png,image/webp";
    input.onchange = () => {
      if (!input.files || !input.files[0]) return;
      window.App.toast("Enviando capa…");
      Api.uploadNotebookCover(id, input.files[0]).then((res) => {
        window.state.notebooks = res.notebooks;
        window.App.render();
        refreshCoverPicker(id);
      }).catch((e) => {
        console.error(e);
        const msg = (e.data && e.data.errors && e.data.errors.cover && e.data.errors.cover[0]) || "Não foi possível enviar a capa.";
        window.App.toast(msg);
      });
    };
    input.click();
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
    window.Modals.prompt({ title: "Renomear caderno", label: "Nome", value: nb.name, okText: "Salvar", maxlength: 120 }).then((name) => {
      if (name === null) return;
      const trimmed = name.trim();
      if (!trimmed || trimmed === nb.name) return;
      Api.updateNotebook(id, { name: trimmed }).then((res) => {
        window.state.notebooks = res.notebooks;
        window.App.render();
      }).catch((e) => { console.error(e); window.App.toast("Não foi possível renomear o caderno."); });
    });
  }

  function deleteNotebook(id) {
    window.Modals.confirm({ title: "Excluir caderno", message: "Excluir este caderno? As notas vão para outro caderno da área.", okText: "Excluir", danger: true }).then((ok) => {
      if (!ok) return;
      Api.deleteNotebook(id).then((res) => {
        window.state.notebooks = res.notebooks;
        if (res.notes) window.state.notes = res.notes;
        if (String(window.state.noteNotebook) === String(id)) window.state.noteNotebook = null;
        window.App.render();
        window.App.toast("Caderno excluído");
      }).catch((e) => { console.error(e); window.App.toast((e.data && e.data.message) || "Não foi possível excluir o caderno."); });
    });
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
    else if (act === "open-notebook") { window.state.noteNotebook = id; window.state.notesView = "active"; window.App.render(); }
    else if (act === "notebook-cover") openCoverPicker(id);
    else if (act === "set-cover") setCover(id, el.dataset.ctype, el.dataset.cval);
    else if (act === "cover-upload") uploadCover(id);
    else if (act === "cover-close") closeCoverPicker();
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
