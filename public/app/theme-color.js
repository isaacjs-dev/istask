/* Esquemas de cor (Fase C) + utilidades de cor reaproveitadas pela personalização
   manual (Fase D). Um "esquema" é uma cor-base; o sistema DERIVA a família de
   accent (600/700/soft/soft-2/on-accent) conforme o modo (claro/escuro) e aplica
   inline em <html> sobre o tema atual. "Usar cores padrão do tema" = limpar a
   sobreposição. window.ThemeColor.{SCHEMES, apply, deriveAccent}. */
(function () {
  // ---------- utilidades de cor ----------
  function hexToRgb(h) {
    h = String(h || "").replace("#", "");
    if (h.length === 3) h = h.split("").map((c) => c + c).join("");
    const n = parseInt(h || "000000", 16);
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
  }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function rgbToHex(r, g, b) {
    const c = (v) => clamp(Math.round(v), 0, 255).toString(16).padStart(2, "0");
    return "#" + c(r) + c(g) + c(b);
  }
  function mix(h1, h2, t) { // t=0 → h1, t=1 → h2
    const a = hexToRgb(h1), b = hexToRgb(h2);
    return rgbToHex(a.r + (b.r - a.r) * t, a.g + (b.g - a.g) * t, a.b + (b.b - a.b) * t);
  }
  function hexToHsl(h) {
    let { r, g, b } = hexToRgb(h); r /= 255; g /= 255; b /= 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b); let hh = 0, s = 0; const l = (max + min) / 2;
    if (max !== min) {
      const d = max - min;
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      if (max === r) hh = (g - b) / d + (g < b ? 6 : 0);
      else if (max === g) hh = (b - r) / d + 2;
      else hh = (r - g) / d + 4;
      hh /= 6;
    }
    return { h: hh * 360, s: s * 100, l: l * 100 };
  }
  function hslToHex(h, s, l) {
    h = ((h % 360) + 360) % 360 / 360; s = clamp(s, 0, 100) / 100; l = clamp(l, 0, 100) / 100;
    const hue2rgb = (p, q, t) => {
      if (t < 0) t += 1; if (t > 1) t -= 1;
      if (t < 1 / 6) return p + (q - p) * 6 * t;
      if (t < 1 / 2) return q;
      if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
      return p;
    };
    let r, g, b;
    if (s === 0) { r = g = b = l; }
    else { const q = l < 0.5 ? l * (1 + s) : l + s - l * s; const p = 2 * l - q; r = hue2rgb(p, q, h + 1 / 3); g = hue2rgb(p, q, h); b = hue2rgb(p, q, h - 1 / 3); }
    return rgbToHex(r * 255, g * 255, b * 255);
  }
  function adjustL(h, dL) { const c = hexToHsl(h); return hslToHex(c.h, c.s, c.l + dL); }
  function luminance(h) {
    const { r, g, b } = hexToRgb(h);
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  }
  function onColor(h) { return luminance(h) > 0.5 ? "#1c1d29" : "#ffffff"; } // texto legível sobre a cor

  // ---------- derivação da família de accent ----------
  // mode: "light" | "dark". Retorna { '--accent', '--accent-600', '--accent-700',
  // '--accent-soft', '--accent-soft-2', '--on-accent' }.
  function deriveAccent(base, mode) {
    if (mode === "dark") {
      const acc = adjustL(base, 8); // um pouco mais claro p/ legibilidade sobre superfície escura
      return {
        "--accent": acc,
        "--accent-600": adjustL(base, 16),
        "--accent-700": adjustL(base, 34),     // usado como TEXTO sobre soft escuro → claro
        "--accent-soft": mix(base, "#1d1f27", 0.80),
        "--accent-soft-2": mix(base, "#1d1f27", 0.66),
        "--on-accent": onColor(acc),
      };
    }
    return {
      "--accent": base,
      "--accent-600": adjustL(base, -9),
      "--accent-700": adjustL(base, -18),
      "--accent-soft": mix(base, "#ffffff", 0.88),
      "--accent-soft-2": mix(base, "#ffffff", 0.80),
      "--on-accent": onColor(base),
    };
  }

  // ---------- esquemas predefinidos (≥12) ----------
  const SCHEMES = [
    { id: "indigo", name: "Índigo", base: "#4f46e5" },
    { id: "azul", name: "Azul", base: "#2563eb" },
    { id: "ceu", name: "Céu", base: "#0284c7" },
    { id: "ciano", name: "Ciano", base: "#0891b2" },
    { id: "teal", name: "Teal", base: "#0d9488" },
    { id: "esmeralda", name: "Esmeralda", base: "#059669" },
    { id: "verde", name: "Verde", base: "#16a34a" },
    { id: "ambar", name: "Âmbar", base: "#d97706" },
    { id: "laranja", name: "Laranja", base: "#ea580c" },
    { id: "vermelho", name: "Vermelho", base: "#dc2626" },
    { id: "rosa", name: "Rosa", base: "#db2777" },
    { id: "roxo", name: "Roxo", base: "#7c3aed" },
  ];
  const VARS = ["--accent", "--accent-600", "--accent-700", "--accent-soft", "--accent-soft-2", "--on-accent"];

  function baseFor(scheme) {
    const s = SCHEMES.find((x) => x.id === scheme);
    return s ? s.base : null;
  }
  // Aplica o esquema (ou cor-base custom) inline em <html>; sem id → restaura o tema.
  function apply(scheme, mode, customBase) {
    const root = document.documentElement;
    const base = customBase || baseFor(scheme);
    if (!base) { VARS.forEach((v) => root.style.removeProperty(v)); return; }
    const vars = deriveAccent(base, mode === "dark" ? "dark" : "light");
    Object.keys(vars).forEach((k) => root.style.setProperty(k, vars[k]));
  }

  // ---------- tipografia (Fase E) ----------
  // ESCOPO: aplica-se EXCLUSIVAMENTE às notas no modo de visualização (cards do
  // mural). Define apenas variáveis consumidas pelas regras .note-postit:not(.editing)
  // — não toca na fonte global, na edição de notas nem em outras telas.
  // id "" = fonte padrão do tema (Plus Jakarta Sans). As demais usam fontes já
  // carregadas (Inter/Kalam) ou pilhas do sistema (sem requisição extra).
  const FONTS = [
    { id: "", name: "Padrão do tema", stack: '"Plus Jakarta Sans", system-ui, sans-serif' },
    { id: "system", name: "Sistema", stack: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' },
    { id: "inter", name: "Inter", stack: '"Inter", system-ui, sans-serif' },
    { id: "serif", name: "Serifada", stack: 'Georgia, "Times New Roman", serif' },
    { id: "kalam", name: "Manuscrita", stack: '"Kalam", cursive' },
    { id: "mono", name: "Monoespaçada", stack: 'ui-monospace, SFMono-Regular, Menlo, monospace' },
  ];
  function fontStack(id) { const f = FONTS.find((x) => x.id === (id || "")); return f && f.id ? f.stack : null; }
  function applyFont(id, scale) {
    const root = document.documentElement;
    const stack = fontStack(id);
    // --note-font / --note-scale só são lidos pelas notas em visualização.
    if (stack) root.style.setProperty("--note-font", stack); else root.style.removeProperty("--note-font");
    const s = +scale || 1;
    if (s && s !== 1) root.style.setProperty("--note-scale", String(s)); else root.style.removeProperty("--note-scale");
  }

  window.ThemeColor = { SCHEMES, FONTS, apply, applyFont, fontStack, deriveAccent, hexToHsl, mix, adjustL, onColor, luminance };
})();
