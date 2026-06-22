/* Diálogos reutilizáveis e acessíveis (substituem window.confirm/prompt nativos).
   window.Modals.confirm({...}) -> Promise<boolean>
   window.Modals.prompt({...})  -> Promise<string|null>  (null = cancelado)
   Acessível: role="dialog" aria-modal, foco preso, ESC/overlay/Cancelar fecham,
   Enter confirma, foco devolvido ao elemento anterior. Tema-aware (tokens). */
(function () {
  const U = window.UI;
  const icon = window.icon;

  function host() {
    let h = document.getElementById("umHost");
    if (!h) { h = document.createElement("div"); h.id = "umHost"; document.body.appendChild(h); }
    return h;
  }

  function esc(s) { return U ? U.esc(s) : (s == null ? "" : String(s)); }

  function open({ kind, title, message, label, value, placeholder, okText, cancelText, danger, maxlength }) {
    return new Promise((resolve) => {
      const h = host();
      const prev = document.activeElement;
      const isPrompt = kind === "prompt";
      okText = okText || (isPrompt ? "Salvar" : "Confirmar");
      cancelText = cancelText || "Cancelar";
      h.innerHTML = `
        <div class="um-overlay" data-um="cancel">
          <div class="um-modal" role="dialog" aria-modal="true" aria-labelledby="umTitle" data-stop>
            <div class="um-head">
              <div class="um-title" id="umTitle">${esc(title || (isPrompt ? "" : "Confirmar"))}</div>
              <button class="um-x" data-um="cancel" aria-label="Fechar">${icon("X", 16)}</button>
            </div>
            ${message ? `<p class="um-msg">${esc(message)}</p>` : ""}
            ${isPrompt ? `
              ${label ? `<label class="um-label" for="umInput">${esc(label)}</label>` : ""}
              <input id="umInput" class="um-input" type="text" value="${esc(value || "")}" placeholder="${esc(placeholder || "")}"${maxlength ? ` maxlength="${maxlength}"` : ""} />
            ` : ""}
            <div class="um-foot">
              <button class="um-btn um-cancel" data-um="cancel">${esc(cancelText)}</button>
              <button class="um-btn um-ok${danger ? " danger" : ""}" data-um="ok">${esc(okText)}</button>
            </div>
          </div>
        </div>`;

      const overlay = h.querySelector(".um-overlay");
      const input = h.querySelector(".um-input");

      function done(result) {
        document.removeEventListener("keydown", onKey, true);
        h.innerHTML = "";
        if (prev && prev.focus) { try { prev.focus(); } catch (e) {} }
        resolve(result);
      }
      function confirm() { done(isPrompt ? (input ? input.value : "") : true); }
      function cancel() { done(isPrompt ? null : false); }

      function onKey(e) {
        if (e.key === "Escape") { e.preventDefault(); cancel(); }
        else if (e.key === "Enter" && (isPrompt || document.activeElement && document.activeElement.classList.contains("um-ok"))) { e.preventDefault(); confirm(); }
        else if (e.key === "Tab") { trap(e); }
      }
      function trap(e) {
        const f = h.querySelectorAll("button, input, [tabindex]");
        if (!f.length) return;
        const first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }

      overlay.addEventListener("click", (e) => {
        const el = e.target.closest("[data-um]");
        if (!el) return;
        if (el.classList.contains("um-overlay") && e.target !== el) return; // só fecha no clique do backdrop
        if (el.dataset.um === "ok") confirm(); else cancel();
      });
      document.addEventListener("keydown", onKey, true);

      setTimeout(() => {
        if (input) { input.focus(); input.select(); }
        else { const ok = h.querySelector(".um-ok"); if (ok) ok.focus(); }
      }, 30);
    });
  }

  window.Modals = {
    confirm: (opts) => open(Object.assign({ kind: "confirm" }, opts || {})),
    prompt: (opts) => open(Object.assign({ kind: "prompt" }, opts || {})),
  };
})();
