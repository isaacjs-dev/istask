/* Modal "Gerenciar Projetos". window.openProjectModal({mode,onCreated}) / window.closeProjectModal() */
(function () {
  const TD = window.TaskData;
  const icon = window.icon;
  const U = window.UI;
  const Api = TD.Api;

  let host = null, opts = {};

  function taskCount(slug) {
    return (window.state.tasks || []).filter((t) => t.project === slug).length;
  }

  function rowHTML(p) {
    const isGeral = p.slug === "geral";
    const ro = p.isOwner === false;
    const n = taskCount(p.slug);
    const shared = (p.members || []).length;
    const wsOptions = (window.state.workspaces || []).map((w) =>
      `<option value="${w.id}"${String(w.id) === String(p.workspaceId) ? " selected" : ""}>${U.esc(w.name)}</option>`).join("");
    const wsSelect = (! ro && (window.state.workspaces || []).length > 1)
      ? `<select class="proj-ws-select" title="Mover para área">${wsOptions}</select>` : "";
    return `
      <div class="set-opt proj-row" data-id="${p.id}" data-slug="${p.slug}">
        <span class="set-opt-ic">${icon(p.icon || "Folder", 18)}</span>
        <input class="proj-name-input" value="${U.esc(p.name)}"${(isGeral || ro) ? " disabled" : ""} />
        ${wsSelect}
        <span class="set-opt-sub" style="flex-shrink:0;white-space:nowrap">${n} ${n === 1 ? "tarefa" : "tarefas"}${shared ? ` · ${shared} 👥` : ""}${ro ? ` · de ${U.esc(p.ownerName || "")}` : ""}</span>
        <button class="modal-x" data-m="proj-share" title="${ro ? "Compartilhamento / Sair" : "Compartilhar"}">${icon("User", 16)}</button>
        ${ro ? "" : `<button class="modal-x" data-m="proj-save" title="Salvar">${icon("Check", 16)}</button>`}
        ${(ro || isGeral) ? "" : `<button class="modal-x" data-m="proj-del" title="Excluir">${icon("Trash", 16)}</button>`}
      </div>`;
  }

  function listHTML() {
    const aw = window.state.activeWorkspaceId;
    return (window.state.projects || []).filter((p) => !aw || String(p.workspaceId) === String(aw)).map(rowHTML).join("");
  }

  function shellHTML() {
    return `
    <div class="modal-overlay">
      <div class="set-modal">
        <div class="set-head">
          <div class="set-title">${icon("Folder", 18)} Projetos</div>
          <button class="modal-x" data-m="proj-close">${icon("X", 18)}</button>
        </div>
        <div class="set-body scroll">
          <div class="set-block">
            <div class="set-block-label">Novo projeto</div>
            <div class="proj-create-row">
              <input class="proj-new-input" placeholder="Nome do projeto…" />
              <button class="set-reset" data-m="proj-create">${icon("Plus", 15)} Criar</button>
            </div>
            <div class="proj-err"></div>
          </div>
          <div class="set-block">
            <div class="set-block-label">Projetos existentes</div>
            <div class="proj-list">${listHTML()}</div>
          </div>
        </div>
      </div>
    </div>`;
  }

  function open(o) {
    opts = o || {};
    host = document.getElementById("settingsHost");
    host.innerHTML = shellHTML();
    bind();
  }

  function close() {
    if (host) host.innerHTML = "";
    host = null;
    opts = {};
    document.removeEventListener("keydown", onKey);
  }

  function onKey(e) { if (e.key === "Escape") close(); }

  function showError(msg) {
    const err = host.querySelector(".proj-err");
    err.textContent = msg;
    err.style.display = msg ? "block" : "none";
  }

  function refreshList() {
    host.querySelector(".proj-list").innerHTML = listHTML();
  }

  function bind() {
    const overlay = host.querySelector(".modal-overlay");
    overlay.addEventListener("mousedown", (e) => { if (e.target === overlay) close(); });
    document.addEventListener("keydown", onKey);

    host.querySelector(".proj-new-input").addEventListener("keydown", (e) => {
      if (e.key === "Enter") createProject();
    });

    overlay.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && e.target.classList.contains("proj-name-input")) {
        saveProject(e.target.closest(".proj-row"));
      }
    });

    overlay.addEventListener("click", (e) => {
      const el = e.target.closest("[data-m]");
      if (!el) return;
      const act = el.dataset.m;
      if (act === "proj-close") close();
      else if (act === "proj-create") createProject();
      else if (act === "proj-save") saveProject(el.closest(".proj-row"));
      else if (act === "proj-del") deleteProject(el.closest(".proj-row"));
      else if (act === "proj-share" && window.openShareModal) window.openShareModal("project", el.closest(".proj-row").dataset.id);
    });

    overlay.addEventListener("change", (e) => {
      if (e.target.classList.contains("proj-ws-select")) {
        moveProjectToWorkspace(e.target.closest(".proj-row").dataset.id, e.target.value);
      }
    });
  }

  function moveProjectToWorkspace(id, workspaceId) {
    showError("");
    Api.moveProject(id, workspaceId).then((res) => {
      window.state.projects = res.projects;
      window.App.render();
      refreshList();
    }).catch((e) => showError((e.data && e.data.message) || "Não foi possível mover o projeto."));
  }

  function createProject() {
    const input = host.querySelector(".proj-new-input");
    const name = input.value.trim();
    if (!name) return;
    showError("");
    Api.createProject(name, window.state.activeWorkspaceId).then((res) => {
      window.state.projects = res.projects;
      input.value = "";
      window.App.render();
      if (opts.mode === "create") {
        if (opts.onCreated) opts.onCreated(res.project);
        close();
        return;
      }
      refreshList();
    }).catch((e) => {
      showError((e.data && e.data.message) || "Não foi possível criar o projeto.");
    });
  }

  function saveProject(row) {
    if (!row) return;
    const id = row.dataset.id;
    const input = row.querySelector(".proj-name-input");
    const name = input.value.trim();
    if (!name) return;
    showError("");
    Api.updateProject(id, { name }).then((res) => {
      window.state.projects = res.projects;
      refreshList();
      window.App.render();
    }).catch((e) => {
      showError((e.data && e.data.message) || "Não foi possível salvar o projeto.");
    });
  }

  function deleteProject(row) {
    if (!row) return;
    const id = row.dataset.id;
    const proj = (window.state.projects || []).find((p) => String(p.id) === String(id));
    const name = proj ? proj.name : "este projeto";
    window.Modals.confirm({ title: "Excluir projeto", message: `Excluir o projeto "${name}"? As tarefas dele vão para "Geral".`, okText: "Excluir", danger: true }).then((ok) => {
      if (!ok) return;
      Api.deleteProject(id).then((res) => {
        window.state.projects = res.projects;
        window.state.tasks = res.tasks;
        refreshList();
        window.App.render();
      }).catch((e) => {
        showError((e.data && e.data.message) || "Não foi possível excluir o projeto.");
      });
    });
  }

  window.openProjectModal = open;
  window.closeProjectModal = close;
})();
