/* Modal "Gerenciar Áreas de Trabalho". window.openWorkspacesModal() — espelha o
   visual de projects-modal.js (mesmas classes .set-modal/.proj-row). Criar,
   renomear e excluir áreas. */
(function () {
  const TD = window.TaskData;
  const icon = window.icon;
  const U = window.UI;
  const Api = TD.Api;

  let host = null;

  function counts(id) {
    const proj = (window.state.projects || []).filter((p) => String(p.workspaceId) === String(id)).length;
    const cad = (window.state.notebooks || []).filter((n) => String(n.workspaceId) === String(id)).length;
    return `${proj} proj · ${cad} cad`;
  }

  function rowHTML(w) {
    const ro = w.isOwner === false;
    const shared = (w.members || []).length;
    return `
      <div class="set-opt proj-row" data-id="${w.id}">
        <span class="set-opt-ic">${icon(w.icon || "Folder", 18)}</span>
        <input class="proj-name-input" value="${U.esc(w.name)}"${ro ? " disabled" : ""} />
        <span class="set-opt-sub" style="flex-shrink:0;white-space:nowrap">${counts(w.id)}${shared ? ` · ${shared} 👥` : ""}${ro ? ` · de ${U.esc(w.ownerName || "")}` : ""}</span>
        <button class="modal-x" data-m="ws-share" title="${ro ? "Compartilhamento / Sair" : "Compartilhar"}">${icon("User", 16)}</button>
        ${ro ? "" : `<button class="modal-x" data-m="ws-save" title="Salvar">${icon("Check", 16)}</button>`}
        ${ro ? "" : `<button class="modal-x" data-m="ws-del" title="Excluir">${icon("Trash", 16)}</button>`}
      </div>`;
  }

  function listHTML() {
    return (window.state.workspaces || []).map(rowHTML).join("");
  }

  function shellHTML() {
    return `
    <div class="modal-overlay">
      <div class="set-modal">
        <div class="set-head">
          <div class="set-title">${icon("Folder", 18)} Áreas de Trabalho</div>
          <button class="modal-x" data-m="ws-close">${icon("X", 18)}</button>
        </div>
        <div class="set-body scroll">
          <div class="set-block">
            <div class="set-block-label">Nova área</div>
            <div class="proj-create-row">
              <input class="proj-new-input" placeholder="Nome da área…" />
              <button class="set-reset" data-m="ws-create">${icon("Plus", 15)} Criar</button>
            </div>
            <div class="proj-err"></div>
          </div>
          <div class="set-block">
            <div class="set-block-label">Áreas existentes</div>
            <div class="proj-list">${listHTML()}</div>
          </div>
        </div>
      </div>
    </div>`;
  }

  function open() {
    host = document.getElementById("settingsHost");
    host.innerHTML = shellHTML();
    bind();
  }
  function close() {
    if (host) host.innerHTML = "";
    host = null;
    document.removeEventListener("keydown", onKey);
  }
  function onKey(e) { if (e.key === "Escape") close(); }
  function showError(msg) {
    const err = host.querySelector(".proj-err");
    err.textContent = msg; err.style.display = msg ? "block" : "none";
  }
  function refreshList() { host.querySelector(".proj-list").innerHTML = listHTML(); }

  function bind() {
    const overlay = host.querySelector(".modal-overlay");
    overlay.addEventListener("mousedown", (e) => { if (e.target === overlay) close(); });
    document.addEventListener("keydown", onKey);
    host.querySelector(".proj-new-input").addEventListener("keydown", (e) => { if (e.key === "Enter") create(); });
    overlay.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && e.target.classList.contains("proj-name-input")) save(e.target.closest(".proj-row"));
    });
    overlay.addEventListener("click", (e) => {
      const el = e.target.closest("[data-m]");
      if (!el) return;
      const act = el.dataset.m;
      if (act === "ws-close") close();
      else if (act === "ws-create") create();
      else if (act === "ws-save") save(el.closest(".proj-row"));
      else if (act === "ws-del") remove(el.closest(".proj-row"));
      else if (act === "ws-share" && window.openShareModal) window.openShareModal("workspace", el.closest(".proj-row").dataset.id);
    });
  }

  function applyLists(res) {
    if (res.workspaces) window.state.workspaces = res.workspaces;
    if (res.projects) window.state.projects = res.projects;
    if (res.notebooks) window.state.notebooks = res.notebooks;
  }

  function create() {
    const input = host.querySelector(".proj-new-input");
    const name = input.value.trim();
    if (!name) return;
    showError("");
    Api.createWorkspace(name).then((res) => {
      applyLists(res);
      input.value = "";
      window.App.render();
      refreshList();
    }).catch((e) => showError((e.data && e.data.message) || "Não foi possível criar a área."));
  }

  function save(row) {
    if (!row) return;
    const id = row.dataset.id;
    const name = row.querySelector(".proj-name-input").value.trim();
    if (!name) return;
    showError("");
    Api.updateWorkspace(id, { name }).then((res) => {
      applyLists(res);
      window.App.render();
      refreshList();
    }).catch((e) => showError((e.data && e.data.message) || "Não foi possível salvar a área."));
  }

  function remove(row) {
    if (!row) return;
    const id = row.dataset.id;
    const w = (window.state.workspaces || []).find((x) => String(x.id) === String(id));
    window.Modals.confirm({ title: "Excluir área", message: `Excluir a área "${w ? w.name : ""}"? Projetos e cadernos dela vão para outra área.`, okText: "Excluir", danger: true }).then((ok) => {
      if (!ok) return;
      Api.deleteWorkspace(id).then((res) => {
        applyLists(res);
        if (String(window.state.activeWorkspaceId) === String(id)) {
          window.state.activeWorkspaceId = res.fallbackId || ((window.state.workspaces[0] || {}).id) || null;
          window.state.project = "geral";
          window.state.noteNotebook = null;
        }
        window.App.render();
        refreshList();
      }).catch((e) => showError((e.data && e.data.message) || "Não foi possível excluir a área."));
    });
  }

  window.openWorkspacesModal = open;
  window.closeWorkspacesModal = close;
})();
