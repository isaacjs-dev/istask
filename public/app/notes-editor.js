/* Editor de nota em modal (estilo Zoho Notebook). window.NotesEditor.open(id).
   Usa TipTap (window.TipTap, carregado via ESM CDN no app.blade.php) para edição
   rica do corpo; degrada para um campo editável (contenteditable) se o TipTap não
   carregar — exibindo o HTML formatado, nunca as tags cruas. Título, cor e
   tags salvam por autosave (debounce). Ações ricas (lembrete, colaboradores,
   anexos, desenho, áudio) reaproveitam os overlays globais já existentes. */
(function () {
  const U = window.UI;
  const Api = window.TaskData.Api;

  let overlay = null;
  let editor = null;       // instância TipTap (ou null no fallback)
  let fallback = null;     // editor de fallback (contenteditable) quando o TipTap não carrega
  let current = null;      // id da nota aberta
  let saveTimer = null;
  let dirty = false;

  function note() { return window.Notes.findNote(current); }

  const TOOLBAR = [
    { cmd: "bold", icon: "format_bold", title: "Negrito" },
    { cmd: "italic", icon: "format_italic", title: "Itálico" },
    { cmd: "underline", icon: "format_underlined", title: "Sublinhado" },
    { cmd: "strike", icon: "format_strikethrough", title: "Tachado" },
    { cmd: "highlight", icon: "ink_highlighter", title: "Realçar" },
    { sep: true },
    { cmd: "h1", icon: "format_h1", title: "Título" },
    { cmd: "h2", icon: "format_h2", title: "Subtítulo" },
    { cmd: "bulletList", icon: "format_list_bulleted", title: "Lista" },
    { cmd: "orderedList", icon: "format_list_numbered", title: "Lista numerada" },
    { cmd: "taskList", icon: "checklist", title: "Lista de tarefas" },
    { cmd: "blockquote", icon: "format_quote", title: "Citação" },
    { cmd: "codeBlock", icon: "code", title: "Código" },
    { cmd: "link", icon: "link", title: "Link" },
    { sep: true },
    { cmd: "undo", icon: "undo", title: "Desfazer" },
    { cmd: "redo", icon: "redo", title: "Refazer" },
  ];

  function toolbarHTML() {
    return `<div class="ne-toolbar">${TOOLBAR.map((b) => b.sep
      ? `<span class="ne-tb-sep"></span>`
      : `<button class="ne-tb" data-ne="cmd" data-cmd="${b.cmd}" title="${b.title}"><span class="material-symbols-outlined">${b.icon}</span></button>`).join("")}</div>`;
  }

  function attHTML(n) {
    const atts = n.attachments || [];
    if (!atts.length) return "";
    return `<div class="ne-attachments">${atts.map((a) => {
      const mime = a.mime || "";
      const del = `<button class="ne-att-del" data-ne="att-del" data-att="${a.id}" title="Remover">${window.icon("Trash", 13)}</button>`;
      if (mime.startsWith("audio/")) return `<div class="ne-att"><audio controls preload="none" src="${U.esc(a.url)}"></audio>${del}</div>`;
      if (mime.startsWith("image/")) return `<div class="ne-att ne-att-img"><a href="${U.esc(a.url)}" target="_blank" rel="noopener"><img src="${U.esc(a.url)}" alt="" loading="lazy"></a>${del}</div>`;
      return `<div class="ne-att ne-att-file"><a href="${U.esc(a.url)}" target="_blank" rel="noopener"><span class="material-symbols-outlined">description</span> ${U.esc(a.name)}</a>${del}</div>`;
    }).join("")}</div>`;
  }

  function actionsHTML(n) {
    const btn = (act, ic, label) => `<button class="ne-act" data-ne="${act}" title="${label}"><span class="material-symbols-outlined">${ic}</span></button>`;
    return [
      btn("attach", "image", "Anexar imagem"),
      btn("draw", "brush", "Desenhar"),
      btn("audio", "mic", "Gravar áudio"),
      btn("reminder", n.remindAt ? "event_repeat" : "schedule", "Lembrete"),
      btn("collab", "group", "Colaboradores"),
      btn("archive", n.archivedAt ? "unarchive" : "archive", n.archivedAt ? "Desarquivar" : "Arquivar"),
      btn("delete", "delete", "Excluir"),
      `<div class="ne-more-wrap"><button class="ne-act" data-ne="more" title="Mais opções"><span class="material-symbols-outlined">more_vert</span></button><div class="ne-more-menu" hidden></div></div>`,
    ].join("");
  }

  // ---------- "Mais opções": etiquetas, mover caderno, converter, padrão, copiar, exportar ----------
  let moreView = "menu";
  function moreMenuInner(n) {
    const PATTERNS = window.Notes.PATTERNS || [];
    if (moreView === "labels") {
      const labels = window.state.labels || [];
      const sel = new Set((n.labels || []).map((l) => String(l.id)));
      return `<button class="ne-mi ne-mi-back" data-ne="more-back"><span class="material-symbols-outlined">arrow_back</span> Etiquetas</button><div class="ne-mi-sep"></div>
        ${labels.length ? labels.map((l) => `<button class="ne-mi" data-ne="lbl-toggle" data-label="${l.id}"><span class="material-symbols-outlined">${sel.has(String(l.id)) ? "check_box" : "check_box_outline_blank"}</span> ${U.esc(l.name)}</button>`).join("") : `<div class="ne-mi-empty">Sem etiquetas ainda.</div>`}
        <div class="ne-mi-new"><input class="ne-lbl-new" placeholder="Nova etiqueta" maxlength="50"><button class="ne-mi-add" data-ne="lbl-create" title="Criar"><span class="material-symbols-outlined">add</span></button></div>`;
    }
    if (moreView === "notebook") {
      const nbs = window.state.notebooks || [];
      return `<button class="ne-mi ne-mi-back" data-ne="more-back"><span class="material-symbols-outlined">arrow_back</span> Mover para caderno</button><div class="ne-mi-sep"></div>
        ${nbs.length ? nbs.map((nb) => `<button class="ne-mi" data-ne="nb-move" data-nb="${nb.id}"><span class="material-symbols-outlined">${String(nb.id) === String(n.notebookId) ? "folder_open" : "folder"}</span> ${U.esc(nb.name)}</button>`).join("") : `<div class="ne-mi-empty">Nenhum caderno.</div>`}`;
    }
    if (moreView === "pattern") {
      const sw = (id, label) => `<button class="ne-pat${(n.pattern || "") === id ? " on" : ""}" data-ne="pat-set" data-pat="${id}" title="${label}"><span class="ne-pat-prev ne-pat-${id || "none"}"></span></button>`;
      return `<button class="ne-mi ne-mi-back" data-ne="more-back"><span class="material-symbols-outlined">arrow_back</span> Padrão de fundo</button><div class="ne-mi-sep"></div>
        <div class="ne-pat-row">${sw("", "Nenhum")}${PATTERNS.map((p) => sw(p.id, p.label)).join("")}</div>`;
    }
    const conv = n.type === "checklist" ? { t: "text", ic: "notes", lbl: "Converter em texto" } : { t: "checklist", ic: "checklist", lbl: "Converter em lista" };
    return `
      <button class="ne-mi" data-ne="more-labels"><span class="material-symbols-outlined">label</span> Etiquetas</button>
      <button class="ne-mi" data-ne="more-notebook"><span class="material-symbols-outlined">drive_file_move</span> Mover para caderno</button>
      <button class="ne-mi" data-ne="more-convert" data-type="${conv.t}"><span class="material-symbols-outlined">${conv.ic}</span> ${conv.lbl}</button>
      <button class="ne-mi" data-ne="more-pattern"><span class="material-symbols-outlined">texture</span> Padrão de fundo</button>
      <div class="ne-mi-sep"></div>
      <button class="ne-mi" data-ne="more-copy"><span class="material-symbols-outlined">content_copy</span> Copiar</button>
      <button class="ne-mi" data-ne="more-export"><span class="material-symbols-outlined">download</span> Exportar .md</button>`;
  }
  function renderMore(openIt) {
    const menu = overlay && overlay.querySelector(".ne-more-menu");
    const n = note();
    if (!menu || !n) return;
    menu.innerHTML = moreMenuInner(n);
    if (openIt !== undefined) menu.hidden = !openIt;
    if (moreView === "labels") { const f = menu.querySelector(".ne-lbl-new"); if (f) f.focus(); }
  }
  function toggleMore() {
    const menu = overlay.querySelector(".ne-more-menu");
    if (!menu) return;
    if (menu.hidden) { moreView = "menu"; renderMore(true); } else menu.hidden = true;
  }
  function closeMore() { const m = overlay && overlay.querySelector(".ne-more-menu"); if (m) m.hidden = true; moreView = "menu"; }
  function doConvert(type) {
    save();
    Api.convertNote(current, type).then((res) => { window.Notes.applyNoteUpdate(res.note); refresh(); window.App.render(); }).catch(() => window.App.toast("Não foi possível converter a nota."));
  }
  function toggleLabel(labelId) {
    const n = note(); if (!n) return;
    const ids = (n.labels || []).map((l) => String(l.id));
    const i = ids.indexOf(String(labelId));
    if (i >= 0) ids.splice(i, 1); else ids.push(String(labelId));
    Api.syncNoteLabels(current, ids.map(Number)).then((res) => { window.Notes.applyNoteUpdate(res.note); renderMore(); window.App.render(); }).catch(() => window.App.toast("Não foi possível atualizar etiquetas."));
  }
  function createLabel() {
    const inp = overlay.querySelector(".ne-lbl-new");
    const name = (inp && inp.value || "").trim(); if (!name) return;
    Api.createLabel(name).then((res) => { if (res.labels) window.state.labels = res.labels; if (res.label) toggleLabel(res.label.id); else renderMore(); }).catch((e) => window.App.toast((e.data && e.data.message) || "Não foi possível criar a etiqueta."));
  }
  function moveNotebook(nbId) {
    Api.moveNote(current, nbId).then((res) => { window.Notes.applyNoteUpdate(res.note); closeMore(); window.App.render(); window.App.toast("Nota movida"); }).catch(() => window.App.toast("Não foi possível mover a nota."));
  }
  function setPattern(pat) {
    const n = note(); if (n) n.pattern = pat || null;
    Api.updateNote(current, { pattern: pat || null }).then((res) => window.Notes.applyNoteUpdate(res.note)).catch(() => {});
    renderMore(); window.App.render();
  }
  // Re-monta o corpo/toolbar do editor a partir do estado atual (usado após converter).
  function refresh() {
    if (!overlay) return;
    const n = note(); if (!n) return;
    if (editor) { try { editor.destroy(); } catch (_) {} editor = null; }
    fallback = null;
    const ed = overlay.querySelector(".note-editor");
    if (ed) ed.outerHTML = shellHTML(n); else overlay.innerHTML = shellHTML(n);
    mountEditor(n);
    moreView = "menu";
  }

  function shellHTML(n) {
    const ro = n.permission === "view";
    const COLORS = window.Notes.COLORS, PALETTE = window.Notes.PALETTE;
    const ck = window.Notes.colorKey(n);
    const dots = PALETTE.map((c) => `<button class="ne-color${ck === c ? " on" : ""}" style="background:${COLORS[c]}" data-ne="color" data-color="${c}" title="${c}"></button>`).join("");
    return `
      <div class="note-editor" style="--note-bg:${COLORS[ck]}" data-stop>
        <div class="ne-head">
          <input class="ne-title" placeholder="Título" value="${U.esc(n.title || "")}"${ro ? " disabled" : ""}/>
          ${(n.collaborators || []).length ? `<span class="ne-shared" title="Compartilhada">${window.icon("User", 15)} ${(n.collaborators || []).length}</span>` : ""}
          <button class="ne-x" data-ne="close" title="Fechar"><span class="material-symbols-outlined">close</span></button>
        </div>
        ${ro || n.type === "checklist" ? "" : toolbarHTML()}
        <div class="ne-body-wrap scroll"><div class="ne-mount"></div></div>
        ${attHTML(n)}
        <input class="ne-tags" placeholder="tags separadas por vírgula" value="${U.esc(n.tags || "")}"${ro ? " disabled" : ""}/>
        <div class="ne-foot">
          <div class="ne-colors">${ro ? "" : dots}</div>
          <div class="ne-actions">${ro ? "" : actionsHTML(n)}</div>
        </div>
        ${ro ? `<div class="ne-ro-note">Somente leitura — compartilhada por <strong>${U.esc(n.ownerName || "")}</strong>.</div>` : ""}
      </div>`;
  }

  // ---------- checklist (notas do tipo lista) ----------
  function checklistHTML(n) {
    const ro = n.permission === "view";
    const items = (n.items || []);
    const rows = items.map((it) => `
      <li class="ne-ck-item${it.done ? " done" : ""}" data-item="${it.id}">
        <button class="ne-ck-check" data-ne="item-toggle" data-item="${it.id}"${ro ? " disabled" : ""}><span class="material-symbols-outlined">${it.done ? "check_box" : "check_box_outline_blank"}</span></button>
        <input class="ne-ck-text" data-item="${it.id}" value="${U.esc(it.text)}" placeholder="Item"${ro ? " disabled" : ""}/>
        ${ro ? "" : `<button class="ne-ck-del" data-ne="item-del" data-item="${it.id}" title="Remover"><span class="material-symbols-outlined">close</span></button>`}
      </li>`).join("");
    return `<ul class="ne-checklist">${rows}
      ${ro ? "" : `<li class="ne-ck-add"><span class="material-symbols-outlined">add</span><input class="ne-ck-new" placeholder="Adicionar item" maxlength="1000"/></li>`}
    </ul>`;
  }
  function mountChecklist(n) { const mount = overlay.querySelector(".ne-mount"); if (mount) mount.innerHTML = checklistHTML(n); }
  function refreshChecklist() { const n = note(); if (n && n.type === "checklist") mountChecklist(n); }
  function ckToggle(itemId) {
    const n = note(); const it = n && (n.items || []).find((x) => String(x.id) === String(itemId));
    if (!it) return;
    it.done = !it.done; refreshChecklist();
    Api.updateNoteItem(current, itemId, { done: it.done }).catch(() => {}); window.App.render();
  }
  function ckAdd(input) {
    const text = (input.value || "").trim(); if (!text) return; input.value = "";
    Api.createNoteItem(current, text).then((res) => {
      const n = note(); if (n && res.note) n.items = res.note.items || [];
      refreshChecklist(); const fresh = overlay && overlay.querySelector(".ne-ck-new"); if (fresh) fresh.focus(); window.App.render();
    }).catch(() => window.App.toast("Não foi possível adicionar o item."));
  }
  function ckText(itemId, value) {
    const n = note(); const it = n && (n.items || []).find((x) => String(x.id) === String(itemId));
    if (!it || it.text === value) return; it.text = value;
    Api.updateNoteItem(current, itemId, { text: value }).catch(() => {}); window.App.render();
  }
  function ckDel(itemId) {
    const n = note(); if (n) n.items = (n.items || []).filter((x) => String(x.id) !== String(itemId));
    refreshChecklist(); Api.deleteNoteItem(current, itemId).catch(() => {}); window.App.render();
  }

  // ---------- editor (TipTap ou fallback) ----------
  function mountEditor(n) {
    const mount = overlay.querySelector(".ne-mount");
    if (!mount) return;
    const ro = n.permission === "view";
    if (n.type === "checklist") { mountChecklist(n); return; }
    if (window.TipTap && window.TipTap.Editor) {
      const T = window.TipTap;
      const exts = [
        T.StarterKit,
        T.Underline,
        T.Highlight,
        T.Link.configure({ openOnClick: false, autolink: true }),
        T.TaskList,
        T.TaskItem.configure({ nested: true }),
        T.Placeholder.configure({ placeholder: "Escreva sua nota…" }),
      ];
      editor = new T.Editor({
        element: mount,
        extensions: exts,
        content: n.body || "",
        editable: !ro,
        autofocus: n.title ? "end" : false,
        onUpdate: () => { dirty = true; scheduleSave(); },
        onSelectionUpdate: refreshToolbar,
        onTransaction: refreshToolbar,
      });
      refreshToolbar();
    } else {
      // Fallback (TipTap indisponível): contenteditable exibe o HTML formatado
      // (negrito/listas), nunca as tags cruas. Preserva a formatação ao salvar.
      fallback = document.createElement("div");
      fallback.className = "ne-fallback note-body-edit";
      fallback.setAttribute("role", "textbox");
      fallback.setAttribute("aria-multiline", "true");
      fallback.dataset.ph = "Escreva sua nota…";
      fallback.innerHTML = U.sanitizeHtml(n.body || "");
      if (ro) {
        fallback.setAttribute("aria-readonly", "true");
      } else {
        fallback.setAttribute("contenteditable", "true");
        fallback.addEventListener("input", () => { dirty = true; scheduleSave(); });
      }
      mount.appendChild(fallback);
      // tenta montar o TipTap se ele carregar depois
      window.addEventListener("tiptap-ready", () => { if (overlay && fallback) { fallback.remove(); fallback = null; mountEditor(note() || n); } }, { once: true });
    }
  }

  function bodyValue() {
    if (editor) return editor.getHTML();
    if (fallback) return U.sanitizeHtml(fallback.innerHTML);
    return note() ? note().body : "";
  }

  function cmd(name) {
    if (!editor) return;
    const c = editor.chain().focus();
    switch (name) {
      case "bold": c.toggleBold().run(); break;
      case "italic": c.toggleItalic().run(); break;
      case "underline": c.toggleUnderline().run(); break;
      case "strike": c.toggleStrike().run(); break;
      case "highlight": c.toggleHighlight().run(); break;
      case "h1": c.toggleHeading({ level: 1 }).run(); break;
      case "h2": c.toggleHeading({ level: 2 }).run(); break;
      case "bulletList": c.toggleBulletList().run(); break;
      case "orderedList": c.toggleOrderedList().run(); break;
      case "taskList": c.toggleTaskList().run(); break;
      case "blockquote": c.toggleBlockquote().run(); break;
      case "codeBlock": c.toggleCodeBlock().run(); break;
      case "undo": c.undo().run(); break;
      case "redo": c.redo().run(); break;
      case "link": {
        const prev = editor.getAttributes("link").href || "";
        window.Modals.prompt({ title: "Inserir link", label: "Endereço (vazio para remover)", value: prev, placeholder: "https://", okText: "Aplicar" }).then((url) => {
          if (url === null) { editor.commands.focus(); return; }
          if (url === "") editor.chain().focus().unsetLink().run();
          else editor.chain().focus().extendMarkRange("link").setLink({ href: url }).run();
          refreshToolbar();
        });
        break;
      }
    }
    refreshToolbar();
  }

  function refreshToolbar() {
    if (!overlay || !editor) return;
    const map = { bold: ["bold"], italic: ["italic"], underline: ["underline"], strike: ["strike"], highlight: ["highlight"],
      h1: ["heading", { level: 1 }], h2: ["heading", { level: 2 }], bulletList: ["bulletList"], orderedList: ["orderedList"],
      taskList: ["taskList"], blockquote: ["blockquote"], codeBlock: ["codeBlock"], link: ["link"] };
    overlay.querySelectorAll(".ne-tb").forEach((b) => {
      const args = map[b.dataset.cmd];
      if (!args) return;
      let active = false;
      try { active = editor.isActive(...args); } catch (_) {}
      b.classList.toggle("on", active);
    });
  }

  // ---------- persistência ----------
  function scheduleSave() {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(() => save(), 700);
  }
  function save(done) {
    const n = note();
    if (!n || n.permission === "view") { if (done) done(); return; }
    if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
    const titleEl = overlay.querySelector(".ne-title");
    const tagsEl = overlay.querySelector(".ne-tags");
    const isChecklist = n.type === "checklist";
    const payload = {
      title: titleEl ? titleEl.value.trim() : (n.title || ""),
      tags: tagsEl ? tagsEl.value.trim() : (n.tags || ""),
    };
    if (!isChecklist) payload.body = bodyValue();
    // otimista no estado (mantém a lista coerente sem esperar a resposta)
    n.title = payload.title; n.tags = payload.tags; if (!isChecklist) n.body = payload.body;
    dirty = false;
    Api.updateNote(current, payload).then((res) => {
      window.Notes.applyNoteUpdate(res.note);
      if (done) done();
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível salvar a nota."); if (done) done(); });
  }

  function setColor(color) {
    const n = note();
    if (!n) return;
    n.color = color;
    overlay.querySelectorAll(".ne-color").forEach((d) => d.classList.toggle("on", d.dataset.color === color));
    const ed = overlay.querySelector(".note-editor");
    if (ed) ed.style.setProperty("--note-bg", window.Notes.COLORS[color] || "");
    Api.updateNote(current, { color }).then((res) => window.Notes.applyNoteUpdate(res.note)).catch(() => {});
  }

  // ---------- ações ----------
  function pickImage() {
    const input = document.createElement("input");
    input.type = "file"; input.accept = "image/*";
    input.onchange = () => { if (input.files && input.files[0]) window.Notes.uploadNoteAttachment(current, input.files[0]).then(() => refreshAttachments()); };
    input.click();
  }
  function refreshAttachments() {
    const n = note(); if (!n || !overlay) return;
    const old = overlay.querySelector(".ne-attachments");
    const fresh = attHTML(n);
    if (old) { old.outerHTML = fresh; }
    else if (fresh) { const wrap = overlay.querySelector(".ne-body-wrap"); if (wrap) wrap.insertAdjacentHTML("afterend", fresh); }
  }
  function deleteAtt(attId) {
    Api.deleteAttachment(attId).then(() => {
      const n = note();
      if (n) n.attachments = (n.attachments || []).filter((a) => String(a.id) !== String(attId));
      refreshAttachments();
      window.App.render();
    }).catch(() => window.App.toast("Não foi possível remover o anexo."));
  }
  function archive() {
    Api.archiveNote(current).then((res) => { window.Notes.applyNoteUpdate(res.note); window.App.toast(res.note.archivedAt ? "Nota arquivada" : "Nota desarquivada"); close(); }).catch(() => window.App.toast("Não foi possível arquivar."));
  }
  function del() {
    window.Modals.confirm({ title: "Mover para a lixeira", message: "Mover esta nota para a lixeira?", okText: "Mover", danger: true }).then((ok) => {
      if (!ok) return;
      Api.deleteNote(current).then(() => {
        window.state.notes = (window.state.notes || []).filter((x) => String(x.id) !== String(current));
        close(); window.App.render(); window.App.toast("Nota movida para a lixeira");
      }).catch(() => window.App.toast("Não foi possível excluir a nota."));
    });
  }

  // ---------- eventos ----------
  function onClick(e) {
    if (e.target === overlay) return close();
    // fecha o menu "Mais opções" ao clicar fora dele
    const menu = overlay.querySelector(".ne-more-menu");
    if (menu && !menu.hidden && !e.target.closest(".ne-more-wrap")) menu.hidden = true, moreView = "menu";
    const el = e.target.closest("[data-ne]");
    if (!el) return;
    const act = el.dataset.ne;
    if (act === "close") close();
    else if (act === "cmd") cmd(el.dataset.cmd);
    else if (act === "color") setColor(el.dataset.color);
    else if (act === "att-del") deleteAtt(el.dataset.att);
    else if (act === "attach") pickImage();
    else if (act === "draw") { if (window.NotesCanvas) window.NotesCanvas.open(current); }
    else if (act === "audio") { if (window.NotesAudio) window.NotesAudio.open(current); }
    else if (act === "reminder") { if (window.NotesReminder) window.NotesReminder.open(current); }
    else if (act === "collab") { if (window.NotesCollab) window.NotesCollab.open(current); }
    else if (act === "item-toggle") ckToggle(el.dataset.item);
    else if (act === "item-del") ckDel(el.dataset.item);
    else if (act === "archive") archive();
    else if (act === "delete") del();
    else if (act === "more") toggleMore();
    else if (act === "more-back") { moreView = "menu"; renderMore(true); }
    else if (act === "more-labels") { moreView = "labels"; renderMore(true); }
    else if (act === "more-notebook") { moreView = "notebook"; renderMore(true); }
    else if (act === "more-pattern") { moreView = "pattern"; renderMore(true); }
    else if (act === "more-convert") doConvert(el.dataset.type);
    else if (act === "more-copy") { closeMore(); if (window.NotesExport) window.NotesExport.copyNote(current); }
    else if (act === "more-export") { closeMore(); if (window.NotesExport) window.NotesExport.exportMd(current); }
    else if (act === "lbl-toggle") toggleLabel(el.dataset.label);
    else if (act === "lbl-create") createLabel();
    else if (act === "nb-move") moveNotebook(el.dataset.nb);
    else if (act === "pat-set") setPattern(el.dataset.pat);
  }
  function onInput(e) {
    if (e.target.classList.contains("ne-title") || e.target.classList.contains("ne-tags")) { dirty = true; scheduleSave(); }
  }
  function onChange(e) {
    if (e.target.classList.contains("ne-ck-text")) ckText(e.target.dataset.item, e.target.value);
  }
  function onCkKey(e) {
    if (e.key === "Enter" && e.target.classList.contains("ne-ck-new")) { e.preventDefault(); ckAdd(e.target); }
    else if (e.key === "Enter" && e.target.classList.contains("ne-lbl-new")) { e.preventDefault(); createLabel(); }
  }
  function onKey(e) {
    if (e.key === "Escape" && overlay) { e.preventDefault(); close(); }
  }

  function open(id) {
    const n = window.Notes.findNote(id);
    if (!n) return;
    if (overlay) close();
    current = String(id);
    overlay = document.createElement("div");
    overlay.className = "note-editor-overlay";
    overlay.innerHTML = shellHTML(n);
    document.body.appendChild(overlay);
    overlay.addEventListener("click", onClick);
    overlay.addEventListener("input", onInput);
    overlay.addEventListener("change", onChange);
    overlay.addEventListener("keydown", onCkKey);
    document.addEventListener("keydown", onKey);
    mountEditor(n);
    const t = overlay.querySelector(".ne-title");
    if (t && !n.title) t.focus();
  }

  function close() {
    if (!overlay) return;
    if (dirty) save();
    document.removeEventListener("keydown", onKey);
    if (editor) { try { editor.destroy(); } catch (_) {} editor = null; }
    fallback = null;
    overlay.remove();
    overlay = null;
    current = null;
    if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
    window.App.render();
  }

  // permite que outros módulos (anexos/desenho/áudio) atualizem o editor aberto
  window.NotesEditor = { open, close, isOpen: () => !!overlay, current: () => current, refreshAttachments, refresh };
})();
