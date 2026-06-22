/* ============================================================
   Minhas Tarefas — data layer (Laravel edition)
   Mantém as MESMAS constantes/ helpers do protótipo. A persistência em
   localStorage foi substituída por um cliente REST (window.TaskData.Api) e o
   estado inicial vem injetado em window.__BOOT__ pelo Blade.
   ============================================================ */
(function () {
  const BOOT = window.__BOOT__ || { tasks: [], messages: [], projects: [], me: {}, csrf: "" };

  const PRIORITY = {
    urgente: { id: "urgente", label: "Urgente", color: "var(--p-urgente)", bg: "var(--p-urgente-bg)", rank: 0 },
    alta:    { id: "alta",    label: "Alta",    color: "var(--p-alta)",    bg: "var(--p-alta-bg)",    rank: 1 },
    media:   { id: "media",   label: "Média",   color: "var(--p-media)",   bg: "var(--p-media-bg)",   rank: 2 },
    baixa:   { id: "baixa",   label: "Baixa",   color: "var(--p-baixa)",   bg: "var(--p-baixa-bg)",   rank: 3 },
  };

  const STATUS = {
    pendente:   { id: "pendente",   label: "Pendente",            short: "Pendente",   color: "var(--s-pendente)",   bg: "var(--s-pendente-bg)" },
    andamento:  { id: "andamento",  label: "Em andamento",        short: "Em andamento", color: "var(--s-andamento)",  bg: "var(--s-andamento-bg)" },
    aguardando: { id: "aguardando", label: "Aguardando terceiros", short: "Aguardando", color: "var(--s-aguardando)", bg: "var(--s-aguardando-bg)" },
    concluido:  { id: "concluido",  label: "Concluído",           short: "Concluído",  color: "var(--s-concluido)",  bg: "var(--s-concluido-bg)" },
    cancelado:  { id: "cancelado",  label: "Cancelado",           short: "Cancelado",  color: "var(--s-cancelado)",  bg: "var(--s-cancelado-bg)" },
  };

  // Kanban columns -> status
  const COLUMNS = [
    { id: "pendente",   name: "Pendente",             dot: "var(--s-pendente)" },
    { id: "andamento",  name: "Em andamento",         dot: "var(--s-andamento)" },
    { id: "aguardando", name: "Aguardando terceiros", dot: "var(--s-aguardando)" },
    { id: "concluido",  name: "Concluído",            dot: "var(--s-concluido)" },
  ];

  // List sections (ordem de exibição: "concluidas" sempre por último)
  const SECTIONS = [
    { id: "prioridade",  title: "Prioridade imediata",    color: "var(--sec-prioridade)" },
    { id: "pendencias",  title: "Pendências operacionais", color: "var(--sec-pendencias)" },
    { id: "projetos",    title: "Projetos e planejamento", color: "var(--sec-projetos)" },
    { id: "integracoes", title: "Integrações e sistemas",  color: "var(--sec-integracoes)" },
    { id: "concluidas",  title: "Tarefas concluídas",     color: "var(--sec-concluidas)" },
  ];

  const PROJECTS = (BOOT.projects && BOOT.projects.length ? BOOT.projects : [
    { slug: "geral", name: "Geral" }, { slug: "sistemas", name: "Sistemas" },
    { slug: "processos", name: "Processos" }, { slug: "integracoes", name: "Integrações" },
    { slug: "comunicacao", name: "Comunicação" },
  ]).map((p) => ({ id: p.slug, name: p.name }));

  const me = {
    name: (BOOT.me && BOOT.me.name) || "Você",
    email: (BOOT.me && BOOT.me.email) || "",
    bio: (BOOT.me && BOOT.me.bio) || "",
    initials: (BOOT.me && BOOT.me.initials) || "VC",
    avatarUrl: (BOOT.me && BOOT.me.avatarUrl) || null,
    color: "linear-gradient(135deg,#f59e0b,#ef4444)",
  };

  let _id = 1000;
  const nid = (p) => `${p}${++_id}`;

  function H(action, by) { return { id: nid("h"), action, by: by || "Você", at: nowISO() }; }
  function nowISO() { return new Date().toISOString(); }

  // --- date helpers (idênticos ao protótipo) ---
  const MONTHS = ["janeiro","fevereiro","março","abril","maio","junho","julho","agosto","setembro","outubro","novembro","dezembro"];
  const MONTHS_SHORT = ["jan","fev","mar","abr","mai","jun","jul","ago","set","out","nov","dez"];
  const TODAY = new Date("2026-06-08T09:00:00");

  function parseDue(d) { if (!d) return null; const [y, m, day] = d.split("-").map(Number); return new Date(y, m - 1, day); }
  function fmtDue(d) {
    if (!d) return "Sem prazo";
    const dt = parseDue(d);
    return `${String(dt.getDate()).padStart(2, "0")}/${String(dt.getMonth() + 1).padStart(2, "0")}/${dt.getFullYear()}`;
  }
  function fmtDueShort(d) { if (!d) return "Sem prazo"; const dt = parseDue(d); return `${String(dt.getDate()).padStart(2, "0")}/${String(dt.getMonth() + 1).padStart(2, "0")}`; }
  function isOverdue(t) {
    if (!t.due || t.status === "concluido" || t.status === "cancelado") return false;
    const dt = parseDue(t.due); const today = new Date(TODAY.getFullYear(), TODAY.getMonth(), TODAY.getDate());
    return dt < today;
  }
  function relTime(iso) {
    if (!iso) return "";
    const then = new Date(iso); const diff = (TODAY - then) / 1000;
    if (diff < 60) return "agora"; if (diff < 3600) return `há ${Math.floor(diff/60)} min`;
    if (diff < 86400) return `há ${Math.floor(diff/3600)} h`;
    const days = Math.floor(diff/86400);
    if (days < 30) return `há ${days} ${days === 1 ? "dia" : "dias"}`;
    const dt = new Date(iso); return `${String(dt.getDate()).padStart(2,"0")}/${String(dt.getMonth()+1).padStart(2,"0")}`;
  }

  const initialChat = () => ([
    { id: "ai0", role: "ai", text: "Olá! Sou seu assistente de tarefas. Escreva comandos em linguagem natural — posso <b>criar</b>, <b>concluir</b>, <b>priorizar</b>, <b>juntar</b> duplicadas e <b>reorganizar</b> tudo automaticamente." },
  ]);

  // --- REST client ---
  const Api = (function () {
    const base = (window.__BASE__ || "");
    function req(method, url, body) {
      return fetch(base + url, {
        method,
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-TOKEN": BOOT.csrf || "",
        },
        body: body ? JSON.stringify(body) : undefined,
      }).then(async (r) => {
        if (r.status === 401) { window.location.href = base + "/login"; throw new Error("Sessão expirada"); }
        const data = await r.json().catch(() => ({}));
        if (!r.ok) throw Object.assign(new Error("Falha na requisição"), { status: r.status, data });
        return data;
      });
    }
    return {
      createTask: (payload) => req("POST", "/api/tasks", payload || {}),
      saveTask: (id, payload) => req("PUT", `/api/tasks/${id}`, payload),
      deleteTask: (id) => req("DELETE", `/api/tasks/${id}`),
      toggleTask: (id) => req("POST", `/api/tasks/${id}/toggle`),
      moveTask: (id, status) => req("POST", `/api/tasks/${id}/move`, { status }),
      archiveTask: (id) => req("POST", `/api/tasks/${id}/archive`),
      duplicateTask: (id) => req("POST", `/api/tasks/${id}/duplicate`),
      updateTaskComment: (taskId, commentId, text) => req("PATCH", `/api/tasks/${taskId}/comments/${commentId}`, { text }),
      deleteTaskComment: (taskId, commentId) => req("DELETE", `/api/tasks/${taskId}/comments/${commentId}`),
      addTaskLink: (taskId, url, label) => req("POST", `/api/tasks/${taskId}/links`, { url, label: label || null }),
      removeTaskLink: (taskId, linkId) => req("DELETE", `/api/tasks/${taskId}/links/${linkId}`),
      addTaskRelation: (taskId, relatedId, type) => req("POST", `/api/tasks/${taskId}/relations`, { related_id: +relatedId, type }),
      removeTaskRelation: (taskId, relationId) => req("DELETE", `/api/tasks/${taskId}/relations/${relationId}`),
      tasksRemindersDue: () => req("GET", "/api/tasks/reminders/due"),
      command: (text, conversationId) => req("POST", "/api/ai/command", { text, conversation_id: conversationId ? +conversationId : null }),
      bootstrap: () => req("GET", "/api/bootstrap"),
      activities: (params) => req("GET", "/api/activities" + (params && Object.keys(params).length ? ("?" + new URLSearchParams(params).toString()) : "")),
      createProject: (name, workspaceId) => req("POST", "/api/projects", workspaceId ? { name, workspace_id: workspaceId } : { name }),
      updateProject: (id, payload) => req("PATCH", `/api/projects/${id}`, payload),
      deleteProject: (id) => req("DELETE", `/api/projects/${id}`),
      moveProject: (id, workspaceId) => req("POST", `/api/projects/${id}/move`, { workspace_id: workspaceId }),
      // Áreas de Trabalho
      createWorkspace: (name) => req("POST", "/api/workspaces", { name }),
      updateWorkspace: (id, payload) => req("PATCH", `/api/workspaces/${id}`, payload),
      deleteWorkspace: (id) => req("DELETE", `/api/workspaces/${id}`),
      reorderWorkspaces: (ids) => req("POST", "/api/workspaces/reorder", { ids }),
      // Compartilhamento (Fase 2)
      addWorkspaceMember: (id, email, permission) => req("POST", `/api/workspaces/${id}/members`, { email, permission }),
      removeWorkspaceMember: (id, userId) => req("DELETE", `/api/workspaces/${id}/members/${userId}`),
      transferWorkspace: (id, userId) => req("POST", `/api/workspaces/${id}/transfer`, { user_id: userId }),
      addProjectMember: (id, email, permission) => req("POST", `/api/projects/${id}/members`, { email, permission }),
      removeProjectMember: (id, userId) => req("DELETE", `/api/projects/${id}/members/${userId}`),
      transferProject: (id, userId) => req("POST", `/api/projects/${id}/transfer`, { user_id: userId }),
      addNotebookMember: (id, email, permission) => req("POST", `/api/notebooks/${id}/members`, { email, permission }),
      removeNotebookMember: (id, userId) => req("DELETE", `/api/notebooks/${id}/members/${userId}`),
      // Cadernos
      createNotebook: (name, workspaceId, color) => req("POST", "/api/notebooks", { name, workspace_id: workspaceId, color: color || null }),
      updateNotebook: (id, payload) => req("PATCH", `/api/notebooks/${id}`, payload),
      uploadNotebookCover: (id, file) => upload(`/api/notebooks/${id}/cover`, { cover: file }),
      deleteNotebook: (id) => req("DELETE", `/api/notebooks/${id}`),
      reorderNotebooks: (ids) => req("POST", "/api/notebooks/reorder", { ids }),
      moveNote: (id, notebookId) => req("POST", `/api/notes/${id}/move`, { notebook_id: notebookId }),
      createNote: (payload) => req("POST", "/api/notes", payload || {}),
      getNote: (id) => req("GET", `/api/notes/${id}`),
      updateNote: (id, payload) => req("PATCH", `/api/notes/${id}`, payload),
      deleteNote: (id) => req("DELETE", `/api/notes/${id}`),
      pinNote: (id) => req("POST", `/api/notes/${id}/pin`),
      archiveNote: (id) => req("POST", `/api/notes/${id}/archive`),
      restoreNote: (id) => req("POST", `/api/notes/${id}/restore`),
      forceDeleteNote: (id) => req("DELETE", `/api/notes/${id}/force`),
      trashNotes: () => req("GET", "/api/notes/trash"),
      syncNoteLabels: (id, labelIds) => req("POST", `/api/notes/${id}/labels`, { label_ids: labelIds }),
      convertNote: (id, type) => req("POST", `/api/notes/${id}/convert`, { type }),
      setNoteReminder: (id, payload) => req("POST", `/api/notes/${id}/reminder`, payload),
      noteReminders: () => req("GET", "/api/notes/reminders"),
      remindersDue: () => req("GET", "/api/notes/reminders/due"),
      addCollaborator: (id, email, permission) => req("POST", `/api/notes/${id}/collaborators`, { email, permission }),
      removeCollaborator: (id, userId) => req("DELETE", `/api/notes/${id}/collaborators/${userId}`),
      createNoteItem: (id, text) => req("POST", `/api/notes/${id}/items`, { text }),
      updateNoteItem: (id, itemId, payload) => req("PATCH", `/api/notes/${id}/items/${itemId}`, payload),
      deleteNoteItem: (id, itemId) => req("DELETE", `/api/notes/${id}/items/${itemId}`),
      reorderNoteItems: (id, ids) => req("POST", `/api/notes/${id}/items/reorder`, { ids }),
      createLabel: (name) => req("POST", "/api/labels", { name }),
      updateLabel: (id, name) => req("PATCH", `/api/labels/${id}`, { name }),
      deleteLabel: (id) => req("DELETE", `/api/labels/${id}`),
      createDiary: (payload) => req("POST", "/api/diary", payload || {}),
      updateDiary: (id, payload) => req("PATCH", `/api/diary/${id}`, payload),
      deleteDiary: (id) => req("DELETE", `/api/diary/${id}`),
      newConversation: () => req("POST", "/api/conversations"),
      updateConversation: (id, payload) => req("PATCH", `/api/conversations/${id}`, payload),
      conversationMessages: (id) => req("GET", `/api/conversations/${id}/messages`),
      savePrefs: (prefs) => req("PUT", "/api/preferences", prefs),
      // Avisos in-app (sino)
      notifications: () => req("GET", "/api/notifications"),
      markNotificationRead: (id) => req("POST", `/api/notifications/${id}/read`),
      markAllNotificationsRead: () => req("POST", "/api/notifications/read-all"),
      updateProfile: (data) => req("PATCH", "/api/profile", data),
      uploadAvatar: (file) => upload("/api/profile/avatar", { avatar: file }),
      // --- Diário de Atividades / anexos ---
      diaryList: () => req("GET", "/api/diary"),
      uploadAttachment: (attachableType, attachableId, file, origin, onProgress) =>
        upload("/api/attachments", Object.assign(
          { attachable_type: attachableType, attachable_id: attachableId, file },
          origin ? { origin } : {}
        ), onProgress),
      importDiaryAttachments: (entryId, ids) => req("POST", `/api/diary/${entryId}/attachments/import`, { attachment_ids: ids }),
      deleteAttachment: (id) => req("DELETE", `/api/attachments/${id}`),
    };

    /** Upload multipart via XHR (suporta progresso). onProgress(percent 0-100) é opcional. */
    function upload(url, fields, onProgress) {
      return new Promise((resolve, reject) => {
        const form = new FormData();
        Object.keys(fields).forEach((k) => form.append(k, fields[k]));
        const xhr = new XMLHttpRequest();
        xhr.open("POST", base + url);
        xhr.setRequestHeader("Accept", "application/json");
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhr.setRequestHeader("X-CSRF-TOKEN", BOOT.csrf || "");
        if (typeof onProgress === "function" && xhr.upload) {
          xhr.upload.addEventListener("progress", (e) => {
            if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100));
          });
        }
        xhr.onload = () => {
          if (xhr.status === 401) { window.location.href = base + "/login"; reject(new Error("Sessão expirada")); return; }
          let data = {};
          try { data = JSON.parse(xhr.responseText || "{}"); } catch (e) {}
          if (xhr.status >= 200 && xhr.status < 300) resolve(data);
          else reject(Object.assign(new Error("Falha na requisição"), { status: xhr.status, data }));
        };
        xhr.onerror = () => reject(Object.assign(new Error("Falha de rede"), { status: 0 }));
        xhr.send(form);
      });
    }
  })();

  window.TaskData = {
    PRIORITY, STATUS, COLUMNS, SECTIONS, PROJECTS, me,
    MONTHS, MONTHS_SHORT, TODAY,
    initialChat, nid, H, nowISO,
    parseDue, fmtDue, fmtDueShort, isOverdue, relTime,
    boot: BOOT, Api,
  };
})();
