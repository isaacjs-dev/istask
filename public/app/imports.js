/* Assistente de Importação (Configurações). window.Imports.sectionHTML('task'|'note').
   Fluxo em 4 etapas SEM modal: 1 Prompt → 2 JSON → 3 Revisar → 4 Confirmar → resultados.
   A IA roda fora do app: o usuário copia o prompt, gera o JSON numa ferramenta externa e
   cola/seleciona o arquivo aqui. Estado em window.state.imports[kind] (sobrevive a re-render). */
(function () {
  const U = window.UI;
  const TD = window.TaskData;
  const Api = TD.Api;
  const icon = window.icon;
  const STATUS = TD.STATUS;
  const PRIORITY = TD.PRIORITY;
  const SECTIONS = TD.SECTIONS;

  const FLOW = ["prompt", "json", "review", "confirm"];
  const STEP_LABEL = { prompt: "Prompt", json: "Importar JSON", review: "Revisar", confirm: "Confirmar" };

  function st(kind) {
    const s = window.state;
    s.imports = s.imports || {};
    if (!s.imports[kind]) s.imports[kind] = { step: "prompt", raw: "", items: [], error: "", results: null, resultItems: [], importing: false };
    return s.imports[kind];
  }
  function render() { window.App.render(); }
  function setStep(kind, step) { st(kind).step = step; render(); }

  // ---------- listas/pessoas ----------
  function projects() { return window.state.projects || []; }
  function labels() { return window.state.labels || []; }
  function notebooks() { return window.state.notebooks || []; }
  function workspaces() { return window.state.workspaces || []; }
  function wsName(id) { const w = workspaces().find((x) => String(x.id) === String(id)); return w ? w.name : ""; }
  function defaultWorkspaceId() {
    if (window.state.activeWorkspaceId) return window.state.activeWorkspaceId;
    const own = workspaces().find((w) => w.isOwner);
    return own ? own.id : (workspaces()[0] || {}).id;
  }
  function findProject(s) { const k = String(s || "").trim().toLowerCase(); return projects().find((p) => p.slug.toLowerCase() === k || (p.name || "").toLowerCase() === k); }
  function findLabel(s) { const k = String(s || "").trim().toLowerCase(); return labels().find((l) => (l.name || "").toLowerCase() === k); }
  function findNotebook(s) { const k = String(s || "").trim().toLowerCase(); return notebooks().find((n) => String(n.id) === k || (n.name || "").toLowerCase() === k); }
  function allPeople() {
    const out = []; const seen = new Set();
    const add = (name, avatarUrl) => { const n = (name || "").trim(); if (!n) return; const k = n.toLowerCase(); if (seen.has(k)) return; seen.add(k); out.push({ name: n, initials: U.initialsOf(n), avatarUrl: avatarUrl || null }); };
    workspaces().forEach((w) => { add(w.ownerName, w.ownerAvatarUrl); (w.members || []).forEach((m) => add(m.name, m.avatarUrl)); });
    projects().forEach((p) => { add(p.ownerName, p.ownerAvatarUrl); (p.members || []).forEach((m) => add(m.name, m.avatarUrl)); });
    if (TD.me) add(TD.me.name, TD.me.avatarUrl);
    return out;
  }
  const noteColors = () => (window.Notes && window.Notes.PALETTE) || ["yellow", "pink", "mint", "blue", "lilac", "peach"];

  // ---------- prompt ----------
  function buildPrompt(kind) {
    if (kind === "note") return buildNotePrompt();
    return buildTaskPrompt();
  }
  function buildTaskPrompt() {
    const projLines = projects().map((p) => `- ${p.slug} — ${p.name}${p.workspaceId ? ` (Área: ${wsName(p.workspaceId)})` : ""}`).join("\n") || "- (nenhum)";
    const secLines = SECTIONS.map((s) => `${s.id} (${s.title})`).join(", ");
    const stLines = Object.keys(STATUS).map((k) => `${k} (${STATUS[k].label})`).join(", ");
    const prLines = Object.keys(PRIORITY).map((k) => `${k} (${PRIORITY[k].label})`).join(", ");
    const lblLines = labels().map((l) => l.name).join(", ") || "(nenhuma)";
    const ppl = allPeople().map((p) => p.name).join(", ") || "(nenhuma)";
    return `Você é um assistente que extrai TAREFAS da transcrição de um ou mais áudios e gera um JSON para importar no sistema "TaskAI Manager".

REGRAS
- Leia a transcrição e identifique TODAS as tarefas mencionadas; separe cada uma como um item.
- Para cada tarefa, extraia quando houver: título, descrição/observações, responsável, prazo (data), projeto/lista, seção, status, prioridade, etiquetas e subtarefas.
- NÃO invente nada que não esteja na transcrição. Se um campo não for citado, omita-o.
- Use SOMENTE os valores válidos abaixo para "project" (use o slug), "section", "status" e "priority". Para "responsible", prefira um dos nomes listados; se for outra pessoa citada, use o nome dela.
- Datas no formato YYYY-MM-DD.
- Responda EXCLUSIVAMENTE com um JSON válido no formato indicado — sem texto antes/depois, sem comentários, sem markdown.

VALORES VÁLIDOS
Projetos (slug — nome):
${projLines}
Seções: ${secLines}
Status: ${stLines}
Prioridades: ${prLines}
Recorrência: none, daily, weekly, monthly
Etiquetas: ${lblLines}
Pessoas (responsável): ${ppl}

FORMATO DO JSON
{
  "tasks": [
    {
      "title": "string (obrigatório)",
      "description": "string (opcional)",
      "project": "slug-do-projeto (opcional)",
      "section": "id-da-seção (opcional)",
      "status": "pendente (opcional, padrão: pendente)",
      "priority": "media (opcional, padrão: media)",
      "due": "YYYY-MM-DD (opcional)",
      "startDate": "YYYY-MM-DD (opcional)",
      "responsible": "Nome (opcional)",
      "recurrence": "none (opcional)",
      "estimatedMinutes": 30,
      "labels": ["Etiqueta"],
      "checklist": [ { "text": "Subtarefa", "done": false } ]
    }
  ]
}

TRANSCRIÇÃO:
<<cole aqui a transcrição dos áudios>>`;
  }
  function buildNotePrompt() {
    const nbLines = notebooks().map((n) => `- ${n.name}${n.workspaceId ? ` (Área: ${wsName(n.workspaceId)})` : ""}`).join("\n") || "- (nenhum)";
    const lblLines = labels().map((l) => l.name).join(", ") || "(nenhuma)";
    const colors = noteColors().join(", ");
    return `Você é um assistente que extrai NOTAS da transcrição de um ou mais áudios e gera um JSON para importar no sistema "TaskAI Manager".

REGRAS
- Leia a transcrição e identifique TODAS as notas/anotações mencionadas; separe cada uma como um item.
- Para cada nota, extraia quando houver: título, conteúdo (body), caderno, etiquetas, cor e, se for uma lista de itens, "type": "checklist" com "items".
- NÃO invente nada que não esteja na transcrição. Se um campo não for citado, omita-o.
- Use SOMENTE os valores válidos abaixo para "notebook" (nome), "labels" e "color".
- Responda EXCLUSIVAMENTE com um JSON válido no formato indicado — sem texto antes/depois, sem comentários, sem markdown.

VALORES VÁLIDOS
Cadernos:
${nbLines}
Etiquetas: ${lblLines}
Cores: ${colors}
Tipos: text, checklist

FORMATO DO JSON
{
  "notes": [
    {
      "title": "string (obrigatório)",
      "body": "string (opcional; para type=text)",
      "type": "text|checklist (opcional, padrão: text)",
      "notebook": "nome-do-caderno (opcional)",
      "labels": ["Etiqueta"],
      "color": "yellow (opcional)",
      "items": [ { "text": "Item", "done": false } ]
    }
  ]
}

TRANSCRIÇÃO:
<<cole aqui a transcrição dos áudios>>`;
  }

  // ---------- parse + normalização ----------
  function normDate(v) {
    if (v == null || v === "") return "";
    const s = String(v).trim();
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) { const d = new Date(s); if (!isNaN(d.getTime())) return `${m[1]}-${m[2]}-${m[3]}`; }
    const d2 = new Date(s);
    if (!isNaN(d2.getTime())) { return `${d2.getFullYear()}-${String(d2.getMonth() + 1).padStart(2, "0")}-${String(d2.getDate()).padStart(2, "0")}`; }
    return false; // inválida
  }
  function normChecklist(arr) {
    if (!Array.isArray(arr)) return [];
    return arr.map((s) => typeof s === "string" ? { text: s, done: false } : {
      text: String((s && (s.text || s.title)) || "").trim(), done: !!(s && s.done),
      assignee: (s && s.assignee) || "", priority: (s && s.priority) || "", due: (s && s.due) || "",
    }).filter((s) => s.text);
  }
  function normalizeTask(o) {
    o = o || {};
    const it = { sel: true, kind: "task" };
    it.title = String(o.title || o.titulo || o["título"] || "").trim();
    it.description = String(o.description || o.descricao || o["descrição"] || o.notes || o.observacoes || o["observações"] || "");
    // projeto
    const projRaw = String(o.project || o.projeto || o.list || o.lista || "").trim();
    const p = projRaw ? findProject(projRaw) : null;
    it.project = p ? p.slug : ""; it.projectBad = projRaw && !p ? projRaw : "";
    // seção
    const secRaw = String(o.section || o.secao || o["seção"] || "").trim().toLowerCase();
    if (!secRaw) { it.section = ""; it.sectionBad = ""; }
    else { const sec = SECTIONS.find((x) => x.id === secRaw || x.title.toLowerCase() === secRaw); it.section = sec ? sec.id : ""; it.sectionBad = sec ? "" : (o.section || o.secao); }
    // status / prioridade
    const stRaw = String(o.status || "").trim().toLowerCase();
    if (!stRaw) { it.status = "pendente"; it.statusBad = ""; } else { it.status = STATUS[stRaw] ? stRaw : ""; it.statusBad = STATUS[stRaw] ? "" : o.status; }
    const prRaw = String(o.priority || o.prioridade || "").trim().toLowerCase();
    if (!prRaw) { it.priority = "media"; it.priorityBad = ""; } else { it.priority = PRIORITY[prRaw] ? prRaw : ""; it.priorityBad = PRIORITY[prRaw] ? "" : (o.priority || o.prioridade); }
    // datas
    const due = normDate(o.due || o.prazo || o.dueDate || o.data || ""); it.due = due || ""; it.dueBad = due === false;
    const sd = normDate(o.startDate || o.inicio || o["início"] || ""); it.startDate = sd || ""; it.startBad = sd === false;
    it.responsible = String(o.responsible || o.responsavel || o["responsável"] || "").trim();
    const rec = String(o.recurrence || "").toLowerCase(); it.recurrence = ["none", "daily", "weekly", "monthly"].includes(rec) ? rec : "none";
    it.estimatedMinutes = (o.estimatedMinutes != null && !isNaN(+o.estimatedMinutes)) ? Math.max(0, parseInt(o.estimatedMinutes, 10)) : null;
    it.labels = (Array.isArray(o.labels) ? o.labels : (Array.isArray(o.tags) ? o.tags : [])).map((x) => String(x).trim()).filter(Boolean);
    it.checklist = normChecklist(o.checklist || o.subtasks || o.subtarefas);
    return it;
  }
  function normalizeNote(o) {
    o = o || {};
    const it = { sel: true, kind: "note" };
    it.title = String(o.title || o.titulo || o["título"] || "").trim();
    const type = String(o.type || o.tipo || "").toLowerCase();
    it.type = type === "checklist" ? "checklist" : "text";
    it.body = it.type === "checklist" ? "" : String(o.body || o.conteudo || o["conteúdo"] || o.text || "");
    const nbRaw = String(o.notebook || o.caderno || "").trim();
    const nb = nbRaw ? findNotebook(nbRaw) : null;
    it.notebook = nb ? String(nb.id) : ""; it.notebookBad = nbRaw && !nb ? nbRaw : "";
    const color = String(o.color || o.cor || "").trim().toLowerCase();
    it.color = noteColors().includes(color) ? color : "";
    it.labels = (Array.isArray(o.labels) ? o.labels : (Array.isArray(o.tags) ? o.tags : [])).map((x) => String(x).trim()).filter(Boolean);
    it.items = normChecklist(o.items || o.itens).map((s) => ({ text: s.text, done: s.done }));
    return it;
  }
  function parse(kind, raw) {
    let data;
    try { data = JSON.parse(raw); } catch (e) { return { ok: false, error: "JSON inválido: " + (e.message || "não foi possível interpretar o conteúdo.") }; }
    let arr = null;
    if (Array.isArray(data)) arr = data;
    else if (data && Array.isArray(data[kind === "task" ? "tasks" : "notes"])) arr = data[kind === "task" ? "tasks" : "notes"];
    else if (data && typeof data === "object") arr = [data];
    if (!arr || !arr.length) return { ok: false, error: `Nenhuma ${kind === "task" ? "tarefa" : "nota"} encontrada no JSON. Esperado { "${kind === "task" ? "tasks" : "notes"}": [ … ] }.` };
    const items = arr.map((o) => kind === "task" ? normalizeTask(o) : normalizeNote(o));
    return { ok: true, items };
  }

  // ---------- validação ----------
  function isDup(kind, it, idx) {
    const s = st(kind);
    const t = (it.title || "").trim().toLowerCase();
    if (!t) return false;
    const inBatch = s.items.some((o, i) => i !== idx && o.sel && (o.title || "").trim().toLowerCase() === t);
    const existing = kind === "task"
      ? (window.state.tasks || []).some((x) => (x.title || "").trim().toLowerCase() === t)
      : (window.state.notes || []).some((x) => (x.title || "").trim().toLowerCase() === t);
    return inBatch || existing;
  }
  function issues(kind, it, idx) {
    const out = [];
    if (!it.title) out.push({ level: "error", msg: "Título obrigatório" });
    if (kind === "task") {
      if (it.projectBad) out.push({ level: "error", kind: "project", msg: `Projeto “${it.projectBad}” não existe` });
      if (it.statusBad) out.push({ level: "error", msg: `Status “${it.statusBad}” inválido` });
      if (it.priorityBad) out.push({ level: "error", msg: `Prioridade “${it.priorityBad}” inválida` });
      if (it.sectionBad) out.push({ level: "error", msg: `Seção “${it.sectionBad}” inválida` });
      if (it.dueBad) out.push({ level: "error", msg: "Prazo com data inválida" });
      if (it.startBad) out.push({ level: "error", msg: "Data de início inválida" });
      if (it.responsible && !allPeople().some((p) => p.name.toLowerCase() === it.responsible.toLowerCase())) out.push({ level: "warn", msg: `Responsável externo: ${it.responsible}` });
    } else {
      if (it.notebookBad) out.push({ level: "error", kind: "notebook", msg: `Caderno “${it.notebookBad}” não existe` });
    }
    const newLabels = (it.labels || []).filter((n) => !findLabel(n));
    if (newLabels.length) out.push({ level: "warn", kind: "labels", msg: `Etiqueta(s) nova(s): ${newLabels.join(", ")} (serão criadas)` });
    if (isDup(kind, it, idx)) out.push({ level: "warn", msg: "Possível duplicata (título já existe)" });
    return out;
  }
  function itemValid(kind, it, idx) { return !issues(kind, it, idx).some((x) => x.level === "error"); }

  function summary(kind) {
    const s = st(kind);
    const total = s.items.length;
    const selected = s.items.filter((it) => it.sel);
    const valid = selected.filter((it, i) => itemValid(kind, s.items.indexOf(it)) || itemValid(kind, it, s.items.indexOf(it)));
    const sel = s.items.filter((it, i) => it.sel);
    const selValid = s.items.filter((it, i) => it.sel && itemValid(kind, it, i)).length;
    const selInvalid = sel.length - selValid;
    const ignored = total - sel.length;
    const withIssues = s.items.filter((it, i) => issues(kind, it, i).length).length;
    // destinos
    const projSet = {}; const respSet = {}; const nbSet = {};
    s.items.forEach((it, i) => {
      if (!it.sel) return;
      if (kind === "task") {
        const pn = it.project ? (findProject(it.project) || {}).name || it.project : "Projeto padrão";
        projSet[pn] = (projSet[pn] || 0) + 1;
        if (it.responsible) respSet[it.responsible] = (respSet[it.responsible] || 0) + 1;
      } else {
        const nb = it.notebook ? (notebooks().find((n) => String(n.id) === String(it.notebook)) || {}).name || it.notebook : "Caderno padrão";
        nbSet[nb] = (nbSet[nb] || 0) + 1;
      }
    });
    return { total, sel: sel.length, selValid, selInvalid, ignored, withIssues, projSet, respSet, nbSet };
  }

  // ========== RENDER ==========
  function sectionHTML(kind) {
    const s = st(kind);
    const head = `
      <div class="set-block-label">${icon("Inbox", 17, 'style="vertical-align:-3px;margin-right:6px"')}Importação de ${kind === "task" ? "Tarefas" : "Notas"}</div>
      <p class="set-hint">Transforme a transcrição de áudios em ${kind === "task" ? "tarefas" : "notas"} estruturadas: copie o prompt, gere o JSON numa IA externa, importe, revise e confirme.</p>
      ${stepperHTML(kind, s.step)}`;
    let body = "";
    if (s.step === "prompt") body = promptStepHTML(kind);
    else if (s.step === "json") body = jsonStepHTML(kind);
    else if (s.step === "review") body = reviewStepHTML(kind);
    else if (s.step === "confirm") body = confirmStepHTML(kind);
    else if (s.step === "done") body = doneStepHTML(kind);
    return `<div class="imp" data-imp-kind="${kind}">${head}${body}</div>`;
  }
  function stepperHTML(kind, step) {
    const cur = step === "done" ? 4 : FLOW.indexOf(step);
    return `<ol class="imp-steps">${FLOW.map((s, i) => `
      <li class="imp-step${i === cur ? " on" : ""}${i < cur || step === "done" ? " done" : ""}">
        <span class="imp-step-n">${i < cur || step === "done" ? icon("Check", 13) : (i + 1)}</span>
        <span class="imp-step-l">${STEP_LABEL[s]}</span>
      </li>`).join("")}</ol>`;
  }
  function promptStepHTML(kind) {
    const prompt = buildPrompt(kind);
    return `
      <div class="imp-panel">
        <p class="imp-lead">1. Copie o prompt abaixo e use-o em uma ferramenta de IA junto com a transcrição dos áudios. Ele já inclui as opções existentes no sistema.</p>
        <textarea class="imp-prompt" readonly rows="12">${U.esc(prompt)}</textarea>
        <div class="imp-actions">
          <button class="set-reset" data-imp="copy" data-kind="${kind}">${icon("Check", 15)} Copiar prompt</button>
          <span class="imp-spacer"></span>
          <button class="note-btn-save" data-imp="goto" data-kind="${kind}" data-step="json">Próximo: Importar JSON ${icon("ChevRight", 15)}</button>
        </div>
      </div>`;
  }
  function jsonStepHTML(kind) {
    const s = st(kind);
    return `
      <div class="imp-panel">
        <p class="imp-lead">2. Cole o JSON gerado pela IA ou selecione um arquivo <code>.json</code>.</p>
        <textarea class="imp-json" data-imp-raw data-kind="${kind}" rows="10" placeholder='{ "${kind === "task" ? "tasks" : "notes"}": [ … ] }'>${U.esc(s.raw || "")}</textarea>
        <div class="imp-file"><label class="set-reset imp-filelbl">${icon("Inbox", 15)} Selecionar arquivo .json<input type="file" accept=".json,application/json" data-imp="file" data-kind="${kind}" hidden></label></div>
        ${s.error ? `<div class="imp-error">${icon("Alert", 15)} ${U.esc(s.error)}</div>` : ""}
        <div class="imp-actions">
          <button class="set-reset" data-imp="goto" data-kind="${kind}" data-step="prompt">${icon("ChevLeft", 15)} Voltar</button>
          <span class="imp-spacer"></span>
          <button class="note-btn-save" data-imp="validate" data-kind="${kind}">Validar e revisar ${icon("ChevRight", 15)}</button>
        </div>
      </div>`;
  }
  function chipLabelsHTML(kind, i, names) {
    return (names || []).map((n) => {
      const isNew = !findLabel(n);
      return `<span class="imp-lbl${isNew ? " new" : ""}">${icon("Tag", 11)} ${U.esc(n)}<button class="imp-lbl-x" data-imp="del-label" data-kind="${kind}" data-i="${i}" data-label="${U.esc(n)}" title="Remover">${icon("X", 11)}</button></span>`;
    }).join("");
  }
  function selectHTML(cls, value, opts, dataAttrs) {
    return `<div class="select-wrap"><select class="${cls}" ${dataAttrs}>${opts.map(([v, l]) => `<option value="${U.esc(v)}"${String(v) === String(value) ? " selected" : ""}>${U.esc(l)}</option>`).join("")}</select><span class="chev">${icon("ChevDown", 15)}</span></div>`;
  }
  function taskCardHTML(it, i) {
    const iss = issues("task", it, i);
    const valid = !iss.some((x) => x.level === "error");
    const df = (f) => `data-imp-field data-kind="task" data-i="${i}" data-field="${f}"`;
    const projOpts = [["", "— Projeto padrão —"]].concat(projects().map((p) => [p.slug, p.name]));
    const secOpts = [["", "— Sem seção —"]].concat(SECTIONS.map((s) => [s.id, s.title]));
    const stOpts = Object.keys(STATUS).map((k) => [k, STATUS[k].label]);
    const prOpts = Object.keys(PRIORITY).map((k) => [k, PRIORITY[k].label]);
    return `
      <div class="imp-card${it.sel ? "" : " off"}${valid ? "" : " invalid"}">
        <div class="imp-card-head">
          <label class="imp-check"><input type="checkbox" data-imp="toggle" data-kind="task" data-i="${i}"${it.sel ? " checked" : ""}> </label>
          <input class="imp-title" ${df("title")} value="${U.esc(it.title)}" placeholder="Título da tarefa">
          <button class="imp-del" data-imp="del-item" data-kind="task" data-i="${i}" title="Excluir da importação">${icon("Trash", 15)}</button>
        </div>
        ${iss.length ? `<div class="imp-issues">${iss.map((x) => `<span class="imp-issue ${x.level}">${icon(x.level === "error" ? "Alert" : "Alert", 12)} ${U.esc(x.msg)}${x.kind === "project" && it.projectBad ? ` <button class="imp-mk" data-imp="create-project" data-kind="task" data-i="${i}">Criar projeto</button>` : ""}</span>`).join("")}</div>` : ""}
        <div class="imp-grid">
          <label class="imp-f"><span>Projeto</span>${selectHTML("imp-sel", it.project, projOpts, df("project"))}</label>
          <label class="imp-f"><span>Seção</span>${selectHTML("imp-sel", it.section, secOpts, df("section"))}</label>
          <label class="imp-f"><span>Status</span>${selectHTML("imp-sel", it.status, stOpts, df("status"))}</label>
          <label class="imp-f"><span>Prioridade</span>${selectHTML("imp-sel", it.priority, prOpts, df("priority"))}</label>
          <label class="imp-f"><span>Prazo</span><input type="date" class="imp-in" ${df("due")} value="${U.esc(it.due)}"></label>
          <label class="imp-f"><span>Responsável</span><input class="imp-in" list="imp-people" ${df("responsible")} value="${U.esc(it.responsible)}" placeholder="Nome"></label>
        </div>
        <label class="imp-f imp-f-full"><span>Descrição / observações</span><textarea class="imp-in" rows="2" ${df("description")} placeholder="—">${U.esc(it.description)}</textarea></label>
        <div class="imp-f imp-f-full"><span>Etiquetas</span><div class="imp-lbls">${chipLabelsHTML("task", i, it.labels)}<input class="imp-lbl-add" list="imp-labels" data-imp-addlabel data-kind="task" data-i="${i}" placeholder="+ etiqueta"></div></div>
        ${it.checklist && it.checklist.length ? `<div class="imp-f imp-f-full"><span>Subtarefas (${it.checklist.length})</span><ul class="imp-subs">${it.checklist.map((s, j) => `<li><input type="checkbox" data-imp="sub-done" data-kind="task" data-i="${i}" data-j="${j}"${s.done ? " checked" : ""}><input class="imp-in" data-imp-sub data-kind="task" data-i="${i}" data-j="${j}" value="${U.esc(s.text)}"><button class="imp-lbl-x" data-imp="del-sub" data-kind="task" data-i="${i}" data-j="${j}">${icon("X", 12)}</button></li>`).join("")}</ul></div>` : ""}
      </div>`;
  }
  function noteCardHTML(it, i) {
    const iss = issues("note", it, i);
    const valid = !iss.some((x) => x.level === "error");
    const df = (f) => `data-imp-field data-kind="note" data-i="${i}" data-field="${f}"`;
    const nbOpts = [["", "— Caderno padrão —"]].concat(notebooks().map((n) => [String(n.id), n.name]));
    const colorOpts = [["", "— Cor padrão —"]].concat(noteColors().map((c) => [c, c]));
    const typeOpts = [["text", "Texto"], ["checklist", "Lista"]];
    return `
      <div class="imp-card${it.sel ? "" : " off"}${valid ? "" : " invalid"}">
        <div class="imp-card-head">
          <label class="imp-check"><input type="checkbox" data-imp="toggle" data-kind="note" data-i="${i}"${it.sel ? " checked" : ""}> </label>
          <input class="imp-title" ${df("title")} value="${U.esc(it.title)}" placeholder="Título da nota">
          <button class="imp-del" data-imp="del-item" data-kind="note" data-i="${i}" title="Excluir da importação">${icon("Trash", 15)}</button>
        </div>
        ${iss.length ? `<div class="imp-issues">${iss.map((x) => `<span class="imp-issue ${x.level}">${icon("Alert", 12)} ${U.esc(x.msg)}${x.kind === "notebook" && it.notebookBad ? ` <button class="imp-mk" data-imp="create-notebook" data-kind="note" data-i="${i}">Criar caderno</button>` : ""}</span>`).join("")}</div>` : ""}
        <div class="imp-grid">
          <label class="imp-f"><span>Caderno</span>${selectHTML("imp-sel", it.notebook, nbOpts, df("notebook"))}</label>
          <label class="imp-f"><span>Tipo</span>${selectHTML("imp-sel", it.type, typeOpts, df("type"))}</label>
          <label class="imp-f"><span>Cor</span>${selectHTML("imp-sel", it.color, colorOpts, df("color"))}</label>
        </div>
        ${it.type === "checklist"
        ? `<div class="imp-f imp-f-full"><span>Itens (${(it.items || []).length})</span><ul class="imp-subs">${(it.items || []).map((s, j) => `<li><input type="checkbox" data-imp="sub-done" data-kind="note" data-i="${i}" data-j="${j}"${s.done ? " checked" : ""}><input class="imp-in" data-imp-sub data-kind="note" data-i="${i}" data-j="${j}" value="${U.esc(s.text)}"><button class="imp-lbl-x" data-imp="del-sub" data-kind="note" data-i="${i}" data-j="${j}">${icon("X", 12)}</button></li>`).join("")}</ul></div>`
        : `<label class="imp-f imp-f-full"><span>Conteúdo</span><textarea class="imp-in" rows="3" ${df("body")} placeholder="—">${U.esc(it.body)}</textarea></label>`}
        <div class="imp-f imp-f-full"><span>Etiquetas</span><div class="imp-lbls">${chipLabelsHTML("note", i, it.labels)}<input class="imp-lbl-add" list="imp-labels" data-imp-addlabel data-kind="note" data-i="${i}" placeholder="+ etiqueta"></div></div>
      </div>`;
  }
  function reviewStepHTML(kind) {
    const s = st(kind);
    const sm = summary(kind);
    const cards = s.items.map((it, i) => kind === "task" ? taskCardHTML(it, i) : noteCardHTML(it, i)).join("");
    const peopleDl = `<datalist id="imp-people">${allPeople().map((p) => `<option value="${U.esc(p.name)}"></option>`).join("")}</datalist>`;
    const labelDl = `<datalist id="imp-labels">${labels().map((l) => `<option value="${U.esc(l.name)}"></option>`).join("")}</datalist>`;
    return `
      <div class="imp-panel">
        <div class="imp-reviewbar">
          <span>${sm.total} ${kind === "task" ? "tarefa(s)" : "nota(s)"} · ${sm.sel} selecionada(s)${sm.selInvalid ? ` · <strong class="imp-warn">${sm.selInvalid} com erro</strong>` : ""}</span>
          <span class="imp-spacer"></span>
          <button class="imp-link" data-imp="sel-all" data-kind="${kind}">Selecionar todas</button>
          <button class="imp-link" data-imp="sel-none" data-kind="${kind}">Limpar</button>
        </div>
        <div class="imp-cards">${cards || `<p class="imp-lead">Nenhum item.</p>`}</div>
        ${peopleDl}${labelDl}
        <div class="imp-actions">
          <button class="set-reset" data-imp="goto" data-kind="${kind}" data-step="json">${icon("ChevLeft", 15)} Voltar</button>
          <span class="imp-spacer"></span>
          <button class="note-btn-save" data-imp="goto" data-kind="${kind}" data-step="confirm"${sm.sel ? "" : " disabled"}>Próximo: Confirmar ${icon("ChevRight", 15)}</button>
        </div>
      </div>`;
  }
  function confirmStepHTML(kind) {
    const sm = summary(kind);
    const dest = kind === "task"
      ? `<div class="imp-sum-row"><b>Projetos de destino:</b> ${Object.keys(sm.projSet).map((k) => `${U.esc(k)} (${sm.projSet[k]})`).join(", ") || "—"}</div>
         <div class="imp-sum-row"><b>Responsáveis:</b> ${Object.keys(sm.respSet).map((k) => `${U.esc(k)} (${sm.respSet[k]})`).join(", ") || "—"}</div>`
      : `<div class="imp-sum-row"><b>Cadernos de destino:</b> ${Object.keys(sm.nbSet).map((k) => `${U.esc(k)} (${sm.nbSet[k]})`).join(", ") || "—"}</div>`;
    const blocked = sm.selInvalid > 0 || sm.selValid === 0;
    const s = st(kind);
    return `
      <div class="imp-panel">
        <p class="imp-lead">4. Revise o resumo e confirme a importação.</p>
        <div class="imp-summary">
          <div class="imp-sum-grid">
            <div class="imp-stat"><b>${sm.total}</b><span>identificadas</span></div>
            <div class="imp-stat ok"><b>${sm.selValid}</b><span>selecionadas válidas</span></div>
            <div class="imp-stat"><b>${sm.ignored}</b><span>ignoradas</span></div>
            <div class="imp-stat${sm.selInvalid ? " bad" : ""}"><b>${sm.selInvalid}</b><span>selecionadas com erro</span></div>
            <div class="imp-stat${sm.withIssues ? " warn" : ""}"><b>${sm.withIssues}</b><span>com inconsistência</span></div>
          </div>
          ${dest}
          ${sm.selInvalid ? `<div class="imp-error">${icon("Alert", 15)} Há tarefas selecionadas com erro. Volte e corrija ou desmarque-as para concluir.</div>` : ""}
        </div>
        <div class="imp-actions">
          <button class="set-reset" data-imp="goto" data-kind="${kind}" data-step="review">${icon("ChevLeft", 15)} Voltar</button>
          <span class="imp-spacer"></span>
          <button class="note-btn-save" data-imp="import" data-kind="${kind}"${blocked || s.importing ? " disabled" : ""}>${s.importing ? "Importando…" : `Importar ${kind === "task" ? "tarefas" : "notas"} selecionadas`}</button>
        </div>
      </div>`;
  }
  function doneStepHTML(kind) {
    const s = st(kind);
    const results = s.results || [];
    const okN = results.filter((r) => r.ok).length;
    const errN = results.length - okN;
    const rows = results.map((r, i) => {
      const it = s.resultItems[i] || {};
      return `<li class="imp-res ${r.ok ? "ok" : "err"}">${icon(r.ok ? "Check" : "Alert", 14)} ${U.esc(it.title || ("Item " + (i + 1)))}${r.ok ? `<button class="imp-link" data-imp="open" data-kind="${kind}" data-id="${r.id}">abrir</button>` : ` — ${U.esc(r.error || "erro")}`}</li>`;
    }).join("");
    return `
      <div class="imp-panel">
        <div class="imp-done-head">${icon("Check", 22)} <b>${okN} ${kind === "task" ? "tarefa(s)" : "nota(s)"} importada(s)</b>${errN ? ` · ${errN} com erro` : ""}</div>
        <ul class="imp-reslist">${rows}</ul>
        <div class="imp-actions">
          <button class="note-btn-save" data-imp="open-page" data-kind="${kind}">Ver ${kind === "task" ? "tarefas" : "notas"}</button>
          <span class="imp-spacer"></span>
          <button class="set-reset" data-imp="reset" data-kind="${kind}">Importar mais</button>
        </div>
      </div>`;
  }

  // ========== EVENTOS ==========
  function copyText(text) {
    const done = () => window.App.toast("Prompt copiado");
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
    else fallbackCopy(text, done);
  }
  function fallbackCopy(text, done) {
    const ta = document.createElement("textarea"); ta.value = text; ta.style.position = "fixed"; ta.style.opacity = "0";
    document.body.appendChild(ta); ta.select();
    try { document.execCommand("copy"); done(); } catch (_) { window.App.toast("Não foi possível copiar."); }
    ta.remove();
  }

  document.addEventListener("click", (e) => {
    const el = e.target.closest("[data-imp]");
    if (!el) return;
    const act = el.dataset.imp; const kind = el.dataset.kind; const s = st(kind);
    if (act === "copy") { copyText(buildPrompt(kind)); }
    else if (act === "goto") { setStep(kind, el.dataset.step); }
    else if (act === "validate") {
      const res = parse(kind, s.raw || "");
      if (!res.ok) { s.error = res.error; render(); return; }
      s.error = ""; s.items = res.items; s.step = "review"; render();
    }
    else if (act === "sel-all") { s.items.forEach((it) => it.sel = true); render(); }
    else if (act === "sel-none") { s.items.forEach((it) => it.sel = false); render(); }
    else if (act === "del-item") { s.items.splice(+el.dataset.i, 1); render(); }
    else if (act === "del-label") { const it = s.items[+el.dataset.i]; it.labels = it.labels.filter((n) => n.toLowerCase() !== el.dataset.label.toLowerCase()); render(); }
    else if (act === "del-sub") { const it = s.items[+el.dataset.i]; (it.checklist || it.items).splice(+el.dataset.j, 1); render(); }
    else if (act === "create-project") { createProjectFor(kind, +el.dataset.i); }
    else if (act === "create-notebook") { createNotebookFor(kind, +el.dataset.i); }
    else if (act === "import") { doImport(kind); }
    else if (act === "reset") { window.state.imports[kind] = { step: "prompt", raw: "", items: [], error: "", results: null, resultItems: [], importing: false }; render(); }
    else if (act === "open") { openResult(kind, el.dataset.id); }
    else if (act === "open-page") { window.state.page = kind === "task" ? "tarefas" : "notas"; render(); }
  });

  document.addEventListener("change", (e) => {
    const t = e.target;
    if (t.dataset && t.dataset.imp === "file") { readFile(t.dataset.kind, t.files && t.files[0]); return; }
    if (t.dataset && t.dataset.imp === "toggle") { const s = st(t.dataset.kind); s.items[+t.dataset.i].sel = t.checked; render(); return; }
    if (t.dataset && t.dataset.imp === "sub-done") { const s = st(t.dataset.kind); const it = s.items[+t.dataset.i]; (it.checklist || it.items)[+t.dataset.j].done = t.checked; return; }
    if (t.dataset && t.dataset.field !== undefined && t.hasAttribute("data-imp-field")) { setField(t, true); return; }
  });
  document.addEventListener("input", (e) => {
    const t = e.target;
    if (t.dataset && t.hasAttribute && t.hasAttribute("data-imp-raw")) { st(t.dataset.kind).raw = t.value; return; }
    if (t.dataset && t.hasAttribute && t.hasAttribute("data-imp-field")) { setField(t, false); return; }
    if (t.dataset && t.hasAttribute && t.hasAttribute("data-imp-sub")) { const s = st(t.dataset.kind); const it = s.items[+t.dataset.i]; (it.checklist || it.items)[+t.dataset.j].text = t.value; return; }
  });
  document.addEventListener("keydown", (e) => {
    const t = e.target;
    if (e.key === "Enter" && t.hasAttribute && t.hasAttribute("data-imp-addlabel")) {
      e.preventDefault();
      const name = (t.value || "").trim(); if (!name) return;
      const s = st(t.dataset.kind); const it = s.items[+t.dataset.i];
      if (!it.labels.some((n) => n.toLowerCase() === name.toLowerCase())) it.labels.push(name);
      t.value = ""; render();
    }
  });

  function setField(t, rerender) {
    const s = st(t.dataset.kind); const it = s.items[+t.dataset.i]; const f = t.dataset.field;
    if (f === "due" || f === "startDate") { it[f] = t.value; it[f === "due" ? "dueBad" : "startBad"] = false; }
    else if (f === "project") { it.project = t.value; it.projectBad = ""; }
    else if (f === "section") { it.section = t.value; it.sectionBad = ""; }
    else if (f === "status") { it.status = t.value; it.statusBad = ""; }
    else if (f === "priority") { it.priority = t.value; it.priorityBad = ""; }
    else if (f === "notebook") { it.notebook = t.value; it.notebookBad = ""; }
    else if (f === "type") { it.type = t.value; }
    else it[f] = t.value;
    if (rerender) render();
  }

  function readFile(kind, file) {
    if (!file) return;
    const r = new FileReader();
    r.onload = () => { st(kind).raw = String(r.result || ""); st(kind).error = ""; render(); };
    r.onerror = () => window.App.toast("Não foi possível ler o arquivo.");
    r.readAsText(file);
  }

  function createProjectFor(kind, i) {
    const it = st(kind).items[i]; const name = it.projectBad; if (!name) return;
    Api.createProject(name, defaultWorkspaceId()).then((res) => {
      if (res.projects) window.state.projects = res.projects;
      const slug = res.project ? res.project.slug : (findProject(name) || {}).slug;
      if (slug) { it.project = slug; it.projectBad = ""; }
      window.App.toast("Projeto criado"); render();
    }).catch((err) => {
      if (err && err.status === 422) { const p = findProject(name); if (p) { it.project = p.slug; it.projectBad = ""; render(); return; } }
      window.App.toast("Não foi possível criar o projeto.");
    });
  }
  function createNotebookFor(kind, i) {
    const it = st(kind).items[i]; const name = it.notebookBad; if (!name) return;
    Api.createNotebook(name, defaultWorkspaceId()).then((res) => {
      if (res.notebooks) window.state.notebooks = res.notebooks;
      const nb = res.notebook ? res.notebook : findNotebook(name);
      if (nb) { it.notebook = String(nb.id); it.notebookBad = ""; }
      window.App.toast("Caderno criado"); render();
    }).catch(() => window.App.toast("Não foi possível criar o caderno."));
  }

  function taskPayload(it) {
    const labelIds = it.labels.map((n) => { const l = findLabel(n); return l ? +l.id : null; }).filter(Boolean);
    return {
      title: it.title, description: it.description || "",
      status: it.status || "pendente", priority: it.priority || "media",
      project: it.project || null, section: it.section || null,
      due: it.due || null, startDate: it.startDate || null,
      responsible: it.responsible || null, recurrence: it.recurrence || "none",
      estimatedMinutes: it.estimatedMinutes != null ? it.estimatedMinutes : null,
      labelIds,
      checklist: (it.checklist || []).map((s) => ({ text: s.text, done: !!s.done, assignee: s.assignee || null, priority: s.priority || null, due: s.due || null })),
    };
  }
  function notePayload(it) {
    const labelIds = it.labels.map((n) => { const l = findLabel(n); return l ? +l.id : null; }).filter(Boolean);
    return {
      title: it.title, body: it.type === "checklist" ? "" : (it.body || ""),
      type: it.type || "text", notebook_id: it.notebook ? +it.notebook : null,
      color: it.color || null, labelIds,
      items: it.type === "checklist" ? (it.items || []).map((s) => ({ text: s.text, done: !!s.done })) : [],
    };
  }

  async function doImport(kind) {
    const s = st(kind);
    const sel = s.items.filter((it, i) => it.sel && itemValid(kind, it, i));
    if (!sel.length) return;
    s.importing = true; render();
    try {
      // cria etiquetas novas (são do próprio usuário; sempre permitido)
      const newNames = [];
      sel.forEach((it) => (it.labels || []).forEach((n) => { if (!findLabel(n) && !newNames.some((x) => x.toLowerCase() === n.toLowerCase())) newNames.push(n); }));
      for (const name of newNames) {
        try { const r = await Api.createLabel(name); if (r.labels) window.state.labels = r.labels; }
        catch (e) { /* já existe → ignora */ }
      }
      const payload = sel.map((it) => kind === "task" ? taskPayload(it) : notePayload(it));
      const res = kind === "task" ? await Api.importTasks(payload) : await Api.importNotes(payload);
      if (kind === "task" && res.tasks) window.state.tasks = res.tasks;
      if (kind === "note" && res.notes) window.state.notes = res.notes;
      s.results = res.results || []; s.resultItems = sel; s.step = "done";
    } catch (e) {
      window.App.toast("Falha na importação.");
    }
    s.importing = false; render();
  }

  function openResult(kind, id) {
    if (kind === "task") {
      window.state.page = "tarefas"; render();
      if (window.QuickEdit && window.QuickEdit.open) window.QuickEdit.open(id);
    } else {
      window.state.page = "notas"; render();
    }
  }

  window.Imports = { sectionHTML };
})();
