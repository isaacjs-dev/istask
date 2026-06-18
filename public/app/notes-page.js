/* Página "Notas" — mural de post-its (layout inspirado em modelo_Tarefas/notas.html,
   com os tokens do app). Cartão lido por padrão; clique abre o modo de edição com
   seletor de cor. window.Render.notasPageHTML() + delegação [data-note-act].
   Organização (Fase 1): paleta de 12 cores + padrão de fundo opcional, fixar,
   arquivar, etiquetas e sub-navegação (Notas/Arquivo/Lixeira/Etiquetas).
   Conteúdo (Fase 2): checklist (itens, reordenar, converter), anexos de imagem,
   desenho, áudio e OCR. As telas extras vivem em notes-views.js, os popovers em
   notes-modals.js e os modais de desenho/áudio/OCR em notes-canvas.js / notes-audio.js
   / notes-ocr.js — todos consomem o namespace window.Notes exposto abaixo. */
(function () {
  const icon = window.icon;
  const U = window.UI;
  const Api = window.TaskData.Api;

  const PALETTE = [
    "yellow", "pink", "mint", "blue", "lilac", "peach",
    "gray", "teal", "coral", "sand", "sage", "rose",
  ];
  const COLORS = {
    yellow: "#fef9c3", pink: "#fbcfe8", mint: "#bbf7d0",
    blue: "#bfdbfe", lilac: "#e9d5ff", peach: "#fed7aa",
    gray: "#e8eaed", teal: "#c4ebe3", coral: "#ffd3c2",
    sand: "#f0e4c8", sage: "#dbe9d2", rose: "#ffd9e3",
  };
  const PATTERNS = [
    { id: "dots", label: "Pontilhado", icon: "blur_on" },
    { id: "lines", label: "Linhas", icon: "reorder" },
    { id: "grid", label: "Grade", icon: "grid_4x4" },
  ];

  let editingId = null;   // id da nota em edição (escopo de módulo, persiste entre renders)
  const ui = { menuId: null, picker: null }; // picker: {id, type: 'color'|'labels'}
  let dragItem = null;    // {noteId, itemId} durante o drag-and-drop de itens do checklist
  let editDirty = false;  // houve digitação no card em edição (para o sync de colaboração)
  let syncTimer = null;   // polling de sincronização da nota compartilhada aberta

  function closeLayer() {
    const r = document.getElementById("note-popover-root");
    if (r) r.remove();
    document.querySelectorAll(".note-more.is-open").forEach((b) => b.classList.remove("is-open"));
  }
  function closePopovers() { ui.menuId = null; ui.picker = null; closeLayer(); }

  // Renderiza o popover (menu ou picker) numa camada fixa no body — fora do mural
  // em colunas, evitando fragmentação/recorte/sobreposição. Ancorado no botão ⋮.
  function renderPopover() {
    closeLayer();
    const id = ui.menuId || (ui.picker && ui.picker.id);
    if (!id) return;
    const n = findNote(id);
    const btn = document.querySelector(`.note-more[data-id="${id}"]`);
    if (!n || !btn) return;
    const inner = ui.menuId ? moreMenuHTML(n) : (window.NotesModals ? window.NotesModals.pickerHTML(n) : "");
    const root = document.createElement("div");
    root.id = "note-popover-root";
    root.innerHTML = `<div class="note-menu-backdrop" data-note-act="close-popover"></div><div class="note-popover">${inner}</div>`;
    document.body.appendChild(root);
    btn.classList.add("is-open");
    placePopover(btn.getBoundingClientRect(), root.querySelector(".note-popover"));
  }

  function placePopover(box, panel) {
    const pad = 8;
    const pw = panel.offsetWidth, ph = panel.offsetHeight;
    let left = Math.max(pad, Math.min(box.right - pw, window.innerWidth - pw - pad)); // borda direita junto ao botão, com clamp
    let top = box.bottom + 4;
    if (top + ph > window.innerHeight - pad) top = Math.max(pad, box.top - ph - 4);   // sem espaço abaixo → abre para cima
    panel.style.left = left + "px";
    panel.style.top = top + "px";
  }

  function refreshOpenPopover() { if (ui.menuId || ui.picker) renderPopover(); }

  // hash determinístico simples a partir do id (para cor/rotação padrão estáveis)
  function hash(id) {
    const s = String(id); let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return h;
  }
  function colorKey(n) {
    return COLORS[n.color] ? n.color : PALETTE[hash(n.id) % PALETTE.length];
  }
  function rotOf(n) {
    const r = [-2, -1.5, -1, 1, 1.5, 2][hash(n.id) % 6];
    return r;
  }
  function tagList(tags) {
    return (tags || "").split(",").map((t) => t.trim()).filter(Boolean);
  }
  function findNote(id) { return (window.state.notes || []).find((n) => String(n.id) === String(id)); }

  // ---------- lembretes (helpers de exibição) ----------
  function reminderOverdue(n) { return !!(n.remindAt && new Date(n.remindAt).getTime() <= Date.now()); }
  function reminderOverdueCount() {
    return (window.state.notes || []).filter((n) => !n.archivedAt && reminderOverdue(n)).length;
  }
  function fmtReminder(iso) {
    if (!iso) return "";
    const d = new Date(iso), now = new Date();
    const time = `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
    if (d.toDateString() === now.toDateString()) return `Hoje ${time}`;
    const tmrw = new Date(now); tmrw.setDate(now.getDate() + 1);
    if (d.toDateString() === tmrw.toDateString()) return `Amanhã ${time}`;
    return `${String(d.getDate()).padStart(2, "0")}/${String(d.getMonth() + 1).padStart(2, "0")} ${time}`;
  }

  // ---------- colaboradores (avatares no card) ----------
  function collabAvatarsHTML(n) {
    const cs = n.collaborators || [];
    if (!cs.length) return "";
    const shown = cs.slice(0, 3).map((c) => U.avatarHTML(c, "note-collab-avatar sm")).join("");
    const more = cs.length > 3 ? `<span class="note-collab-more">+${cs.length - 3}</span>` : "";
    const owner = !n.isOwner && n.ownerName ? `<span class="note-collab-owner-tag">de ${U.esc(n.ownerName)}</span>` : "";
    return `<div class="note-collab-avatars" data-note-act="open-collab" data-id="${n.id}" title="Colaboradores">${shown}${more}${owner}</div>`;
  }

  function filterByQuery(notes) {
    const q = (window.state.query || "").trim().toLowerCase();
    if (!q) return notes;
    return notes.filter((n) =>
      (n.title || "").toLowerCase().includes(q) ||
      (n.body || "").toLowerCase().includes(q) ||
      (n.tags || "").toLowerCase().includes(q) ||
      (n.labels || []).some((l) => (l.name || "").toLowerCase().includes(q)) ||
      (n.items || []).some((it) => (it.text || "").toLowerCase().includes(q))
    );
  }
  function filterNotes() { return filterByQuery(window.state.notes || []); }

  // ---------- escopo por Área de Trabalho / Caderno ----------
  function noteWorkspaceId(n) {
    const nb = (window.state.notebooks || []).find((b) => String(b.id) === String(n.notebookId));
    return nb ? nb.workspaceId : null;
  }
  function inActiveWorkspace(n) {
    const aw = window.state.activeWorkspaceId;
    if (!aw || !n.notebookId) return true; // sem área ativa ou nota legada sem caderno
    const wsId = noteWorkspaceId(n);
    if (wsId == null) return true; // caderno fora das minhas áreas (nota compartilhada direto comigo): sempre visível
    return String(wsId) === String(aw);
  }
  function notebookMatch(n) {
    const nb = window.state.noteNotebook;
    return !nb || String(n.notebookId) === String(nb);
  }
  function activeNotebooks() {
    const aw = window.state.activeWorkspaceId;
    return (window.state.notebooks || []).filter((b) => !aw || String(b.workspaceId) === String(aw));
  }

  // ---------- filtros (cor / tipo / etiqueta) ----------
  function filters() {
    return window.state.noteFilters || (window.state.noteFilters = { color: null, type: null, labelId: null });
  }
  function anyFilterActive() { const f = filters(); return !!(f.color || f.type || f.labelId || f.hasReminder || f.shared); }
  function applyFilters(notes) {
    const f = filters();
    return notes.filter((n) =>
      (!f.color || n.color === f.color) &&
      (!f.type || (n.type || "text") === f.type) &&
      (!f.labelId || (n.labels || []).some((l) => String(l.id) === String(f.labelId))) &&
      (!f.hasReminder || !!n.remindAt) &&
      (!f.shared || (n.collaborators || []).length > 0)
    );
  }
  function setFilter(key, val) {
    const f = filters();
    if (key === "hasReminder" || key === "shared") f[key] = !f[key];
    else f[key] = String(f[key]) === String(val) ? null : val; // clicar de novo limpa
    closePopovers();
    window.App.render();
  }
  function clearFilters() {
    window.state.noteFilters = { color: null, type: null, labelId: null, hasReminder: false, shared: false };
    window.App.render();
  }

  function filterBarHTML() {
    const f = filters();
    const labels = window.state.labels || [];
    const typeChip = (id, label, ic) => `<button class="note-filter-chip${f.type === id ? " on" : ""}" data-note-act="set-filter" data-fkey="type" data-fval="${id}"><span class="material-symbols-outlined">${ic}</span>${label}</button>`;
    const dots = PALETTE.map((c) => `<button class="note-filter-dot${f.color === c ? " on" : ""}" data-note-act="set-filter" data-fkey="color" data-fval="${c}" style="background:${COLORS[c]}" title="${c}"></button>`).join("");
    const labelChips = labels.map((l) => `<button class="note-filter-chip${String(f.labelId) === String(l.id) ? " on" : ""}" data-note-act="set-filter" data-fkey="labelId" data-fval="${l.id}"><span class="material-symbols-outlined">label</span>${U.esc(l.name)}</button>`).join("");
    return `<div class="note-filter-bar note-m3">
      <span class="note-filter-ico material-symbols-outlined" title="Filtros">filter_list</span>
      ${typeChip("text", "Texto", "notes")}${typeChip("checklist", "Lista", "checklist")}
      <button class="note-filter-chip${f.hasReminder ? " on" : ""}" data-note-act="set-filter" data-fkey="hasReminder" data-fval="1"><span class="material-symbols-outlined">schedule</span>Com lembrete</button>
      <button class="note-filter-chip${f.shared ? " on" : ""}" data-note-act="set-filter" data-fkey="shared" data-fval="1"><span class="material-symbols-outlined">group</span>Compartilhada</button>
      <span class="note-filter-sep"></span>
      <div class="note-filter-colors">${dots}</div>
      ${labels.length ? `<span class="note-filter-sep"></span>${labelChips}` : ""}
      ${anyFilterActive() ? `<button class="note-filter-clear" data-note-act="clear-filters"><span class="material-symbols-outlined">close</span> Limpar</button>` : ""}
    </div>`;
  }

  // ---------- sub-navegação (Notas / Arquivo / Lixeira / Etiquetas) ----------
  function subnavHTML() {
    const view = window.state.notesView || "active";
    const notes = window.state.notes || [];
    const archivedCount = notes.filter((n) => n.archivedAt && inActiveWorkspace(n)).length;
    const labelsCount = (window.state.labels || []).length;
    const items = [
      { id: "active", label: "Notas", icon: "sticky_note_2" },
      { id: "notebooks", label: "Cadernos", icon: "menu_book", count: activeNotebooks().length },
      { id: "reminders", label: "Lembretes", icon: "notifications", count: reminderOverdueCount() },
      { id: "archived", label: "Arquivo", icon: "archive", count: archivedCount },
      { id: "trash", label: "Lixeira", icon: "delete" },
      { id: "labels", label: "Etiquetas", icon: "label", count: labelsCount },
    ];
    return `<div class="note-subnav note-m3">${items.map((it) => `
      <button class="note-subnav-item${view === it.id ? " active" : ""}" data-note-act="view" data-view="${it.id}">
        <span class="material-symbols-outlined">${it.icon}</span> ${it.label}${it.count ? `<span class="note-subnav-count">${it.count}</span>` : ""}
      </button>`).join("")}</div>`;
  }

  // ---------- barra de cadernos (view "Notas") ----------
  function notebookBarHTML() {
    const nbs = activeNotebooks();
    const active = window.state.noteNotebook;
    const chip = (id, label, count) => `<button class="note-nb-chip${String(active || "") === String(id || "") ? " on" : ""}" data-note-act="set-notebook" data-nb="${id || ""}"><span class="material-symbols-outlined">${id ? "menu_book" : "apps"}</span>${label}${count != null ? `<span class="note-nb-count">${count}</span>` : ""}</button>`;
    return `<div class="note-notebook-bar note-m3">
      ${chip("", "Todos", null)}
      ${nbs.map((b) => chip(b.id, U.esc(b.name), b.count || 0)).join("")}
      <button class="note-nb-add" data-note-act="new-notebook" title="Novo caderno"><span class="material-symbols-outlined">add</span></button>
    </div>`;
  }

  // ---------- checklist ----------
  function orderedItems(n) {
    const items = (n.items || []).slice();
    const group = !(window.state.prefs && window.state.prefs.notesGroupCompleted === false);
    if (!group) return items;
    return items.filter((i) => !i.done).concat(items.filter((i) => i.done));
  }

  function readChecklistHTML(n, ro) {
    const items = orderedItems(n);
    if (!items.length) return `<div class="note-body empty">${ro ? "Lista vazia." : "Lista vazia — toque para adicionar itens…"}</div>`;
    return `<ul class="note-checklist">${items.map((it) => {
      const check = ro
        ? `<span class="note-item-check"><span class="material-symbols-outlined">${it.done ? "check_box" : "check_box_outline_blank"}</span></span>`
        : `<button class="note-item-check" data-note-act="toggle-item" data-id="${n.id}" data-item="${it.id}" title="${it.done ? "Desmarcar" : "Concluir"}"><span class="material-symbols-outlined">${it.done ? "check_box" : "check_box_outline_blank"}</span></button>`;
      return `<li class="note-item${it.done ? " done" : ""}">${check}<span class="note-item-text">${U.esc(it.text)}</span></li>`;
    }).join("")}</ul>`;
  }

  function editChecklistHTML(n) {
    const items = (n.items || []); // já ordenados por position no backend
    const rows = items.map((it) => `
      <li class="note-item-edit" draggable="true" data-id="${n.id}" data-item="${it.id}">
        <span class="note-item-drag material-symbols-outlined" title="Arrastar para reordenar">drag_indicator</span>
        <button class="note-item-check" data-note-act="toggle-item" data-id="${n.id}" data-item="${it.id}">
          <span class="material-symbols-outlined">${it.done ? "check_box" : "check_box_outline_blank"}</span>
        </button>
        <input class="note-item-input${it.done ? " done" : ""}" draggable="false" data-id="${n.id}" data-item="${it.id}" value="${U.esc(it.text)}" />
        <button class="note-item-del" data-note-act="delete-item" data-id="${n.id}" data-item="${it.id}" title="Remover item">
          <span class="material-symbols-outlined">close</span>
        </button>
      </li>`).join("");
    return `<ul class="note-checklist editing">${rows}
      <li class="note-item-add">
        <span class="material-symbols-outlined">add</span>
        <input class="note-item-new-input" data-id="${n.id}" placeholder="Adicionar item" maxlength="1000" />
      </li>
    </ul>`;
  }

  // ---------- anexos ----------
  function attThumbHTML(noteId, a) {
    const mime = a.mime || "";
    const del = `<button class="note-att-del" data-note-act="att-delete" data-id="${noteId}" data-att="${a.id}" title="Remover anexo">${icon("Trash", 13)}</button>`;
    if (mime.startsWith("audio/")) {
      return `<div class="note-att note-att-audio"><audio controls preload="none" src="${U.esc(a.url)}"></audio>${del}</div>`;
    }
    if (mime.startsWith("image/")) {
      const isDraw = a.origin === "drawing";
      return `<div class="note-att note-att-img${isDraw ? " is-drawing" : ""}">
        <a href="${U.esc(a.url)}" target="_blank" rel="noopener"><img src="${U.esc(a.url)}" alt="${U.esc(a.name)}" loading="lazy" /></a>
        ${isDraw ? `<span class="note-att-badge material-symbols-outlined" title="Desenho">brush</span>` : ""}
        <div class="note-att-actions">
          <button class="note-att-btn" data-note-act="ocr" data-id="${noteId}" data-att="${a.id}" title="Extrair texto (OCR)"><span class="material-symbols-outlined">text_fields</span></button>
          ${del}
        </div>
      </div>`;
    }
    return `<div class="note-att note-att-file">
      <a href="${U.esc(a.url)}" target="_blank" rel="noopener"><span class="material-symbols-outlined">description</span> ${U.esc(a.name)}</a>${del}
    </div>`;
  }

  function attachmentsHTML(n) {
    const atts = n.attachments || [];
    if (!atts.length) return "";
    // data-note-act="noop" evita que cliques nas miniaturas/áudio abram o modo de edição
    return `<div class="note-attachments" data-note-act="noop">${atts.map((a) => attThumbHTML(n.id, a)).join("")}</div>`;
  }

  // ---------- cartões ----------
  function moreMenuHTML(n) {
    const isChecklist = n.type === "checklist";
    return `
      <div class="note-more-menu note-m3">
        <button class="note-more-item" data-note-act="open-color-picker" data-id="${n.id}">
          <span class="material-symbols-outlined">palette</span> Cor e padrão
        </button>
        <button class="note-more-item" data-note-act="open-label-picker" data-id="${n.id}">
          <span class="material-symbols-outlined">label</span> Etiquetas
        </button>
        <button class="note-more-item" data-note-act="move-notebook" data-id="${n.id}">
          <span class="material-symbols-outlined">drive_file_move</span> Mover para caderno
        </button>
        <button class="note-more-item" data-note-act="convert" data-id="${n.id}" data-type="${isChecklist ? "text" : "checklist"}">
          <span class="material-symbols-outlined">${isChecklist ? "notes" : "checklist"}</span> ${isChecklist ? "Converter em texto" : "Converter em lista"}
        </button>
        <button class="note-more-item" data-note-act="open-reminder" data-id="${n.id}">
          <span class="material-symbols-outlined">schedule</span> ${n.remindAt ? "Editar lembrete" : "Lembrete"}
        </button>
        <button class="note-more-item" data-note-act="open-collab" data-id="${n.id}">
          <span class="material-symbols-outlined">group</span> Colaboradores${(n.collaborators || []).length ? ` (${n.collaborators.length})` : ""}
        </button>
        <div class="note-more-sep"></div>
        <button class="note-more-item" data-note-act="attach-image" data-id="${n.id}">
          <span class="material-symbols-outlined">image</span> Anexar imagem
        </button>
        <button class="note-more-item" data-note-act="draw" data-id="${n.id}">
          <span class="material-symbols-outlined">brush</span> Desenhar
        </button>
        <button class="note-more-item" data-note-act="record-audio" data-id="${n.id}">
          <span class="material-symbols-outlined">mic</span> Gravar áudio
        </button>
        <div class="note-more-sep"></div>
        <button class="note-more-item" data-note-act="copy" data-id="${n.id}">
          <span class="material-symbols-outlined">content_copy</span> Copiar
        </button>
        <button class="note-more-item" data-note-act="export-md" data-id="${n.id}">
          <span class="material-symbols-outlined">download</span> Exportar .md
        </button>
        <button class="note-more-item" data-note-act="archive" data-id="${n.id}">
          <span class="material-symbols-outlined">${n.archivedAt ? "unarchive" : "archive"}</span> ${n.archivedAt ? "Desarquivar" : "Arquivar"}
        </button>
      </div>`;
  }

  function readCardHTML(n) {
    const ck = colorKey(n);
    const tags = tagList(n.tags);
    const labels = n.labels || [];
    const ro = n.permission === "view"; // compartilhada só para visualização
    const patternClass = n.pattern && PATTERNS.some((p) => p.id === n.pattern) ? ` note-pattern-${n.pattern}` : "";
    const content = n.type === "checklist"
      ? readChecklistHTML(n, ro)
      : `<div class="note-body${n.body ? "" : " empty"}">${n.body ? U.esc(n.body) : (ro ? "" : "Toque para escrever…")}</div>`;
    return `
      <div class="masonry-item">
        <div class="note-postit${patternClass}${ro ? " readonly" : ""}"${ro ? "" : ` data-note-act="edit"`} data-id="${n.id}" style="--note-bg:${COLORS[ck]};--rot:${rotOf(n)}deg">
          <span class="note-tape"></span>
          ${ro ? `<span class="note-ro-badge" title="Somente leitura"><span class="material-symbols-outlined">visibility</span></span>` : `
          <button class="note-pin${n.pinned ? " active" : ""}" data-note-act="pin" data-id="${n.id}" title="${n.pinned ? "Desafixar" : "Fixar"}">
            <span class="material-symbols-outlined">push_pin</span>
          </button>
          <div class="note-more-wrap">
            <button class="note-more" data-note-act="toggle-menu" data-id="${n.id}" title="Mais ações">
              <span class="material-symbols-outlined">more_vert</span>
            </button>
          </div>
          <button class="note-del" data-note-act="delete" data-id="${n.id}" title="Excluir nota">${icon("Trash", 16)}</button>`}
          ${n.title ? `<h3 class="note-title">${U.esc(n.title)}</h3>` : ""}
          ${content}
          ${attachmentsHTML(n)}
          ${n.remindAt ? `<button class="note-reminder-badge${reminderOverdue(n) ? " overdue" : ""}" data-note-act="open-reminder" data-id="${n.id}" title="Lembrete"><span class="material-symbols-outlined">${n.remindRecurrence ? "event_repeat" : "schedule"}</span>${fmtReminder(n.remindAt)}</button>` : ""}
          ${collabAvatarsHTML(n)}
          ${tags.length ? `<div class="note-tags">${tags.map((t) => `<span class="note-tag">#${U.esc(t)}</span>`).join("")}</div>` : ""}
          ${labels.length ? `<div class="note-labels">${labels.map((l) => `<span class="note-label-chip"><span class="material-symbols-outlined">label</span>${U.esc(l.name)}</span>`).join("")}</div>` : ""}
        </div>
      </div>`;
  }

  function editCardHTML(n) {
    const ck = colorKey(n);
    const dots = PALETTE.map((c) => `
      <button class="note-color-dot${ck === c ? " on" : ""}" data-note-act="set-color" data-id="${n.id}" data-color="${c}" style="background:${COLORS[c]}" title="${c}"></button>`).join("");
    const content = n.type === "checklist"
      ? editChecklistHTML(n)
      : `<textarea class="note-body-input" placeholder="Escreva sua nota…" rows="5">${U.esc(n.body || "")}</textarea>`;
    return `
      <div class="masonry-item">
        <div class="note-postit editing" data-id="${n.id}" style="--note-bg:${COLORS[ck]}">
          <span class="note-tape"></span>
          <input class="note-title-input" placeholder="Título…" value="${U.esc(n.title || "")}" />
          ${content}
          ${attachmentsHTML(n)}
          <input class="note-tags-input" placeholder="tags separadas por vírgula" value="${U.esc(n.tags || "")}" />
          <div class="note-colors">${dots}</div>
          <div class="note-edit-actions">
            <button class="note-btn-ghost" data-note-act="cancel" data-id="${n.id}">Cancelar</button>
            <button class="note-btn-save" data-note-act="save" data-id="${n.id}">${icon("Check", 14)} Salvar</button>
          </div>
        </div>
      </div>`;
  }

  function emptyHTML() {
    const searching = !!(window.state.query || "").trim();
    return `<div class="empty"><div class="empty-ico">${icon("NotebookPen", 24)}</div>
      <h3>${searching ? "Nenhuma nota encontrada" : "Nenhuma nota ainda"}</h3>
      <p>${searching ? "Ajuste a busca ou crie uma nova nota." : 'Crie uma nota ou peça ao assistente: "anota que…".'}</p>
      <button class="btn-primary" data-note-act="new">${icon("Plus", 16)} Nova nota</button>
    </div>`;
  }

  function wrapCards(cardsHtml) {
    return window.state.notesViewMode === "list" ? `<div class="note-list">${cardsHtml}</div>` : `<div class="masonry-grid">${cardsHtml}</div>`;
  }

  function notasPageHTML() {
    const view = window.state.notesView || "active";
    const sub = subnavHTML();

    if (view === "notebooks") return sub + (window.NotesViews ? window.NotesViews.notebooksHTML() : "");
    if (view === "archived") return sub + (window.NotesViews ? window.NotesViews.archivedHTML() : "");
    if (view === "trash") return sub + (window.NotesViews ? window.NotesViews.trashHTML() : "");
    if (view === "labels") return sub + (window.NotesViews ? window.NotesViews.labelsHTML() : "");
    if (view === "reminders") return sub + (window.NotesViews ? window.NotesViews.remindersHTML() : "");

    const nbBar = notebookBarHTML();
    const base = filterNotes().filter((n) => !n.archivedAt && inActiveWorkspace(n) && notebookMatch(n));
    const notes = applyFilters(base);
    const hasActive = (window.state.notes || []).some((n) => !n.archivedAt && inActiveWorkspace(n));
    const bar = (hasActive || anyFilterActive()) ? filterBarHTML() : "";

    if (!notes.length) {
      if (anyFilterActive() || window.state.noteNotebook) {
        return sub + nbBar + bar + `<div class="empty"><div class="empty-ico"><span class="material-symbols-outlined" style="font-size:26px">filter_list_off</span></div>
          <h3>Nenhuma nota aqui</h3>
          <button class="btn-primary" data-note-act="new">${icon("Plus", 16)} Nova nota</button></div>`;
      }
      return sub + nbBar + emptyHTML();
    }

    const pinned = notes.filter((n) => n.pinned);
    const others = notes.filter((n) => !n.pinned);
    const wrap = (arr) => wrapCards(arr.map((n) => (String(n.id) === String(editingId) ? editCardHTML(n) : readCardHTML(n))).join(""));

    let html = "";
    if (pinned.length) {
      html += `<div class="note-section-title">Fixadas</div>${wrap(pinned)}`;
      html += `<div class="note-section-title">Outras</div>${wrap(others)}`;
    } else {
      html += wrap(others);
    }
    return sub + nbBar + bar + html;
  }

  // ---------- ações de nota ----------
  function createNote() {
    const nb = window.state.noteNotebook || ((activeNotebooks()[0] || {}).id) || null;
    Api.createNote(nb ? { body: "", notebook_id: +nb } : { body: "" }).then((res) => {
      window.state.notes = [res.note, ...(window.state.notes || [])];
      editingId = String(res.note.id);
      window.App.render();
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível criar a nota."); });
  }

  function saveNote(id, card) {
    const titleEl = card.querySelector(".note-title-input");
    const bodyEl = card.querySelector(".note-body-input");
    const tagsEl = card.querySelector(".note-tags-input");
    const payload = {
      title: titleEl ? titleEl.value.trim() : "",
      tags: tagsEl ? tagsEl.value.trim() : "",
    };
    if (bodyEl) payload.body = bodyEl.value; // checklist não tem corpo
    const n = findNote(id);
    if (n && n.color) payload.color = n.color;
    Api.updateNote(id, payload).then((res) => {
      applyNoteUpdate(res.note);
      editingId = null;
      window.App.render();
      window.App.toast("Nota salva");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível salvar a nota."); });
  }

  // troca de cor no modo edição: preserva o texto não salvo no estado, re-renderiza e persiste
  function setColor(id, color, card) {
    const n = findNote(id);
    if (!n) return;
    const titleEl = card.querySelector(".note-title-input");
    const bodyEl = card.querySelector(".note-body-input");
    const tagsEl = card.querySelector(".note-tags-input");
    if (titleEl) n.title = titleEl.value;
    if (bodyEl) n.body = bodyEl.value;
    if (tagsEl) n.tags = tagsEl.value;
    n.color = color;
    window.App.render();
    const payload = { color };
    if (titleEl) payload.title = n.title.trim();
    if (bodyEl) payload.body = n.body;
    if (tagsEl) payload.tags = n.tags.trim();
    Api.updateNote(id, payload)
      .catch((e) => { console.error(e); window.App.toast("Não foi possível mudar a cor."); });
  }

  function deleteNote(id) {
    if (!window.confirm("Mover esta nota para a lixeira?")) return;
    Api.deleteNote(id).then(() => {
      window.state.notes = (window.state.notes || []).filter((n) => String(n.id) !== String(id));
      if (String(editingId) === String(id)) editingId = null;
      closePopovers();
      window.App.render();
      window.App.toast("Nota movida para a lixeira");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível excluir a nota."); });
  }

  function togglePin(id) {
    closePopovers();
    Api.pinNote(id).then((res) => {
      applyNoteUpdate(res.note);
      window.App.render();
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível fixar a nota."); });
  }

  function toggleArchive(id) {
    closePopovers();
    Api.archiveNote(id).then((res) => {
      applyNoteUpdate(res.note);
      window.App.render();
      window.App.toast(res.note.archivedAt ? "Nota arquivada" : "Nota desarquivada");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível arquivar a nota."); });
  }

  function convertNote(id, type) {
    closePopovers();
    Api.convertNote(id, type).then((res) => {
      applyNoteUpdate(res.note);
      editingId = String(id); // abre em edição para o usuário continuar
      window.App.render();
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível converter a nota."); });
  }

  function toggleMenu(id) {
    if (ui.menuId === id) { closePopovers(); return; }
    ui.picker = null;
    ui.menuId = id;
    renderPopover();
  }

  function openPicker(id, type) {
    ui.menuId = null;
    ui.picker = { id, type };
    renderPopover();
  }

  function setNotebookFilter(nb) {
    window.state.noteNotebook = nb;
    closePopovers();
    window.App.render();
  }

  function createNotebookPrompt() {
    const aw = window.state.activeWorkspaceId;
    if (!aw) { window.App.toast("Crie uma área de trabalho primeiro."); return; }
    const name = window.prompt("Nome do novo caderno:");
    if (!name || !name.trim()) return;
    Api.createNotebook(name.trim(), +aw).then((res) => {
      window.state.notebooks = res.notebooks;
      if (res.notebook) window.state.noteNotebook = res.notebook.id;
      window.App.render();
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível criar o caderno."); });
  }

  function setView(view) {
    if (window.state.notesView === view) return;
    closePopovers();
    stopEditSync();
    editingId = null;
    window.state.notesView = view;
    if (view === "trash" && window.NotesViews) window.NotesViews.loadTrash();
    if (view === "reminders" && window.NotesViews) window.NotesViews.loadReminders();
    window.App.render();
  }

  function setViewMode(mode) {
    if (window.state.notesViewMode === mode) return;
    window.state.notesViewMode = mode;
    window.state.prefs = Object.assign({}, window.state.prefs, { notesViewMode: mode });
    closePopovers();
    window.App.render();
    Api.savePrefs({ notesViewMode: mode }).catch(() => {});
  }

  // ---------- ações de checklist ----------
  function applyItems(id, note) {
    const n = findNote(id);
    if (n && note) n.items = note.items || [];
  }

  function toggleItem(id, itemId) {
    const n = findNote(id);
    const it = n && (n.items || []).find((x) => String(x.id) === String(itemId));
    if (!it) return;
    it.done = !it.done; // otimista
    window.App.render();
    Api.updateNoteItem(id, itemId, { done: it.done })
      .catch((e) => { console.error(e); window.App.toast("Não foi possível atualizar o item."); });
  }

  function persistItemText(id, itemId, value) {
    const n = findNote(id);
    const it = n && (n.items || []).find((x) => String(x.id) === String(itemId));
    if (!it || it.text === value) return;
    it.text = value; // otimista, sem re-render (preserva foco)
    Api.updateNoteItem(id, itemId, { text: value })
      .catch((e) => { console.error(e); window.App.toast("Não foi possível salvar o item."); });
  }

  function addItem(id, input) {
    const text = (input.value || "").trim();
    if (!text) return;
    input.value = "";
    Api.createNoteItem(id, text).then((res) => {
      applyItems(id, res.note);
      window.App.render();
      const fresh = document.querySelector(`.note-item-new-input[data-id="${id}"]`);
      if (fresh) fresh.focus();
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível adicionar o item."); });
  }

  function deleteChecklistItem(id, itemId) {
    const n = findNote(id);
    if (n) n.items = (n.items || []).filter((x) => String(x.id) !== String(itemId)); // otimista
    window.App.render();
    Api.deleteNoteItem(id, itemId)
      .catch((e) => { console.error(e); window.App.toast("Não foi possível remover o item."); });
  }

  function reorderItems(noteId, orderedIds) {
    const n = findNote(noteId);
    if (n) n.items = orderedIds.map((iid) => (n.items || []).find((x) => String(x.id) === String(iid))).filter(Boolean);
    window.App.render();
    Api.reorderNoteItems(noteId, orderedIds.map(Number))
      .catch((e) => { console.error(e); window.App.toast("Não foi possível reordenar."); });
  }

  // ---------- ações de anexo ----------
  function uploadNoteAttachment(id, file, origin) {
    window.App.toast("Enviando anexo…");
    return Api.uploadAttachment("note", id, file, origin).then((res) => {
      const n = findNote(id);
      if (n) n.attachments = [...(n.attachments || []), res.attachment];
      window.App.render();
      window.App.toast("Anexo adicionado");
      return res.attachment;
    }).catch((e) => {
      console.error(e);
      const msg = (e.data && e.data.errors && e.data.errors.file && e.data.errors.file[0]) || "Não foi possível anexar o arquivo.";
      window.App.toast(msg);
    });
  }

  function pickImage(id) {
    closePopovers();
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "image/*";
    input.onchange = () => { if (input.files && input.files[0]) uploadNoteAttachment(id, input.files[0]); };
    input.click();
  }

  function deleteAttachment(id, attId) {
    Api.deleteAttachment(attId).then(() => {
      const n = findNote(id);
      if (n) n.attachments = (n.attachments || []).filter((a) => String(a.id) !== String(attId));
      window.App.render();
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível remover o anexo."); });
  }

  // ---------- OCR ----------
  function addItemsSequentially(id, lines) {
    return lines.reduce((p, line) => p.then(() => Api.createNoteItem(id, line).then((res) => applyItems(id, res.note))), Promise.resolve())
      .then(() => window.App.render());
  }

  function runOcr(id, attId) {
    const n = findNote(id);
    const a = n && (n.attachments || []).find((x) => String(x.id) === String(attId));
    if (!a) return;
    if (!window.OCR || !window.OCR.recognize) { window.App.toast("OCR indisponível (verifique a conexão)."); return; }
    closePopovers();
    window.App.toast("Extraindo texto da imagem… (pode levar alguns segundos)");
    window.OCR.recognize(a.url).then((text) => {
      const clean = (text || "").trim();
      if (!clean) { window.App.toast("Nenhum texto reconhecido."); return; }
      if (n.type === "checklist") {
        const lines = clean.split(/\r?\n/).map((s) => s.trim()).filter(Boolean);
        addItemsSequentially(id, lines).then(() => window.App.toast("Texto extraído para a lista"));
      } else {
        n.body = (n.body ? n.body + "\n" : "") + clean;
        editingId = String(id);
        window.App.render();
        Api.updateNote(id, { body: n.body }).then((res) => applyNoteUpdate(res.note)).catch(() => {});
        window.App.toast("Texto extraído");
      }
    }).catch((e) => { console.error(e); window.App.toast("Falha ao extrair texto."); });
  }

  // ---------- delegação de clique ----------
  document.addEventListener("click", (e) => {
    const el = e.target.closest("[data-note-act]");
    if (!el) {
      if (ui.menuId || ui.picker) closePopovers();
      return;
    }
    const act = el.dataset.noteAct, id = el.dataset.id, card = el.closest(".note-postit");
    if (act !== "new" && act !== "edit") e.stopPropagation();

    if (act === "new") createNote();
    else if (act === "delete") deleteNote(id);
    else if (act === "edit") { editingId = String(id); editDirty = false; closePopovers(); window.App.render(); startEditSync(id); }
    else if (act === "save") { stopEditSync(); saveNote(id, card); }
    else if (act === "cancel") { stopEditSync(); editDirty = false; editingId = null; window.App.render(); }
    else if (act === "set-color") setColor(id, el.dataset.color, card);
    else if (act === "pin") togglePin(id);
    else if (act === "archive") toggleArchive(id);
    else if (act === "convert") convertNote(id, el.dataset.type);
    else if (act === "toggle-menu") toggleMenu(id);
    else if (act === "open-color-picker") openPicker(id, "color");
    else if (act === "open-label-picker") openPicker(id, "labels");
    else if (act === "move-notebook") openPicker(id, "notebook");
    else if (act === "set-notebook") setNotebookFilter(el.dataset.nb || null);
    else if (act === "new-notebook") createNotebookPrompt();
    else if (act === "manage-notebooks") setView("notebooks");
    else if (act === "view") setView(el.dataset.view);
    else if (act === "set-view-mode") setViewMode(el.dataset.mode);
    else if (act === "set-filter") setFilter(el.dataset.fkey, el.dataset.fval);
    else if (act === "clear-filters") clearFilters();
    else if (act === "close-popover") closePopovers();
    else if (act === "toggle-item") toggleItem(id, el.dataset.item);
    else if (act === "delete-item") deleteChecklistItem(id, el.dataset.item);
    else if (act === "attach-image") pickImage(id);
    else if (act === "draw") { closePopovers(); if (window.NotesCanvas) window.NotesCanvas.open(id); else window.App.toast("Editor de desenho indisponível."); }
    else if (act === "record-audio") { closePopovers(); if (window.NotesAudio) window.NotesAudio.open(id); else window.App.toast("Gravador de áudio indisponível."); }
    else if (act === "open-reminder") { closePopovers(); if (window.NotesReminder) window.NotesReminder.open(id); }
    else if (act === "open-collab") { closePopovers(); if (window.NotesCollab) window.NotesCollab.open(id); }
    else if (act === "copy") { closePopovers(); if (window.NotesExport) window.NotesExport.copyNote(id); }
    else if (act === "export-md") { closePopovers(); if (window.NotesExport) window.NotesExport.exportMd(id); }
    else if (act === "ocr") runOcr(id, el.dataset.att);
    else if (act === "att-delete") deleteAttachment(id, el.dataset.att);
  });

  // adicionar item (Enter) e persistir texto editado (change/blur)
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && (ui.menuId || ui.picker)) { closePopovers(); return; }
    if (e.key === "Enter" && e.target.classList.contains("note-item-new-input")) {
      e.preventDefault();
      addItem(e.target.dataset.id, e.target);
    }
  });
  document.addEventListener("change", (e) => {
    if (e.target.classList && e.target.classList.contains("note-item-input")) {
      persistItemText(e.target.dataset.id, e.target.dataset.item, e.target.value);
    }
  });
  // marca o card aberto como "sujo" (digitação) para o sync de colaboração não sobrescrever
  document.addEventListener("input", (e) => {
    if (e.target.closest && e.target.closest(".note-postit.editing")) editDirty = true;
  });

  // o popover é fixo e ancorado ao botão; ao rolar/redimensionar, fecha para não desalinhar
  window.addEventListener("scroll", () => { if (ui.menuId || ui.picker) closePopovers(); }, true);
  window.addEventListener("resize", () => { if (ui.menuId || ui.picker) closePopovers(); });

  // drag-and-drop de itens do checklist (modo edição)
  document.addEventListener("dragstart", (e) => {
    const li = e.target.closest && e.target.closest(".note-item-edit");
    if (!li) return;
    dragItem = { noteId: li.dataset.id, itemId: li.dataset.item };
    if (e.dataTransfer) { e.dataTransfer.effectAllowed = "move"; try { e.dataTransfer.setData("text/plain", li.dataset.item); } catch (_) {} }
  });
  document.addEventListener("dragover", (e) => {
    const li = e.target.closest && e.target.closest(".note-item-edit");
    if (li && dragItem) e.preventDefault(); // permite o drop
  });
  document.addEventListener("drop", (e) => {
    const li = e.target.closest && e.target.closest(".note-item-edit");
    if (!li || !dragItem) return;
    e.preventDefault();
    const noteId = li.dataset.id, targetId = li.dataset.item, srcId = dragItem.itemId;
    const sameNote = String(noteId) === String(dragItem.noteId);
    dragItem = null;
    if (sameNote && srcId !== targetId) {
      const n = findNote(noteId);
      if (!n) return;
      const ids = (n.items || []).map((x) => String(x.id));
      const from = ids.indexOf(String(srcId)), to = ids.indexOf(String(targetId));
      if (from < 0 || to < 0) return;
      ids.splice(to, 0, ids.splice(from, 1)[0]);
      reorderItems(noteId, ids);
    }
  });
  document.addEventListener("dragend", () => { dragItem = null; });

  // ---------- estado compartilhado ----------
  function applyNoteUpdate(note) {
    window.state.notes = (window.state.notes || []).map((x) => String(x.id) === String(note.id) ? note : x);
  }

  // ---------- polling de lembretes (leve, só com a aba visível) ----------
  function pollReminders() {
    if (document.visibilityState !== "visible") return;
    if (!(window.state.notes || []).some((n) => n.remindAt)) return; // nada a checar
    Api.remindersDue().then((res) => {
      const fired = res.fired || [];
      if (!fired.length) return;
      fired.forEach((note) => { applyNoteUpdate(note); window.App.toast(`⏰ Lembrete: ${note.title || "nota"}`); });
      window.App.render();
    }).catch(() => {});
  }
  setTimeout(pollReminders, 4000);       // verificação inicial logo após o carregamento
  setInterval(pollReminders, 60000);     // a cada 60s

  // ---------- sincronização de nota compartilhada em edição (~30s) ----------
  function startEditSync(id) {
    stopEditSync();
    const n = findNote(id);
    if (!n || !(n.collaborators && n.collaborators.length)) return; // só notas compartilhadas
    syncTimer = setInterval(() => {
      if (document.visibilityState !== "visible") return;
      if (String(editingId) !== String(id)) { stopEditSync(); return; }
      Api.getNote(id).then((res) => {
        const cur = findNote(id);
        if (!cur || !res.note) return;
        if (res.note.updatedAt && res.note.updatedAt !== cur.updatedAt) {
          if (editDirty) {
            window.App.toast("Esta nota foi atualizada por outro colaborador.");
          } else {
            applyNoteUpdate(res.note);
            window.App.render();
          }
        }
      }).catch(() => {});
    }, 30000);
  }
  function stopEditSync() { if (syncTimer) { clearInterval(syncTimer); syncTimer = null; } }

  // Namespace compartilhado com notes-modals.js / notes-views.js / notes-canvas.js / notes-audio.js.
  window.Notes = {
    PALETTE, COLORS, PATTERNS, ui,
    findNote, colorKey, rotOf, tagList, filterByQuery, wrapCards, inActiveWorkspace,
    readCardHTML, closePopovers, refreshOpenPopover, applyNoteUpdate,
    // usado pelos modais de desenho/áudio após o upload
    addAttachment(noteId, attachment) {
      const n = findNote(noteId);
      if (n) { n.attachments = [...(n.attachments || []), attachment]; window.App.render(); }
    },
    uploadNoteAttachment,
  };

  window.Render.notasPageHTML = notasPageHTML;
})();
