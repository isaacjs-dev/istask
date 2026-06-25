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
  /**
   * Sanitiza HTML para exibição segura via innerHTML (allowlist). Cobre a saída do
   * editor rico (TipTap: StarterKit + Underline/Highlight/Link/TaskList) e o corpo
   * legado. Tags não permitidas e não perigosas são "desembrulhadas" (preserva o
   * texto); script/style/iframe/etc. são removidas por completo. Defesa em
   * profundidade — o body também é sanitizado no servidor (HtmlSanitizer::clean).
   */
  const SANITIZE_ALLOW = {
    P: [], BR: [], DIV: [], SPAN: [],
    STRONG: [], B: [], EM: [], I: [], U: [], S: [], MARK: [], CODE: [], PRE: [],
    H1: [], H2: [], H3: [], H4: [], H5: [], H6: [],
    UL: ["data-type"], OL: ["data-type", "start"], LI: ["data-type", "data-checked"],
    BLOCKQUOTE: [], HR: [], A: ["href"], LABEL: [], INPUT: ["type", "checked"],
  };
  const SANITIZE_DROP = new Set(["SCRIPT", "STYLE", "IFRAME", "OBJECT", "EMBED", "FORM", "LINK", "META", "BASE", "HEAD", "TITLE"]);
  function sanitizeHtml(html) {
    if (html == null || html === "") return "";
    const doc = new DOMParser().parseFromString(String(html), "text/html");
    const clean = (node) => {
      Array.from(node.childNodes).forEach((child) => {
        if (child.nodeType === 8) { child.remove(); return; }      // comentário
        if (child.nodeType !== 1) return;                          // texto: mantém
        const tag = child.tagName;
        if (SANITIZE_DROP.has(tag)) { child.remove(); return; }
        if (!Object.prototype.hasOwnProperty.call(SANITIZE_ALLOW, tag)) {
          // tag não permitida (não perigosa): desembrulha, preserva o conteúdo
          clean(child);
          const parent = child.parentNode;
          while (child.firstChild) parent.insertBefore(child.firstChild, child);
          parent.removeChild(child);
          return;
        }
        if (tag === "INPUT" && (child.getAttribute("type") || "").toLowerCase() !== "checkbox") {
          child.remove(); return;
        }
        const allowed = SANITIZE_ALLOW[tag];
        Array.from(child.attributes).forEach((attr) => {
          const name = attr.name.toLowerCase();
          if (allowed.indexOf(name) === -1) { child.removeAttribute(attr.name); return; }
          if (name === "href" && /^\s*(javascript|vbscript|data):/i.test(attr.value || "")) {
            child.removeAttribute(attr.name);
          }
        });
        if (tag === "INPUT") child.setAttribute("disabled", "");   // checkbox apenas leitura na exibição
        clean(child);
      });
    };
    clean(doc.body);
    return doc.body.innerHTML;
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
  const RECUR_LABEL = { daily: "Diária", weekly: "Semanal", monthly: "Mensal" };
  function recurMini(t) {
    if (!t.recurrence || t.recurrence === "none") return "";
    return `<span class="card-ic-item" title="Recorrência: ${RECUR_LABEL[t.recurrence] || ""}">${icon("Refresh", 14)}</span>`;
  }
  function remindMini(t) {
    if (!t.remindAt) return "";
    const over = new Date(t.remindAt) <= TD.TODAY && t.status !== "concluido";
    return `<span class="card-ic-item${over ? " overdue" : ""}" title="Lembrete${over ? " vencido" : ""}">${icon("Bell", 14)}</span>`;
  }
  function labelChips(labels) {
    if (!labels || !labels.length) return "";
    const shown = labels.slice(0, 4);
    return `<div class="task-labels">${shown.map((l) => `<span class="task-label-chip">${icon("Tag", 10)}${esc(l.name)}</span>`).join("")}${labels.length > 4 ? `<span class="task-label-chip more">+${labels.length - 4}</span>` : ""}</div>`;
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

  // --- responsável: pessoas com acesso à tarefa (sugestões do combobox) ---
  // Une dono+membros da Área (achada pelo workspaceId do projeto) + dono+membros do
  // próprio projeto + o usuário atual. Deduplicado por nome (resolução é por nome).
  function taskPeople(projectSlug) {
    const st = window.state || {};
    const proj = (st.projects || []).find((p) => p.slug === projectSlug || String(p.id) === String(projectSlug));
    const ws = proj ? (st.workspaces || []).find((w) => String(w.id) === String(proj.workspaceId)) : null;
    const out = [];
    const seen = new Set();
    const add = (name, avatarUrl) => {
      const n = (name == null ? "" : String(name)).trim();
      if (!n) return;
      const k = n.toLowerCase();
      if (seen.has(k)) return;
      seen.add(k);
      out.push({ name: n, initials: initialsOf(n), avatarUrl: avatarUrl || null });
    };
    if (ws) { add(ws.ownerName, ws.ownerAvatarUrl); (ws.members || []).forEach((m) => add(m.name, m.avatarUrl)); }
    if (proj) { add(proj.ownerName, proj.ownerAvatarUrl); (proj.members || []).forEach((m) => add(m.name, m.avatarUrl)); }
    const me = (window.TaskData && window.TaskData.me) || null;
    if (me) add(me.name, me.avatarUrl);
    return out;
  }
  // <datalist> nativo com as pessoas-com-acesso: dá autocomplete e mantém texto livre.
  function peopleDatalistHTML(id, people) {
    return `<datalist id="${id}">${(people || []).map((p) => `<option value="${esc(p.name)}"></option>`).join("")}</datalist>`;
  }
  // resolve um nome contra a lista; mostra avatar (vinculado), iniciais (externo) ou vazio.
  // "Você" (convenção do app) e o próprio nome do usuário resolvem para o usuário atual.
  function respAvatarHTML(name, people, cssClass) {
    const n = (name == null ? "" : String(name)).trim();
    if (!n) return `<div class="${cssClass} resp-empty" title="Sem responsável"></div>`;
    const me = (window.TaskData && window.TaskData.me) || null;
    const low = n.toLowerCase();
    if (me && (low === "você" || low === "voce" || (me.name && low === me.name.toLowerCase()))) {
      return avatarHTML(me, cssClass);
    }
    const person = (people || []).find((p) => p.name.toLowerCase() === low);
    if (person) return avatarHTML(person, cssClass);
    return `<div class="${cssClass} resp-external" title="Responsável externo (sem acesso à tarefa)">${esc(initialsOf(n))}</div>`;
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

  /**
   * Protege um botão de ação contra cliques repetidos enquanto a operação corre.
   * Desabilita o botão, exibe um rótulo de carregamento (ex.: "Abrindo…") e o
   * reabilita ao concluir OU em caso de erro. Cliques durante a execução são
   * ignorados (evita envios/criações duplicadas). É por elemento/instância — não
   * usa estado global, então não interfere na UI de outros usuários/abas.
   * @param {HTMLElement} el  botão clicado
   * @param {Function} fn     função que dispara a ação (idealmente retorna Promise)
   * @param {string} [busyLabel] rótulo temporário enquanto carrega
   */
  function busyGuard(el, fn, busyLabel) {
    if (!el) return Promise.resolve(fn && fn());
    if (el.dataset.busy === "1") return Promise.resolve();      // já em andamento → ignora
    el.dataset.busy = "1";
    const html = el.innerHTML, wasDisabled = el.disabled;
    el.disabled = true; el.setAttribute("aria-busy", "true");
    if (busyLabel != null) el.textContent = busyLabel;
    const done = () => { delete el.dataset.busy; el.disabled = wasDisabled; el.removeAttribute("aria-busy"); el.innerHTML = html; };
    let r;
    try { r = fn(); } catch (e) { done(); throw e; }
    return Promise.resolve(r).then((v) => { done(); return v; }, (e) => { done(); throw e; });
  }

  window.UI = { esc, stripHtml, sanitizeHtml, priorityBadge, statusBadge, duePill, checklistMini, commentMini, recurMini, remindMini, labelChips, projectName, liveSection, sectionTitle, initialsOf, avatarHTML, taskPeople, peopleDatalistHTML, respAvatarHTML, ASSISTANT_AVATARS, assistantAvatarUrl, assistantName, busyGuard };
})();
