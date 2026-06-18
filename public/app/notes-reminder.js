/* Modal de lembrete das Notas (Fase 4). window.NotesReminder.open(noteId) abre um
   <input type="datetime-local"> + recorrência; salva via Api.setNoteReminder.
   Visual M3 (.note-m3). */
(function () {
  const Api = window.TaskData.Api;
  let overlay = null;

  const RECUR = [
    { id: "", label: "Não repetir" },
    { id: "daily", label: "Diariamente" },
    { id: "weekly", label: "Semanalmente" },
    { id: "monthly", label: "Mensalmente" },
    { id: "yearly", label: "Anualmente" },
  ];

  function pad(x) { return String(x).padStart(2, "0"); }
  function toLocalInput(iso) {
    if (!iso) return "";
    const d = new Date(iso);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }
  function defaultWhen() {
    const d = new Date(Date.now() + 3600000); // +1h
    d.setMinutes(0, 0, 0);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  function close() {
    if (!overlay) return;
    document.removeEventListener("keydown", onKey);
    overlay.remove();
    overlay = null;
  }
  function onKey(e) { if (e.key === "Escape") close(); }

  function open(noteId) {
    const N = window.Notes;
    const note = N && N.findNote(noteId);
    if (!note) return;
    if (overlay) close();
    const val = note.remindAt ? toLocalInput(note.remindAt) : defaultWhen();
    const rec = note.remindRecurrence || "";

    overlay = document.createElement("div");
    overlay.className = "note-modal-overlay";
    overlay.innerHTML = `
      <div class="note-modal note-reminder-modal note-m3" role="dialog" aria-label="Lembrete">
        <div class="note-modal-head"><span class="material-symbols-outlined">schedule</span> Lembrete
          <button class="note-modal-x" data-act="cancel" title="Fechar"><span class="material-symbols-outlined">close</span></button>
        </div>
        <label class="note-reminder-field">Data e hora
          <input type="datetime-local" class="note-reminder-when" value="${val}" />
        </label>
        <label class="note-reminder-field">Repetir
          <select class="note-reminder-recur">
            ${RECUR.map((r) => `<option value="${r.id}"${r.id === rec ? " selected" : ""}>${r.label}</option>`).join("")}
          </select>
        </label>
        <div class="note-modal-actions">
          ${note.remindAt ? `<button class="note-btn-ghost note-reminder-remove" data-act="remove">Remover</button>` : ""}
          <div style="flex:1"></div>
          <button class="note-btn-ghost" data-act="cancel">Cancelar</button>
          <button class="note-btn-save" data-act="save"><span class="material-symbols-outlined" style="font-size:16px">check</span> Salvar</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) return close();
      const t = e.target.closest("[data-act]");
      if (!t) return;
      if (t.dataset.act === "cancel") close();
      else if (t.dataset.act === "save") save(noteId);
      else if (t.dataset.act === "remove") remove(noteId);
    });
    document.addEventListener("keydown", onKey);
    const input = overlay.querySelector(".note-reminder-when");
    if (input) input.focus();
  }

  function persist(noteId, payload) {
    Api.setNoteReminder(noteId, payload).then((res) => {
      window.Notes.applyNoteUpdate(res.note);
      close();
      window.App.render();
      window.App.toast(payload.remind_at ? "Lembrete definido" : "Lembrete removido");
    }).catch((e) => { console.error(e); window.App.toast("Não foi possível salvar o lembrete."); });
  }

  function save(noteId) {
    const when = overlay.querySelector(".note-reminder-when").value;
    if (!when) { window.App.toast("Escolha uma data e hora."); return; }
    const recur = overlay.querySelector(".note-reminder-recur").value || null;
    persist(noteId, { remind_at: new Date(when).toISOString(), remind_recurrence: recur });
  }

  function remove(noteId) { persist(noteId, { remind_at: null }); }

  window.NotesReminder = { open };
})();
