/* Popovers de "Cor e padrão" e "Etiquetas" do post-it (Notas — Fase 1).
   Ancorados dentro de .note-more-wrap (mesmo canto do menu "mais ações"),
   usando os tokens .note-m3. Renderizados por notes-page.js via
   window.NotesModals.pickerHTML(n) quando window.Notes.ui.picker aponta
   para a nota n. */
(function () {
  const N = window.Notes;
  const U = window.UI;
  const Api = window.TaskData.Api;

  function colorPickerHTML(n) {
    const ck = N.colorKey(n);
    const swatches = N.PALETTE.map((c) => `
      <button class="note-color-picker-swatch${ck === c ? " on" : ""}" data-note-act="pick-color" data-id="${n.id}" data-color="${c}" style="background:${N.COLORS[c]}" title="${c}"></button>`).join("");
    const noneOpt = `
      <button class="note-pattern-swatch${!n.pattern ? " on" : ""}" data-note-act="pick-pattern" data-id="${n.id}" data-pattern="" title="Sem padrão">
        <span class="material-symbols-outlined">block</span>
      </button>`;
    const patternOpts = N.PATTERNS.map((p) => `
      <button class="note-pattern-swatch${n.pattern === p.id ? " on" : ""}" data-note-act="pick-pattern" data-id="${n.id}" data-pattern="${p.id}" title="${p.label}">
        <span class="material-symbols-outlined">${p.icon}</span>
      </button>`).join("");
    return `
      <div class="note-color-picker note-m3" data-note-act="noop">
        <div class="note-color-picker-label">Cor</div>
        <div class="note-color-picker-grid">${swatches}</div>
        <div class="note-color-picker-label">Padrão</div>
        <div class="note-pattern-row">${noneOpt}${patternOpts}</div>
      </div>`;
  }

  function labelPickerHTML(n) {
    const labels = window.state.labels || [];
    const noteLabelIds = new Set((n.labels || []).map((l) => String(l.id)));
    const items = labels.length
      ? labels.map((l) => {
          const on = noteLabelIds.has(String(l.id));
          return `
            <button class="note-label-picker-item${on ? " on" : ""}" data-note-act="toggle-label" data-id="${n.id}" data-label-id="${l.id}">
              <span class="material-symbols-outlined">${on ? "check_box" : "check_box_outline_blank"}</span>
              <span>${U.esc(l.name)}</span>
            </button>`;
        }).join("")
      : `<p class="note-label-picker-empty">Nenhuma etiqueta ainda.</p>`;
    return `
      <div class="note-label-picker note-m3" data-note-act="noop">
        <div class="note-label-picker-title">Etiquetas</div>
        <div class="note-label-picker-list">${items}</div>
        <div class="note-label-picker-new">
          <input type="text" class="note-label-new-input" data-id="${n.id}" placeholder="Nova etiqueta" maxlength="50" />
          <button data-note-act="create-label" data-id="${n.id}" title="Criar etiqueta">
            <span class="material-symbols-outlined" style="font-size:18px">add</span>
          </button>
        </div>
      </div>`;
  }

  function notebookPickerHTML(n) {
    const aw = window.state.activeWorkspaceId;
    const nbs = (window.state.notebooks || []).filter((b) => !aw || String(b.workspaceId) === String(aw));
    const items = nbs.length
      ? nbs.map((b) => {
          const on = String(n.notebookId) === String(b.id);
          return `
            <button class="note-label-picker-item${on ? " on" : ""}" data-note-act="pick-notebook" data-id="${n.id}" data-nb="${b.id}">
              <span class="material-symbols-outlined">${on ? "check" : "menu_book"}</span>
              <span>${U.esc(b.name)}</span>
            </button>`;
        }).join("")
      : `<p class="note-label-picker-empty">Nenhum caderno nesta área.</p>`;
    return `
      <div class="note-label-picker note-m3" data-note-act="noop">
        <div class="note-label-picker-title">Mover para caderno</div>
        <div class="note-label-picker-list">${items}</div>
      </div>`;
  }

  function pickerHTML(n) {
    const picker = N.ui.picker;
    if (!picker || String(picker.id) !== String(n.id)) return "";
    if (picker.type === "labels") return labelPickerHTML(n);
    if (picker.type === "notebook") return notebookPickerHTML(n);
    return colorPickerHTML(n);
  }

  // ---------- ações ----------
  function pickColor(id, color) {
    const n = N.findNote(id);
    if (!n) return;
    n.color = color;
    window.App.render();
    N.refreshOpenPopover();
    Api.updateNote(id, { color }).then((res) => N.applyNoteUpdate(res.note))
      .catch((e) => { console.error(e); window.App.toast("Não foi possível mudar a cor."); });
  }

  function pickPattern(id, pattern) {
    const n = N.findNote(id);
    if (!n) return;
    n.pattern = pattern || null;
    window.App.render();
    N.refreshOpenPopover();
    Api.updateNote(id, { pattern: pattern || null }).then((res) => N.applyNoteUpdate(res.note))
      .catch((e) => { console.error(e); window.App.toast("Não foi possível mudar o padrão."); });
  }

  function toggleLabel(id, labelId, on) {
    const n = N.findNote(id);
    if (!n) return;
    const ids = new Set((n.labels || []).map((l) => Number(l.id)));
    if (on) ids.add(Number(labelId)); else ids.delete(Number(labelId));
    Api.syncNoteLabels(id, Array.from(ids)).then((res) => {
      N.applyNoteUpdate(res.note);
      window.App.render();
      N.refreshOpenPopover();
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível atualizar as etiquetas."); });
  }

  function moveNote(id, notebookId) {
    Api.moveNote(id, notebookId).then((res) => {
      N.applyNoteUpdate(res.note);
      N.closePopovers();
      window.App.render();
      window.App.toast("Nota movida");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível mover a nota."); });
  }

  function createLabel(id, input) {
    const name = (input.value || "").trim();
    if (!name) return;
    input.value = "";
    Api.createLabel(name).then((res) => {
      window.state.labels = res.labels;
      const n = N.findNote(id);
      const ids = new Set((n.labels || []).map((l) => Number(l.id)));
      ids.add(Number(res.label.id));
      return Api.syncNoteLabels(id, Array.from(ids));
    }).then((res) => {
      N.applyNoteUpdate(res.note);
      window.App.render();
      N.refreshOpenPopover();
    }).catch((e) => {
      console.error(e);
      const msg = (e.data && e.data.errors && e.data.errors.name && e.data.errors.name[0]) || "Não foi possível criar a etiqueta.";
      window.App.toast(msg);
    });
  }

  document.addEventListener("click", (e) => {
    const el = e.target.closest("[data-note-act]");
    if (!el) return;
    const act = el.dataset.noteAct, id = el.dataset.id;
    if (act === "pick-color") pickColor(id, el.dataset.color);
    else if (act === "pick-pattern") pickPattern(id, el.dataset.pattern);
    else if (act === "toggle-label") toggleLabel(id, el.dataset.labelId, !el.classList.contains("on"));
    else if (act === "pick-notebook") moveNote(id, el.dataset.nb);
    else if (act === "create-label") {
      const wrap = el.closest(".note-label-picker");
      const input = wrap && wrap.querySelector(".note-label-new-input");
      if (input) createLabel(id, input);
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && e.target.classList.contains("note-label-new-input")) {
      e.preventDefault();
      createLabel(e.target.dataset.id, e.target);
    }
  });

  window.NotesModals = { pickerHTML };
})();
