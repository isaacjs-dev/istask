/* Modal de desenho das Notas (Fase 2). window.NotesCanvas.open(noteId) abre um
   <canvas> com paleta/espessura/borracha; ao salvar, exporta PNG e envia como anexo
   origin=drawing via window.Notes.uploadNoteAttachment. Visual M3 (.note-m3),
   isolado do post-it. */
(function () {
  const W = 600, H = 400;
  const COLORS = ["#202124", "#ea4335", "#fbbc04", "#34a853", "#4285f4", "#a142f4", "#ffffff"];
  let overlay = null, canvas = null, ctx = null;
  let drawing = false, color = "#202124", size = 4, eraser = false, lastX = 0, lastY = 0;

  function pos(e) {
    const r = canvas.getBoundingClientRect();
    return {
      x: (e.clientX - r.left) * (canvas.width / r.width),
      y: (e.clientY - r.top) * (canvas.height / r.height),
    };
  }
  function start(e) { drawing = true; const p = pos(e); lastX = p.x; lastY = p.y; e.preventDefault(); }
  function move(e) {
    if (!drawing) return;
    const p = pos(e);
    ctx.strokeStyle = eraser ? "#ffffff" : color;
    ctx.lineWidth = eraser ? size * 3 : size;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    lastX = p.x; lastY = p.y;
    e.preventDefault();
  }
  function stop() { drawing = false; }

  function clear() {
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
  }

  function close() {
    if (!overlay) return;
    document.removeEventListener("keydown", onKey);
    overlay.remove();
    overlay = canvas = ctx = null;
    drawing = false; eraser = false; color = "#202124"; size = 4;
  }
  function onKey(e) { if (e.key === "Escape") close(); }

  function toolbarHTML() {
    const swatches = COLORS.map((c) => `<button class="note-canvas-color${c === color ? " on" : ""}" data-c="${c}" style="background:${c}" title="${c}"></button>`).join("");
    return `
      <div class="note-canvas-tools">
        <div class="note-canvas-colors">${swatches}</div>
        <label class="note-canvas-size">Espessura
          <input type="range" min="1" max="20" value="${size}" class="note-canvas-range" />
        </label>
        <button class="note-canvas-tool" data-tool="eraser" title="Borracha"><span class="material-symbols-outlined">ink_eraser</span></button>
        <button class="note-canvas-tool" data-tool="clear" title="Limpar"><span class="material-symbols-outlined">delete</span></button>
      </div>`;
  }

  function open(noteId) {
    if (overlay) close();
    overlay = document.createElement("div");
    overlay.className = "note-modal-overlay";
    overlay.innerHTML = `
      <div class="note-modal note-m3" role="dialog" aria-label="Desenho">
        <div class="note-modal-head"><span class="material-symbols-outlined">brush</span> Desenho
          <button class="note-modal-x" data-act="cancel" title="Fechar"><span class="material-symbols-outlined">close</span></button>
        </div>
        ${toolbarHTML()}
        <div class="note-canvas-wrap"><canvas class="note-canvas" width="${W}" height="${H}"></canvas></div>
        <div class="note-modal-actions">
          <button class="note-btn-ghost" data-act="cancel">Cancelar</button>
          <button class="note-btn-save" data-act="save"><span class="material-symbols-outlined" style="font-size:16px">check</span> Salvar desenho</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    canvas = overlay.querySelector(".note-canvas");
    ctx = canvas.getContext("2d");
    clear();

    canvas.addEventListener("pointerdown", start);
    canvas.addEventListener("pointermove", move);
    window.addEventListener("pointerup", stop);

    overlay.addEventListener("click", (e) => {
      const t = e.target.closest("[data-act], [data-c], [data-tool]");
      if (e.target === overlay) { close(); return; }
      if (!t) return;
      if (t.dataset.act === "cancel") return close();
      if (t.dataset.act === "save") return save(noteId);
      if (t.dataset.c) { color = t.dataset.c; eraser = false; overlay.querySelectorAll(".note-canvas-color").forEach((b) => b.classList.toggle("on", b.dataset.c === color)); overlay.querySelector('[data-tool="eraser"]').classList.remove("on"); }
      if (t.dataset.tool === "eraser") { eraser = !eraser; t.classList.toggle("on", eraser); }
      if (t.dataset.tool === "clear") clear();
    });
    overlay.querySelector(".note-canvas-range").addEventListener("input", (e) => { size = +e.target.value; });
    document.addEventListener("keydown", onKey);
  }

  function save(noteId) {
    canvas.toBlob((blob) => {
      if (!blob) { close(); return; }
      const file = new File([blob], `desenho-${Date.now()}.png`, { type: "image/png" });
      close();
      if (window.Notes && window.Notes.uploadNoteAttachment) window.Notes.uploadNoteAttachment(noteId, file, "drawing");
    }, "image/png");
  }

  window.NotesCanvas = { open };
})();
