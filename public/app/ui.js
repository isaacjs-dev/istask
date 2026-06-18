/* Vanilla UI helpers: escaping, badges, meta pills, derived helpers */
(function () {
  const TD = window.TaskData;
  const { PRIORITY, STATUS, SECTIONS, fmtDue, fmtDueShort, isOverdue } = TD;
  const icon = window.icon;

  function esc(s) {
    return (s == null ? "" : String(s)).replace(/[&<>"']/g, (m) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[m]));
  }
  function stripHtml(html) {
    const d = document.createElement("div");
    d.innerHTML = html || "";
    return (d.textContent || "").trim();
  }

  function priorityBadge(id) {
    const p = PRIORITY[id]; if (!p) return "";
    return `<span class="badge" style="color:${p.color};background:${p.bg}"><span class="bdot" style="background:${p.color}"></span>${p.label}</span>`;
  }
  function statusBadge(id) {
    const s = STATUS[id]; if (!s) return "";
    const label = id === "aguardando" ? "Aguardando" : s.label;
    return `<span class="badge" style="color:${s.color};background:${s.bg}">${label}</span>`;
  }
  function duePill(task, short) {
    if (!task.due) return "";
    const over = isOverdue(task);
    return `<span class="meta-pill${over ? " overdue" : ""}">${icon("Calendar", 14)}${short ? fmtDueShort(task.due) : fmtDue(task.due)}${over ? ' <span style="font-weight:800">· Atrasada</span>' : ""}</span>`;
  }
  function checklistMini(cl) {
    if (!cl || !cl.length) return "";
    const done = cl.filter((c) => c.done).length;
    return `<span class="card-ic-item" title="Checklist">${icon("Checklist", 14.5)}${done}/${cl.length}</span>`;
  }
  function commentMini(cm) {
    if (!cm || !cm.length) return "";
    return `<span class="card-ic-item" title="Comentários">${icon("Comment", 14.5)}${cm.length}</span>`;
  }

  // slug -> nome do projeto (state.projects vem do boot/REST)
  function projectName(slug) {
    const list = (window.state && window.state.projects) || [];
    const p = list.find((x) => x.slug === slug || x.id === slug);
    return p ? p.name : "Geral";
  }

  // live section for list grouping
  function liveSection(t) {
    if (t.status === "concluido") return "concluidas";
    if (t._section) return t._section;
    if (t.section && t.section !== "concluidas") return t.section;
    const proj = t.project || "geral";
    if (t.priority === "urgente" || t.priority === "alta") {
      if (proj === "integracoes" || proj === "sistemas") return "integracoes";
      if (proj === "processos") return "projetos";
      return "prioridade";
    }
    if (proj === "integracoes" || proj === "sistemas") return "integracoes";
    if (proj === "processos") return "projetos";
    return "pendencias";
  }

  function sectionTitle(t) {
    const s = SECTIONS.find((x) => x.id === liveSection(t));
    return s ? s.title : "Geral";
  }

  function initialsOf(name) {
    if (!name) return "?";
    const p = name.trim().split(/\s+/);
    return ((p[0][0] || "") + (p[1] ? p[1][0] : "")).toUpperCase();
  }

  // avatar do usuário: foto enviada (img dentro do container, recorte via CSS), com fallback para iniciais coloridas
  function avatarHTML(person, cssClass) {
    if (person && person.avatarUrl) {
      return `<div class="${cssClass}"><img src="${esc(person.avatarUrl)}" alt="" /></div>`;
    }
    return `<div class="${cssClass}" style="background:${(person && person.color) || "var(--accent)"}">${esc((person && person.initials) || "")}</div>`;
  }

  // --- personalização do assistente de IA ---
  const ASSISTANT_AVATARS = ["default", "robot", "assistant", "person1", "person2", "person3", "comet", "owl", "bolt"];
  function assistantAvatarUrl() {
    const prefs = (window.state && window.state.prefs) || {};
    const id = ASSISTANT_AVATARS.includes(prefs.assistantAvatar) ? prefs.assistantAvatar : "default";
    const base = window.__BASE__ || "";
    return `${base}/app/assets/avatars/${id}.svg`;
  }
  function assistantName() {
    const prefs = (window.state && window.state.prefs) || {};
    return (prefs.assistantName && prefs.assistantName.trim()) || "Assistente";
  }

  window.UI = { esc, stripHtml, priorityBadge, statusBadge, duePill, checklistMini, commentMini, projectName, liveSection, sectionTitle, initialsOf, avatarHTML, ASSISTANT_AVATARS, assistantAvatarUrl, assistantName };
})();
