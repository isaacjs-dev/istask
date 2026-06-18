/* Exportação de Notas (Fase 6). window.NotesExport.copyNote(id) copia a nota como
   Markdown para a área de transferência; .exportMd(id) baixa um arquivo .md.
   100% client-side, sem backend. */
(function () {
  function noteToMarkdown(n) {
    let md = "";
    if (n.title) md += `# ${n.title}\n\n`;
    if (n.type === "checklist") {
      md += (n.items || []).map((it) => `- [${it.done ? "x" : " "}] ${it.text}`).join("\n");
    } else if (n.body) {
      md += n.body;
    }
    const labels = (n.labels || []).map((l) => `#${l.name}`);
    const tags = (n.tags || "").split(",").map((t) => t.trim()).filter(Boolean).map((t) => `#${t}`);
    const tagLine = labels.concat(tags);
    if (tagLine.length) md += `\n\n${tagLine.join(" ")}`;
    return md.trim() + "\n";
  }

  function fallbackCopy(text, done) {
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.style.position = "fixed";
    ta.style.opacity = "0";
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand("copy"); } catch (_) { ok = false; }
    ta.remove();
    ok ? done() : window.App.toast("Não foi possível copiar.");
  }

  function copyNote(id) {
    const n = window.Notes && window.Notes.findNote(id);
    if (!n) return;
    const text = noteToMarkdown(n);
    const done = () => window.App.toast("Nota copiada");
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
    } else {
      fallbackCopy(text, done);
    }
  }

  function exportMd(id) {
    const n = window.Notes && window.Notes.findNote(id);
    if (!n) return;
    const md = noteToMarkdown(n);
    const slug = (n.title || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "")
      .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 40) || `nota-${id}`;
    const blob = new Blob([md], { type: "text/markdown;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${slug}.md`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    window.App.toast("Arquivo .md exportado");
  }

  window.NotesExport = { copyNote, exportMd };
})();
