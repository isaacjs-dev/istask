/* Modal de gravação de áudio das Notas (Fase 2). window.NotesAudio.open(noteId)
   usa MediaRecorder para gravar voz; ao salvar, envia o blob como anexo de áudio
   via window.Notes.uploadNoteAttachment. Sem transcrição (decisão do projeto).
   Visual M3 (.note-m3). */
(function () {
  let overlay = null, stream = null, recorder = null, chunks = [], blob = null, url = null, timer = null, seconds = 0;

  function supported() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  }

  function fmt(s) { const m = Math.floor(s / 60); const r = s % 60; return `${m}:${String(r).padStart(2, "0")}`; }

  function extFor(mime) {
    if (/webm/.test(mime)) return "webm";
    if (/ogg/.test(mime)) return "ogg";
    if (/mp4|m4a|aac/.test(mime)) return "m4a";
    if (/wav/.test(mime)) return "wav";
    if (/mpeg/.test(mime)) return "mp3";
    return "webm";
  }

  function setState(state) {
    if (!overlay) return;
    overlay.querySelector(".note-audio-body").innerHTML = bodyHTML(state);
    const t = overlay.querySelector(".note-audio-time");
    if (t) t.textContent = fmt(seconds);
  }

  function bodyHTML(state) {
    if (state === "recording") {
      return `<div class="note-audio-rec"><span class="note-audio-dot"></span><span class="note-audio-time">${fmt(seconds)}</span></div>
        <button class="note-audio-btn stop" data-act="stop"><span class="material-symbols-outlined">stop</span> Parar</button>`;
    }
    if (state === "recorded") {
      return `<audio class="note-audio-preview" controls src="${url}"></audio>
        <button class="note-audio-btn" data-act="rerecord"><span class="material-symbols-outlined">refresh</span> Regravar</button>`;
    }
    return `<button class="note-audio-btn rec" data-act="start"><span class="material-symbols-outlined">mic</span> Gravar</button>`;
  }

  function tick() { seconds++; const t = overlay && overlay.querySelector(".note-audio-time"); if (t) t.textContent = fmt(seconds); }

  function startRec() {
    navigator.mediaDevices.getUserMedia({ audio: true }).then((s) => {
      stream = s;
      chunks = []; blob = null; seconds = 0;
      recorder = new MediaRecorder(stream);
      recorder.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
      recorder.onstop = () => {
        const type = recorder.mimeType || "audio/webm";
        blob = new Blob(chunks, { type });
        if (url) URL.revokeObjectURL(url);
        url = URL.createObjectURL(blob);
        setState("recorded");
      };
      recorder.start();
      setState("recording");
      timer = setInterval(tick, 1000);
    }).catch((e) => {
      console.error(e);
      window.App.toast("Não foi possível acessar o microfone.");
      close();
    });
  }

  function stopRec() {
    if (timer) { clearInterval(timer); timer = null; }
    if (recorder && recorder.state !== "inactive") recorder.stop();
    stopStream();
  }
  function stopStream() { if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; } }

  function save(noteId) {
    if (!blob) { window.App.toast("Grave um áudio antes de salvar."); return; }
    const type = blob.type || "audio/webm";
    const file = new File([blob], `gravacao-${Date.now()}.${extFor(type)}`, { type });
    close();
    if (window.Notes && window.Notes.uploadNoteAttachment) window.Notes.uploadNoteAttachment(noteId, file, "own");
  }

  function close() {
    if (timer) { clearInterval(timer); timer = null; }
    if (recorder && recorder.state !== "inactive") { try { recorder.stop(); } catch (_) {} }
    stopStream();
    if (url) { URL.revokeObjectURL(url); url = null; }
    document.removeEventListener("keydown", onKey);
    if (overlay) overlay.remove();
    overlay = null; recorder = null; chunks = []; blob = null; seconds = 0;
  }
  function onKey(e) { if (e.key === "Escape") close(); }

  function open(noteId) {
    if (!supported()) { window.App.toast("Gravação de áudio não suportada neste navegador."); return; }
    if (overlay) close();
    overlay = document.createElement("div");
    overlay.className = "note-modal-overlay";
    overlay.innerHTML = `
      <div class="note-modal note-audio-modal note-m3" role="dialog" aria-label="Gravar áudio">
        <div class="note-modal-head"><span class="material-symbols-outlined">mic</span> Gravar áudio
          <button class="note-modal-x" data-act="cancel" title="Fechar"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="note-audio-body">${bodyHTML("idle")}</div>
        <div class="note-modal-actions">
          <button class="note-btn-ghost" data-act="cancel">Cancelar</button>
          <button class="note-btn-save" data-act="save"><span class="material-symbols-outlined" style="font-size:16px">check</span> Salvar</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) return close();
      const t = e.target.closest("[data-act]");
      if (!t) return;
      const act = t.dataset.act;
      if (act === "cancel") close();
      else if (act === "save") save(noteId);
      else if (act === "start" || act === "rerecord") startRec();
      else if (act === "stop") stopRec();
    });
    document.addEventListener("keydown", onKey);
  }

  window.NotesAudio = { open };
})();
