/* Página "Atividades" — registro/timeline das ações de tarefas (TaskHistory).
   Separado por dia, com janela Dia/Semana/Mês e visão de time (atividades de
   todos os membros de uma área compartilhada, filtráveis por membro).
   window.Render.activitiesPageHTML() + delegação [data-ac-act]. */
(function () {
  const U = window.UI;
  const icon = window.icon;
  const Api = window.TaskData.Api;

  let cache = null;       // últimas atividades carregadas
  let loading = false;
  let scope = "me";       // "me" ou id da área (time)
  let memberFilter = null; // Set de byId selecionados (null = todos)
  let refMonth = null;     // { y, m } do mês de referência (null = mês atual) — só no modo "Mês"

  function range() { return (window.state.prefs && window.state.prefs.activityRange) || "day"; }
  function currentMonth() { const n = new Date(); return { y: n.getFullYear(), m: n.getMonth() }; }
  function isCurrentMonth(r) { const c = currentMonth(); return r.y === c.y && r.m === c.m; }

  function fromForRange() {
    const now = new Date();
    const d = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    if (range() === "week") d.setDate(d.getDate() - 6);
    else if (range() === "month") d.setMonth(d.getMonth(), 1);
    return d;
  }

  // Janela do mês de referência (modo "Mês"): mês inteiro; se for o mês atual, vai até agora.
  function monthBounds() {
    const r = refMonth || currentMonth();
    const from = new Date(r.y, r.m, 1);
    const to = isCurrentMonth(r) ? null : new Date(r.y, r.m + 1, 0, 23, 59, 59, 999);
    return { from, to };
  }
  function monthLabel() {
    const r = refMonth || currentMonth();
    const s = new Date(r.y, r.m, 1).toLocaleDateString("pt-BR", { month: "long", year: "numeric" });
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  function params() {
    const p = {};
    if (range() === "month") {
      const { from, to } = monthBounds();
      p.from = from.toISOString();
      if (to) p.to = to.toISOString();
    } else {
      p.from = fromForRange().toISOString();
    }
    if (scope !== "me") p.workspace = scope;
    return p;
  }

  function load() {
    if (loading) return;
    loading = true; cache = null;
    Api.activities(params()).then((res) => { cache = res.activities || []; })
      .catch(() => { cache = []; })
      .finally(() => { loading = false; window.App.render(); });
  }
  function reload() { cache = null; load(); }

  // ---------- helpers ----------
  function dayKey(iso) { const d = new Date(iso); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`; }
  function dayLabel(iso) {
    const d = new Date(iso), now = new Date();
    const same = (a, b) => a.toDateString() === b.toDateString();
    const y = new Date(now); y.setDate(now.getDate() - 1);
    if (same(d, now)) return "Hoje";
    if (same(d, y)) return "Ontem";
    return d.toLocaleDateString("pt-BR", { weekday: "long", day: "2-digit", month: "long" });
  }
  function timeOf(iso) { const d = new Date(iso); return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`; }

  function teamWorkspaces() {
    // áreas com membros (compartilhadas comigo ou que eu compartilhei) — base do "time"
    return (window.state.workspaces || []).filter((w) => (w.members || []).length > 0 || w.isOwner === false);
  }
  function currentMembers() {
    const w = (window.state.workspaces || []).find((x) => String(x.id) === String(scope));
    if (!w) return [];
    const list = (w.members || []).slice();
    // inclui o proprietário como membro do time
    list.unshift({ id: "owner:" + w.id, name: (w.ownerName || "Proprietário"), email: "", initials: U.initialsOf ? U.initialsOf(w.ownerName || "") : "", _owner: true });
    return list;
  }

  // ---------- render ----------
  function controlsHTML() {
    const r = range();
    const seg = (id, label) => `<button class="ac-seg${r === id ? " on" : ""}" data-ac-act="range" data-range="${id}">${label}</button>`;
    const teamOn = !!(window.state.prefs && window.state.prefs.teamActivityEnabled);
    if (!teamOn && scope !== "me") scope = "me";
    const teams = teamWorkspaces();
    const scopeSel = (teamOn && teams.length) ? `
      <select class="ac-scope" data-ac-act="scope">
        <option value="me"${scope === "me" ? " selected" : ""}>Minhas atividades</option>
        ${teams.map((w) => `<option value="${w.id}"${String(scope) === String(w.id) ? " selected" : ""}>Time · ${U.esc(w.name)}</option>`).join("")}
      </select>` : "";
    let memberChips = "";
    if (scope !== "me") {
      const members = currentMembers();
      memberChips = `<div class="ac-members">${members.map((m) => {
        const on = !memberFilter || memberFilter.has(String(m.id));
        return `<button class="ac-member${on ? " on" : ""}" data-ac-act="member" data-id="${m.id}">${U.avatarHTML(m, "ac-member-ava")}<span>${U.esc(m.name)}</span></button>`;
      }).join("")}</div>`;
    }
    const monthNav = r === "month" ? `
      <div class="ac-month" role="group" aria-label="Mês de referência">
        <button class="ac-month-btn" data-ac-act="month-prev" title="Mês anterior" aria-label="Mês anterior">${icon("ChevLeft", 18)}</button>
        <span class="ac-month-label">${monthLabel()}</span>
        <button class="ac-month-btn" data-ac-act="month-next" title="Próximo mês" aria-label="Próximo mês"${isCurrentMonth(refMonth || currentMonth()) ? " disabled" : ""}>${icon("ChevRight", 18)}</button>
      </div>` : "";
    return `
      <div class="ac-controls">
        <div class="ac-seg-group">${seg("day", "Dia")}${seg("week", "Semana")}${seg("month", "Mês")}</div>
        ${monthNav}
        ${scopeSel}
      </div>
      ${memberChips}`;
  }

  function itemHTML(a) {
    const who = a.by === "IA" ? "Assistente" : a.by;
    const ctx = [a.taskTitle ? U.esc(a.taskTitle) : null, a.project ? U.esc(a.project) : null].filter(Boolean).join(" · ");
    const dotStyle = a.kind === "diary" ? ' style="border-color:var(--p-media)"' : (a.by === "IA" ? ' style="border-color:#7c3aed"' : "");
    const tag = a.kind === "diary" ? `<span class="ac-kind" title="Diário">${icon("BookOpen", 12)}</span>` : "";
    return `
      <div class="hist-item ac-item">
        <span class="hist-dot"${dotStyle}></span>
        <div class="hist-text"><b>${U.esc(who)}</b> ${a.action}${tag}${ctx ? `<span class="ac-ctx">${ctx}</span>` : ""}</div>
        <div class="hist-time">${timeOf(a.at)}</div>
      </div>`;
  }

  function filtered() {
    let list = (cache || []);
    if (scope !== "me" && memberFilter) {
      list = list.filter((a) => memberFilter.has(String(a.byId)) || (memberFilter.has("owner:" + scope)));
    }
    const q = (window.state.query || "").trim().toLowerCase();
    if (q) list = list.filter((a) => (a.taskTitle || "").toLowerCase().includes(q) || U.stripHtml(a.action || "").toLowerCase().includes(q) || (a.by || "").toLowerCase().includes(q));
    return list;
  }

  function activitiesPageHTML() {
    if (cache === null) { if (!loading) load(); return controlsHTML() + emptyBlock("hourglass_top", "Carregando atividades…"); }
    const list = filtered();
    if (!list.length) return controlsHTML() + emptyBlock("history", "Nenhuma atividade no período", "Ajuste o intervalo (Dia/Semana/Mês) ou registre ações nas tarefas.");

    // agrupa por dia
    const groups = [];
    const byDay = {};
    list.forEach((a) => { const k = dayKey(a.at); if (!byDay[k]) { byDay[k] = []; groups.push(k); } byDay[k].push(a); });

    const sections = groups.map((k) => `
      <div class="ac-day">
        <div class="ac-day-head"><span class="ac-day-label">${dayLabel(byDay[k][0].at)}</span><span class="ac-day-count">${byDay[k].length}</span></div>
        <div class="hist ac-timeline">${byDay[k].map(itemHTML).join("")}</div>
      </div>`).join("");
    return controlsHTML() + `<div class="ac-wrap">${sections}</div>`;
  }

  function emptyBlock(materialIcon, title, text) {
    return `<div class="empty"><div class="empty-ico"><span class="material-symbols-outlined" style="font-size:28px">${materialIcon}</span></div>
      <h3>${title}</h3>${text ? `<p>${text}</p>` : ""}</div>`;
  }

  // ---------- eventos ----------
  document.addEventListener("click", (e) => {
    const el = e.target.closest("[data-ac-act]");
    if (!el) return;
    const act = el.dataset.acAct;
    if (act === "range") {
      const r = el.dataset.range;
      if (r === range()) return;
      refMonth = null; // troca de granularidade volta ao período atual
      window.state.prefs = Object.assign({}, window.state.prefs, { activityRange: r });
      Api.savePrefs({ activityRange: r }).catch(() => {});
      reload();
    } else if (act === "month-prev") {
      const r = refMonth || currentMonth();
      let y = r.y, m = r.m - 1; if (m < 0) { m = 11; y--; }
      refMonth = { y, m };
      reload();
    } else if (act === "month-next") {
      const r = refMonth || currentMonth();
      if (isCurrentMonth(r)) return;
      let y = r.y, m = r.m + 1; if (m > 11) { m = 0; y++; }
      refMonth = isCurrentMonth({ y, m }) ? null : { y, m };
      reload();
    } else if (act === "member") {
      const id = String(el.dataset.id);
      if (!memberFilter) memberFilter = new Set(currentMembers().map((m) => String(m.id)));
      if (memberFilter.has(id)) memberFilter.delete(id); else memberFilter.add(id);
      if (memberFilter.size === currentMembers().length) memberFilter = null; // todos = sem filtro
      window.App.render();
    }
  });
  document.addEventListener("change", (e) => {
    const sel = e.target.closest('.ac-scope[data-ac-act="scope"]');
    if (!sel) return;
    scope = sel.value;
    memberFilter = null;
    reload();
  });

  // recarrega ao entrar na página
  window.ActivitiesPage = { load, reload, reset() { cache = null; refMonth = null; } };
  window.Render.activitiesPageHTML = activitiesPageHTML;
})();
