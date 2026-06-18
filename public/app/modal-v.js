/* Vanilla Trello-style task modal. window.Modal.open(id) / close() */
(function () {
  const TD = window.TaskData;
  const { PRIORITY, STATUS, relTime, nid } = TD;
  const Api = TD.Api;
  const icon = window.icon;
  const U = window.UI;

  const STATUS_OPTS = ["pendente", "andamento", "aguardando", "concluido", "cancelado"];
  const PRIO_OPTS = ["urgente", "alta", "media", "baixa"];

  let draft = null, host = null, initialDesc = "";

  function projectOptions() {
    const list = ((window.state && window.state.projects) || []).map((p) => [p.slug, U.esc(p.name)]);
    list.push(["__new__", "+ Criar novo projeto…"]);
    return list;
  }

  function open(id) {
    const task = window.state.tasks.find((t) => t.id === id);
    if (!task) return;
    draft = JSON.parse(JSON.stringify(task));
    initialDesc = draft.description || "";
    host = document.getElementById("modalHost");
    host.innerHTML = shellHTML();
    const rte = host.querySelector(".rte");
    rte.innerHTML = draft.description || "";
    bind();
    renderChecklist();
    renderComments();
    renderAttachments();
  }

  function close() { if (host) host.innerHTML = ""; draft = null; }

  function selectHTML(value, opts) {
    return `<div class="select-wrap"><select>${opts.map(([v, l]) => `<option value="${v}"${v === value ? " selected" : ""}>${l}</option>`).join("")}</select><span class="chev">${icon("ChevDown", 16)}</span></div>`;
  }

  function shellHTML() {
    const done = draft.status === "concluido";
    const accent = PRIORITY[draft.priority] ? PRIORITY[draft.priority].color : "var(--accent)";
    return `
    <div class="modal-overlay">
      <div class="modal">
        <div class="modal-cover" style="background:${done ? STATUS.concluido.color : accent}"></div>
        <div class="modal-head">
          <button class="modal-headcheck${done ? " checked" : ""}" data-m="complete" title="${done ? "Reabrir" : "Concluir"}">${icon("CheckSmall", 15)}</button>
          <div class="modal-title-wrap">
            <input class="modal-title-input" value="${U.esc(draft.title)}" />
            <div class="modal-incat">na lista <b>${U.esc(U.sectionTitle(draft))}</b> · <span class="m-cat-label">${U.esc(U.projectName(draft.project))}</span></div>
          </div>
          <button class="modal-x" data-m="close">${icon("X", 18)}</button>
        </div>
        <div class="modal-body">
          <div class="modal-main scroll">
            <div class="m-block">
              <div class="m-label">${icon("List", 15)} Descrição <span class="rte-dirty" style="display:none">• não salvo</span></div>
              <div>
                <div class="rte-toolbar">
                  <button class="rte-btn" data-cmd="bold" title="Negrito">${icon("Bold", 16)}</button>
                  <button class="rte-btn" data-cmd="italic" title="Itálico">${icon("Italic", 16)}</button>
                  <button class="rte-btn" data-cmd="underline" title="Sublinhado">${icon("Underline", 16)}</button>
                  <button class="rte-btn" data-cmd="strikeThrough" title="Tachado">${icon("Strike", 16)}</button>
                  <span class="rte-sep"></span>
                  <button class="rte-btn" data-cmd="formatBlock" data-val="h3" title="Título">H</button>
                  <button class="rte-btn" data-cmd="insertUnorderedList" title="Lista">${icon("ListUl", 16)}</button>
                  <button class="rte-btn" data-cmd="insertOrderedList" title="Lista numerada">${icon("ListOl", 16)}</button>
                </div>
                <div class="rte" contenteditable="true" data-ph="Adicione uma descrição mais detalhada…"></div>
              </div>
            </div>
            <div class="m-block">
              <div class="m-label">${icon("Checklist", 15)} Checklist <span class="count cl-count"></span></div>
              <div class="cl-wrap"></div>
              <div class="cl-add">
                <input class="cl-input" placeholder="Adicionar item…" />
                <button data-m="cl-add">Adicionar</button>
              </div>
            </div>
            <div class="m-block">
              <div class="m-label">${icon("Comment", 15)} Comentários <span class="count cm-count"></span></div>
              <div class="cm-add">
                ${U.avatarHTML(TD.me, "cm-ava")}
                <div style="flex:1">
                  <textarea class="cm-input" placeholder="Escreva um comentário…"></textarea>
                  <div class="cm-add-btn" style="display:none;margin-top:8px"><button class="cl-add-btn" data-m="cm-add" style="padding:7px 13px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:12.5px;font-weight:700">Comentar</button></div>
                </div>
              </div>
              <div class="cm-list" style="margin-top:14px"></div>
            </div>
            <div class="m-block">
              <div class="m-label">${icon("Folder", 15)} Anexos <span class="count m-att-count"></span></div>
              <div class="m-att-list"></div>
              <div class="m-att-add">
                <button class="m-att-pick" data-m="att-pick" type="button">${icon("Plus", 14)} Anexar arquivo</button>
                <input type="file" class="m-att-file" hidden>
              </div>
            </div>
          </div>
          <div class="modal-side scroll">
            <div class="fld"><div class="fld-label">Status</div>${selectHTML(draft.status, STATUS_OPTS.map((s) => [s, STATUS[s].label]))}</div>
            <div class="fld"><div class="fld-label">Prioridade</div>${selectHTML(draft.priority, PRIO_OPTS.map((p) => [p, PRIORITY[p].label]))}</div>
            <div class="fld"><div class="fld-label">Data de entrega</div><input type="date" class="m-due" value="${draft.due || ""}" /></div>
            <div class="fld"><div class="fld-label">Projeto</div>${selectHTML(draft.project, projectOptions())}</div>
            <div class="fld"><div class="fld-label">Responsável</div><div class="resp-row"><div class="resp-ava">${U.initialsOf(draft.responsible)}</div><input class="txt m-resp" value="${U.esc(draft.responsible || "")}" /></div></div>
            <div style="border-top:1px solid var(--line);margin:8px 0 14px"></div>
            <div class="fld-label">${icon("History", 13, 'style="vertical-align:-2px;margin-right:4px"')} Histórico</div>
            <div class="hist">${histHTML()}</div>
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn-save" data-m="save">Salvar alterações</button>
          <button class="btn-cancel" data-m="close">Cancelar</button>
          <button class="btn-del-task" data-m="delete" title="Excluir tarefa">${icon("Trash", 17)}</button>
          <button class="btn-complete${draft.status === "concluido" ? " is-done" : ""}" data-m="complete">${icon("Check", 16)} ${draft.status === "concluido" ? "Reabrir tarefa" : "Marcar como concluída"}</button>
        </div>
      </div>
    </div>`;
  }

  function histHTML() {
    return [...(draft.history || [])].reverse().map((h) =>
      `<div class="hist-item"><span class="hist-dot"${h.by === "IA" ? ' style="border-color:#7c3aed"' : ""}></span>
        <div class="hist-text"><b>${U.esc(h.by === "IA" ? "Assistente" : h.by)}</b> ${h.action}</div>
        <div class="hist-time">${relTime(h.at)}</div></div>`).join("");
  }

  function renderChecklist() {
    const wrap = host.querySelector(".cl-wrap");
    const done = draft.checklist.filter((c) => c.done).length;
    const pct = draft.checklist.length ? Math.round((done / draft.checklist.length) * 100) : 0;
    host.querySelector(".cl-count").textContent = draft.checklist.length ? `· ${done}/${draft.checklist.length}` : "";
    wrap.innerHTML =
      (draft.checklist.length ? `<div class="cl-prog"><div class="cl-bar"><i style="width:${pct}%"></i></div><span class="cl-pct">${pct}%</span></div>` : "") +
      draft.checklist.map((c) => `<div class="cl-item${c.done ? " on" : ""}">
        <button class="cl-box${c.done ? " on" : ""}" data-m="cl-toggle" data-id="${c.id}">${icon("CheckSmall", 12)}</button>
        <span class="cl-text">${U.esc(c.text)}</span>
        <button class="cl-del" data-m="cl-del" data-id="${c.id}">${icon("Trash", 14)}</button>
      </div>`).join("");
  }

  function renderComments() {
    host.querySelector(".cm-count").textContent = draft.comments.length ? "· " + draft.comments.length : "";
    host.querySelector(".cm-list").innerHTML = [...draft.comments].reverse().map((c) => {
      const isAi = c.ai || c.author === "IA";
      const ava = isAi
        ? `<div class="cm-ava"><img src="${U.assistantAvatarUrl()}" alt=""></div>`
        : (c.avatarUrl ? `<div class="cm-ava"><img src="${U.esc(c.avatarUrl)}" alt=""></div>` : `<div class="cm-ava" style="background:${c.color}">${c.initials}</div>`);
      return `<div class="cm">${ava}
        <div class="cm-body">
          <div class="cm-head"><span class="cm-name">${U.esc(isAi ? U.assistantName() : c.author)}${c.ai ? ` <span style="color:var(--accent);font-size:11px;margin-left:5px">✦ ${U.esc(U.assistantName())}</span>` : ""}</span><span class="cm-time">${relTime(c.at)}</span></div>
          <div class="cm-text${c.ai ? " ai" : ""}">${U.esc(c.text)}</div>
        </div></div>`;
    }).join("");
  }

  function renderAttachments() {
    const list = host.querySelector(".m-att-list");
    const atts = draft.attachments || [];
    host.querySelector(".m-att-count").textContent = atts.length ? "· " + atts.length : "";
    list.innerHTML = atts.length
      ? atts.map((a) => `<div class="m-att">
          <a class="m-att-link" href="${U.esc(a.url)}" target="_blank" rel="noopener">${icon("Folder", 14)} ${U.esc(a.name)}</a>
          <button class="m-att-del" data-m="att-del" data-id="${a.id}" title="Remover">${icon("Trash", 13)}</button>
        </div>`).join("")
      : `<div class="m-att-empty">Nenhum anexo nesta tarefa.</div>`;
  }
  function syncStateAttachments() {
    const t = window.state.tasks.find((x) => String(x.id) === String(draft.id));
    if (t) t.attachments = draft.attachments;
  }
  function uploadAtt(file) {
    Api.uploadAttachment("task", draft.id, file).then((res) => {
      draft.attachments = [res.attachment, ...(draft.attachments || [])];
      syncStateAttachments(); renderAttachments();
    }).catch(() => window.App.toast("Não foi possível anexar o arquivo."));
  }
  function deleteAtt(attId) {
    Api.deleteAttachment(attId).then(() => {
      draft.attachments = (draft.attachments || []).filter((a) => String(a.id) !== String(attId));
      syncStateAttachments(); renderAttachments();
    }).catch(() => window.App.toast("Não foi possível remover o anexo."));
  }

  function setCover() {
    const done = draft.status === "concluido";
    const accent = PRIORITY[draft.priority] ? PRIORITY[draft.priority].color : "var(--accent)";
    host.querySelector(".modal-cover").style.background = done ? STATUS.concluido.color : accent;
    const hc = host.querySelector(".modal-headcheck");
    hc.classList.toggle("checked", done);
    const cbtn = host.querySelector(".btn-complete");
    cbtn.classList.toggle("is-done", done);
    cbtn.innerHTML = `${icon("Check", 16)} ${done ? "Reabrir tarefa" : "Marcar como concluída"}`;
  }

  function bind() {
    const overlay = host.querySelector(".modal-overlay");
    overlay.addEventListener("mousedown", (e) => { if (e.target === overlay) close(); });

    // title
    host.querySelector(".modal-title-input").addEventListener("input", (e) => { draft.title = e.target.value; });

    // RTE toolbar
    const rte = host.querySelector(".rte");
    host.querySelectorAll(".rte-btn").forEach((b) => {
      b.addEventListener("mousedown", (e) => {
        e.preventDefault();
        document.execCommand(b.dataset.cmd, false, b.dataset.val || null);
        rte.focus();
        markDirty();
      });
    });
    rte.addEventListener("input", markDirty);
    rte.addEventListener("blur", markDirty);

    // selects
    const sels = host.querySelectorAll(".modal-side select");
    sels[0].addEventListener("change", (e) => { draft.status = e.target.value; draft.completedAt = e.target.value === "concluido" ? TD.nowISO() : null; setCover(); });
    sels[1].addEventListener("change", (e) => { draft.priority = e.target.value; setCover(); });
    sels[2].addEventListener("change", (e) => {
      if (e.target.value === "__new__") {
        e.target.value = draft.project || "geral";
        window.openProjectModal({
          mode: "create",
          onCreated: (proj) => {
            draft.project = proj.slug;
            sels[2].innerHTML = projectOptions().map(([v, l]) => `<option value="${v}"${v === draft.project ? " selected" : ""}>${l}</option>`).join("");
            host.querySelector(".m-cat-label").textContent = U.projectName(draft.project);
          },
        });
        return;
      }
      draft.project = e.target.value;
      host.querySelector(".m-cat-label").textContent = U.projectName(draft.project);
    });
    host.querySelector(".m-due").addEventListener("change", (e) => { draft.due = e.target.value; });
    host.querySelector(".m-resp").addEventListener("input", (e) => { draft.responsible = e.target.value; host.querySelector(".resp-ava").textContent = U.initialsOf(e.target.value); });

    // checklist add
    const clInput = host.querySelector(".cl-input");
    clInput.addEventListener("keydown", (e) => { if (e.key === "Enter") addChk(); });

    // comment box show/hide button
    const cmInput = host.querySelector(".cm-input");
    cmInput.addEventListener("input", () => { host.querySelector(".cm-add-btn").style.display = cmInput.value.trim() ? "block" : "none"; });
    cmInput.addEventListener("keydown", (e) => { if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) addCmt(); });

    // anexos
    const attFile = host.querySelector(".m-att-file");
    attFile.addEventListener("change", () => { if (attFile.files && attFile.files[0]) { uploadAtt(attFile.files[0]); attFile.value = ""; } });

    // delegated modal actions
    overlay.addEventListener("click", (e) => {
      const el = e.target.closest("[data-m]");
      if (!el) return;
      const act = el.dataset.m;
      if (act === "close") close();
      else if (act === "save") save();
      else if (act === "delete") { window.App.deleteTask(draft.id); close(); }
      else if (act === "complete") toggleComplete();
      else if (act === "cl-add") addChk();
      else if (act === "cl-toggle") { const c = draft.checklist.find((x) => x.id === el.dataset.id); if (c) { c.done = !c.done; renderChecklist(); } }
      else if (act === "cl-del") { draft.checklist = draft.checklist.filter((x) => x.id !== el.dataset.id); renderChecklist(); }
      else if (act === "cm-add") addCmt();
      else if (act === "att-pick") host.querySelector(".m-att-file").click();
      else if (act === "att-del") deleteAtt(el.dataset.id);
    });

    // ESC to close
    document.addEventListener("keydown", onKey);
  }

  function onKey(e) { if (e.key === "Escape" && host && host.innerHTML) { close(); document.removeEventListener("keydown", onKey); } }

  function markDirty() {
    const rte = host.querySelector(".rte");
    draft.description = rte.innerHTML;
    host.querySelector(".rte-dirty").style.display = rte.innerHTML !== initialDesc ? "inline" : "none";
  }
  function addChk() {
    const inp = host.querySelector(".cl-input");
    const v = inp.value.trim(); if (!v) return;
    draft.checklist.push({ id: nid("c"), text: v, done: false });
    inp.value = ""; renderChecklist(); inp.focus();
  }
  function addCmt() {
    const inp = host.querySelector(".cm-input");
    const v = inp.value.trim(); if (!v) return;
    draft.comments.push({ id: nid("m"), author: TD.me.name, initials: TD.me.initials, color: TD.me.color, avatarUrl: TD.me.avatarUrl, text: v, at: TD.nowISO() });
    inp.value = ""; host.querySelector(".cm-add-btn").style.display = "none"; renderComments();
  }
  function toggleComplete() {
    const done = draft.status === "concluido";
    draft.status = done ? "pendente" : "concluido";
    draft.completedAt = done ? null : TD.nowISO();
    host.querySelector(".modal-side select").value = draft.status;
    setCover();
  }
  function save() {
    draft.description = host.querySelector(".rte").innerHTML;
    window.App.saveTask(JSON.parse(JSON.stringify(draft)));
    close();
  }

  window.Modal = { open, close };
})();
