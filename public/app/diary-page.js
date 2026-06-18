/* Página "Diário de Atividades".
   Linha do tempo (layout inspirado em modelo_Tarefas/ad.html, com os tokens do
   app): grupos por dia, cartões com ponto na timeline, avatar de tipo, chip de
   status, anexos (próprios e importados da tarefa) e histórico de auditoria.
   window.Render.diarioPageHTML() + delegação [data-diary-act]. */
(function () {
  const icon = window.icon;
  const U = window.UI;
  const TD = window.TaskData;
  const Api = TD.Api;
  const STATUS = TD.STATUS;
  const render = () => window.App.render();
  const toast = (t) => window.App.toast(t);

  // Tipos de atividade (item 9) — letra, rótulo e cor do avatar.
  const TYPES = {
    D: { label: "Desenvolvimento", color: "#4f46e5" },
    A: { label: "Análise", color: "#0ea5e9" },
    R: { label: "Reunião", color: "#f59e0b" },
    C: { label: "Correção", color: "#ef4444" },
    T: { label: "Teste", color: "#8b5cf6" },
    P: { label: "Planejamento", color: "#10b981" },
    S: { label: "Suporte", color: "#06b6d4" },
    V: { label: "Validação", color: "#22c55e" },
    E: { label: "Estudo", color: "#eab308" },
    O: { label: "Outra atividade", color: "#64748b" },
  };
  const WEEKDAYS = ["domingo", "segunda-feira", "terça-feira", "quarta-feira", "quinta-feira", "sexta-feira", "sábado"];

  const expanded = new Set();   // ids de cartões abertos (persiste entre renders)
  let typeFilter = null;        // filtro por letra de tipo

  // ---------- helpers ----------
  function entries() { return window.state.diaryEntries || []; }
  function findEntry(id) { return entries().find((e) => String(e.id) === String(id)); }
  function replaceEntry(entry) {
    const list = entries();
    const i = list.findIndex((e) => String(e.id) === String(entry.id));
    if (i >= 0) list[i] = entry; else list.unshift(entry);
  }
  function fail(e) { console.error(e); toast("Não foi possível concluir a ação."); }

  function fmtTime(iso) {
    if (!iso) return "";
    const d = new Date(iso);
    return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
  }
  function localDt(iso) {
    if (!iso) return "";
    const d = new Date(iso), p = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
  }
  function durLabel(min) {
    if (min == null) return "—";
    if (min < 60) return `${min} min`;
    const h = Math.floor(min / 60), m = min % 60;
    return m ? `${h}h ${m}min` : `${h}h`;
  }
  function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

  function dayLabel(iso) {
    const d = new Date(iso);
    const day = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
    const full = `${cap(WEEKDAYS[day.getDay()])}, ${day.getDate()} de ${TD.MONTHS[day.getMonth()]} de ${day.getFullYear()}`;
    if (day.getTime() === today.getTime()) return "Hoje · " + full;
    if (day.getTime() === yesterday.getTime()) return "Ontem · " + full;
    return full;
  }

  function groupByDay(list) {
    const sorted = list.slice().sort((a, b) => new Date(b.startedAt) - new Date(a.startedAt));
    const groups = [];
    sorted.forEach((e) => {
      const label = dayLabel(e.startedAt);
      let g = groups.find((x) => x.label === label);
      if (!g) { g = { label, items: [] }; groups.push(g); }
      g.items.push(e);
    });
    return groups;
  }

  function filtered() {
    let list = entries();
    const q = (window.state.query || "").trim().toLowerCase();
    if (q) list = list.filter((e) =>
      (e.description || "").toLowerCase().includes(q) ||
      (e.title || "").toLowerCase().includes(q) ||
      (e.taskTitle || "").toLowerCase().includes(q));
    if (typeFilter) list = list.filter((e) => (e.activityType || "O") === typeFilter);
    return list;
  }

  function statusOf(e) { return e.statusTo || (e.open ? "andamento" : null); }
  function statusChip(e) {
    const st = statusOf(e);
    if (!st || !STATUS[st]) return `<span class="diary-chip">${e.open ? "Em andamento" : "Registrado"}</span>`;
    const s = STATUS[st];
    return `<span class="diary-chip" style="color:${s.color};background:${s.bg}">${st === "aguardando" ? "Aguardando" : s.label}</span>`;
  }
  function movementText(e) {
    const lbl = (k) => (STATUS[k] ? STATUS[k].label : k);
    if (e.statusFrom && e.statusTo) return `${lbl(e.statusFrom)} → ${lbl(e.statusTo)}`;
    if (e.statusFrom && e.open) return `${lbl(e.statusFrom)} → Em andamento`;
    return "";
  }
  const clip = `<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>`;

  // ---------- render ----------
  function diarioPageHTML() {
    const list = filtered();
    if (!entries().length) {
      const searching = !!(window.state.query || "").trim();
      return `<div class="empty"><div class="empty-ico">${icon("BookOpen", 24)}</div>
        <h3>${searching ? "Nenhuma atividade encontrada" : "Nenhuma atividade ainda"}</h3>
        <p>${searching ? "Ajuste a busca ou crie uma nova atividade." : "Mova uma tarefa para <b>Em andamento</b> ou registre uma atividade manualmente."}</p>
        <button class="btn-primary" data-diary-act="new">${icon("Plus", 16)} Nova atividade</button>
      </div>`;
    }
    const types = Array.from(new Set(entries().map((e) => e.activityType || "O")));
    const chips = [`<button class="diary-filter${typeFilter ? "" : " active"}" data-diary-act="filter-type" data-type="">Todas</button>`]
      .concat(types.map((t) => `<button class="diary-filter${typeFilter === t ? " active" : ""}" data-diary-act="filter-type" data-type="${t}"><span class="diary-filter-dot" style="background:${(TYPES[t] || TYPES.O).color}"></span>${(TYPES[t] || TYPES.O).label}</button>`))
      .join("");
    const groups = groupByDay(list);
    const body = groups.length
      ? groups.map((g) => `
        <section class="diary-day">
          <div class="diary-day-head">${U.esc(g.label)}</div>
          <div class="diary-day-items">${g.items.map(cardHTML).join("")}</div>
        </section>`).join("")
      : `<div class="diary-none">Nenhuma atividade para este filtro.</div>`;
    return `<div class="diary-tl"><div class="diary-filters">${chips}</div>${body}</div>`;
  }

  function cardHTML(e) {
    const type = TYPES[e.activityType] || TYPES.O;
    const isOpen = expanded.has(String(e.id));
    const period = e.open ? `${fmtTime(e.startedAt)} – <span class="diary-live">em andamento</span>` : `${fmtTime(e.startedAt)} – ${fmtTime(e.endedAt)}`;
    const taskChip = e.taskId
      ? `<button class="diary-task" data-diary-act="open-task" data-id="${e.taskId}">${icon("Checklist", 12)} ${U.esc(e.projectName || "")}${e.taskId ? ` · #${e.taskId}` : ""}</button>`
      : `<span class="diary-task diary-task-manual">${icon("Pencil", 11)} Manual</span>`;
    const mov = movementText(e);
    const nAtt = (e.attachments || []).length;
    const srcTag = e.source === "auto_split" ? `<span class="diary-tag">continuação</span>` : (e.source === "auto" ? `<span class="diary-tag">automática</span>` : "");

    return `
      <article class="diary-card${isOpen ? " expanded" : ""}" data-id="${e.id}" style="--type:${type.color}">
        <div class="diary-card-head" data-diary-act="expand" data-id="${e.id}">
          <div class="diary-type-ava" style="background:${type.color}" title="${type.label}">${e.activityType || "O"}</div>
          <div class="diary-card-main">
            <div class="diary-card-titlerow">
              <span class="diary-card-title">${U.esc(e.title || e.taskTitle || "Atividade")}</span>
              ${statusChip(e)} ${srcTag}
            </div>
            <div class="diary-card-meta">
              <span class="diary-period">${icon("Clock", 13)} ${period}</span>
              <span class="diary-dur">${durLabel(e.durationMinutes)}</span>
              ${taskChip}
              ${e.movedBy ? `<span class="diary-by">${icon("User", 12)} ${U.esc(e.movedBy)}</span>` : ""}
              ${nAtt ? `<span class="diary-att-count">${clip} ${nAtt}</span>` : ""}
            </div>
            ${mov ? `<div class="diary-mov">${U.esc(mov)}</div>` : ""}
          </div>
          <span class="diary-chev">${icon(isOpen ? "ChevDown" : "ChevRight", 18)}</span>
        </div>
        ${isOpen ? detailHTML(e) : ""}
      </article>`;
  }

  function detailHTML(e) {
    const typeOpts = Object.keys(TYPES).map((k) => `<option value="${k}"${(e.activityType || "O") === k ? " selected" : ""}>${k} · ${TYPES[k].label}</option>`).join("");
    const field = (label, name, val, rows) => `
      <label class="diary-f">
        <span>${label}</span>
        <textarea data-f="${name}" rows="${rows || 2}" placeholder="…">${U.esc(val || "")}</textarea>
      </label>`;
    return `
      <div class="diary-detail">
        <div class="diary-grid2">
          <label class="diary-f"><span>Atividade</span><input data-f="title" value="${U.esc(e.title || "")}" placeholder="Nome da atividade" /></label>
          <label class="diary-f"><span>Tipo</span><select data-f="type">${typeOpts}</select></label>
          <label class="diary-f"><span>Início</span><input type="datetime-local" data-f="started_at" value="${localDt(e.startedAt)}" /></label>
          <label class="diary-f"><span>Término</span><input type="datetime-local" data-f="ended_at" value="${localDt(e.endedAt)}" /></label>
        </div>
        ${field("Descrição do trabalho", "description", e.description, 2)}
        <div class="diary-grid2">
          ${field("Observações", "observations", e.observations, 2)}
          ${field("Resultados", "results", e.results, 2)}
          ${field("Dificuldades", "difficulties", e.difficulties, 2)}
          ${field("Próximos passos", "next_steps", e.nextSteps, 2)}
        </div>
        <label class="diary-f diary-progress">
          <span>Progresso: <b data-progress-val>${e.progress == null ? 0 : e.progress}%</b></span>
          <input type="range" min="0" max="100" step="5" data-f="progress" value="${e.progress == null ? 0 : e.progress}" />
        </label>

        <div class="diary-att-block">
          <div class="diary-att-title">${clip} Anexos</div>
          <div class="diary-att-dyn">${attListHTML(e)}${importHTML(e)}</div>
          <div class="diary-att-actions">
            <button class="btn-ghost" data-diary-act="att-pick" data-id="${e.id}">${icon("Plus", 14)} Anexar arquivo</button>
            <input type="file" class="diary-att-file" data-id="${e.id}" hidden />
          </div>
        </div>

        ${historyHTML(e)}

        <div class="diary-detail-actions">
          <button class="btn-ghost danger" data-diary-act="delete" data-id="${e.id}">${icon("Trash", 15)} Excluir</button>
          <div style="flex:1"></div>
          ${e.open ? `<button class="btn-ghost" data-diary-act="close" data-id="${e.id}">${icon("Check", 14)} Encerrar agora</button>` : ""}
          <button class="btn-primary" data-diary-act="save" data-id="${e.id}">${icon("Check", 14)} Salvar</button>
        </div>
      </div>`;
  }

  function attListHTML(e) {
    const atts = e.attachments || [];
    if (!atts.length) return `<div class="diary-att-empty">Nenhum anexo.</div>`;
    return atts.map((a) => `
      <div class="diary-att">
        <a class="diary-att-link" href="${U.esc(a.url)}" target="_blank" rel="noopener">${clip} ${U.esc(a.name)}</a>
        <span class="diary-att-origin ${a.origin === "task" ? "from-task" : "from-diary"}">${a.origin === "task" ? "da tarefa" : "do diário"}</span>
        <button class="diary-att-del" data-diary-act="att-delete" data-id="${e.id}" data-att="${a.id}" title="Remover">${icon("Trash", 13)}</button>
      </div>`).join("");
  }

  // anexos da tarefa vinculada ainda não importados
  function importHTML(e) {
    if (!e.taskId) return "";
    const task = (window.state.tasks || []).find((t) => String(t.id) === String(e.taskId));
    const taskAtts = (task && task.attachments) || [];
    if (!taskAtts.length) return "";
    const importedSrc = (e.attachments || []).filter((a) => a.origin === "task").map((a) => String(a.sourceId));
    const avail = taskAtts.filter((a) => !importedSrc.includes(String(a.id)));
    if (!avail.length) return "";
    return `<div class="diary-import">
      <div class="diary-import-title">Anexos da tarefa disponíveis para importar:</div>
      ${avail.map((a) => `<button class="diary-import-btn" data-diary-act="att-import" data-id="${e.id}" data-att="${a.id}">${clip} ${U.esc(a.name)} <span>importar</span></button>`).join("")}
    </div>`;
  }

  function historyHTML(e) {
    const h = e.history || [];
    if (!h.length) return "";
    const rows = h.slice().reverse().map((x) => {
      const t = x.at ? new Date(x.at) : null;
      const when = t ? `${String(t.getDate()).padStart(2, "0")}/${String(t.getMonth() + 1).padStart(2, "0")} ${fmtTime(x.at)}` : "";
      return `<li><span class="diary-hist-when">${when}</span> <span class="diary-hist-by">${U.esc(x.by || "Sistema")}</span> — ${U.esc(x.description || x.action)}</li>`;
    }).join("");
    return `<details class="diary-hist"><summary>${icon("History", 14)} Histórico (${h.length})</summary><ul>${rows}</ul></details>`;
  }

  // ---------- ações ----------
  function createDiary() {
    Api.createDiary({}).then((res) => {
      replaceEntry(res.entry);
      window.state.diaryEntries = [res.entry, ...entries().filter((e) => String(e.id) !== String(res.entry.id))];
      expanded.add(String(res.entry.id));
      render();
    }).catch(fail);
  }

  function collectPayload(card) {
    const q = (sel) => card.querySelector(sel);
    const payload = {
      title: q('[data-f="title"]').value.trim() || null,
      activity_type: q('[data-f="type"]').value || null,
      description: q('[data-f="description"]').value,
      observations: q('[data-f="observations"]').value,
      results: q('[data-f="results"]').value,
      difficulties: q('[data-f="difficulties"]').value,
      next_steps: q('[data-f="next_steps"]').value,
      progress: q('[data-f="progress"]').value === "" ? null : +q('[data-f="progress"]').value,
    };
    const sv = q('[data-f="started_at"]').value, ev = q('[data-f="ended_at"]').value;
    if (sv) payload.started_at = new Date(sv).toISOString();
    if (ev) payload.ended_at = new Date(ev).toISOString();
    return payload;
  }

  function saveDiary(id, card) {
    Api.updateDiary(id, collectPayload(card)).then((res) => {
      replaceEntry(res.entry); render(); toast("Atividade salva");
    }).catch((e) => { if (e && e.status === 422) toast(msg422(e)); else fail(e); });
  }

  function closeDiary(id, card) {
    const payload = collectPayload(card);
    payload.ended_at = new Date().toISOString();
    Api.updateDiary(id, payload).then((res) => {
      replaceEntry(res.entry); render(); toast("Atividade encerrada");
    }).catch((e) => { if (e && e.status === 422) toast(msg422(e)); else fail(e); });
  }

  function deleteDiary(id) {
    if (!window.confirm("Excluir esta atividade do diário?")) return;
    Api.deleteDiary(id).then(() => {
      window.state.diaryEntries = entries().filter((e) => String(e.id) !== String(id));
      expanded.delete(String(id));
      render(); toast("Atividade excluída");
    }).catch(fail);
  }

  // atualiza só a sub-lista de anexos (preserva o texto não salvo no editor aberto)
  function refreshAtt(id) {
    const card = document.querySelector(`.diary-card[data-id="${id}"]`);
    const dyn = card && card.querySelector(".diary-att-dyn");
    const e = findEntry(id);
    if (dyn && e) dyn.innerHTML = attListHTML(e) + importHTML(e);
  }
  function uploadAtt(id, file) {
    Api.uploadAttachment("diary", id, file).then((res) => {
      const e = findEntry(id); if (e) { e.attachments = [res.attachment, ...(e.attachments || [])]; }
      refreshAtt(id);
    }).catch(fail);
  }
  function importAtt(id, attId) {
    Api.importDiaryAttachments(id, [+attId]).then((res) => {
      const e = findEntry(id); if (e) { e.attachments = [...(res.attachments || []), ...(e.attachments || [])]; }
      refreshAtt(id); toast("Anexo importado da tarefa");
    }).catch(fail);
  }
  function deleteAtt(id, attId) {
    Api.deleteAttachment(attId).then(() => {
      const e = findEntry(id); if (e) { e.attachments = (e.attachments || []).filter((a) => String(a.id) !== String(attId)); }
      refreshAtt(id);
    }).catch(fail);
  }

  function msg422(e) {
    const errs = e.data && e.data.errors;
    if (errs) { const k = Object.keys(errs)[0]; if (k) return errs[k][0]; }
    return "Verifique os horários da atividade.";
  }

  // ---------- delegação ----------
  document.addEventListener("click", (ev) => {
    const el = ev.target.closest("[data-diary-act]");
    if (!el) return;
    const act = el.dataset.diaryAct, id = el.dataset.id, card = el.closest(".diary-card");
    if (act === "new") createDiary();
    else if (act === "filter-type") { typeFilter = el.dataset.type || null; render(); }
    else if (act === "expand") { const k = String(id); expanded.has(k) ? expanded.delete(k) : expanded.add(k); render(); }
    else if (act === "open-task") window.Modal.open(id);
    else if (act === "save") saveDiary(id, card);
    else if (act === "close") closeDiary(id, card);
    else if (act === "delete") deleteDiary(id);
    else if (act === "att-pick") { const f = card.querySelector(".diary-att-file"); if (f) f.click(); }
    else if (act === "att-import") importAtt(id, el.dataset.att);
    else if (act === "att-delete") deleteAtt(id, el.dataset.att);
  });

  document.addEventListener("change", (ev) => {
    const f = ev.target.closest(".diary-att-file");
    if (f && f.files && f.files[0]) { uploadAtt(f.dataset.id, f.files[0]); f.value = ""; }
  });

  document.addEventListener("input", (ev) => {
    const r = ev.target.closest('[data-f="progress"]');
    if (r) { const lbl = r.closest(".diary-card").querySelector("[data-progress-val]"); if (lbl) lbl.textContent = r.value + "%"; }
  });

  window.Render.diarioPageHTML = diarioPageHTML;
})();
