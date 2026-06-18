/* Icons as SVG strings. window.icon(name, size, extraAttrs) */
(function () {
  // inner = svg children markup; sw = optional stroke width override
  const I = {
    Plus: { inner: '<path d="M12 5v14"/><path d="M5 12h14"/>' },
    Search: { inner: '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>' },
    List: { inner: '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3.5" y1="6" x2="3.51" y2="6"/><line x1="3.5" y1="12" x2="3.51" y2="12"/><line x1="3.5" y1="18" x2="3.51" y2="18"/>' },
    Kanban: { inner: '<rect x="3" y="4" width="5" height="16" rx="1.5"/><rect x="9.5" y="4" width="5" height="11" rx="1.5"/><rect x="16" y="4" width="5" height="13" rx="1.5"/>' },
    Calendar: { inner: '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="8" y1="2.5" x2="8" y2="6"/><line x1="16" y1="2.5" x2="16" y2="6"/>' },
    Check: { inner: '<path d="M20 6 9 17l-5-5"/>' },
    CheckSmall: { inner: '<path d="M20 6 9 17l-5-5"/>', sw: 2.6 },
    Clock: { inner: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 1.8"/>' },
    Clock2: { inner: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 1.8"/>' },
    Flag: { inner: '<path d="M5 21V4"/><path d="M5 4h11l-1.5 3.5L16 11H5"/>' },
    Comment: { inner: '<path d="M21 11.5a8 8 0 0 1-11.5 7.2L4 20l1.3-4.5A8 8 0 1 1 21 11.5Z"/>' },
    Checklist: { inner: '<path d="M4 6.5 5.2 7.8 8 5"/><path d="M4 13.5 5.2 14.8 8 12"/><line x1="11" y1="6.5" x2="20" y2="6.5"/><line x1="11" y1="13.5" x2="20" y2="13.5"/><line x1="11" y1="18.5" x2="16" y2="18.5"/><path d="M4 18.5h1.5"/>' },
    Send: { inner: '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/>' },
    Sparkles: { inner: '<path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3z"/><path d="M19 14l.8 2.2L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.8L19 14z"/>' },
    Folder: { inner: '<path d="M3 7a2 2 0 0 1 2-2h3.5l2 2.5H19a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>' },
    Filter: { inner: '<path d="M3 5h18l-7 8v6l-4-2v-4L3 5z"/>' },
    Dots: { inner: '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>' },
    DotsV: { inner: '<circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/>' },
    User: { inner: '<circle cx="12" cy="8" r="4"/><path d="M5 20c0-3.5 3.1-6 7-6s7 2.5 7 6"/>' },
    X: { inner: '<path d="M18 6 6 18"/><path d="M6 6l12 12"/>' },
    ChevDown: { inner: '<path d="m6 9 6 6 6-6"/>' },
    ChevRight: { inner: '<path d="m9 6 6 6-6 6"/>' },
    ChevLeft: { inner: '<path d="m15 6-6 6 6 6"/>' },
    Inbox: { inner: '<path d="M3 12h5l2 3h4l2-3h5"/><path d="M5 5h14l2 7v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6l2-7z"/>' },
    Hourglass: { inner: '<path d="M7 3h10"/><path d="M7 21h10"/><path d="M7 3c0 4 5 5 5 9s-5 5-5 9"/><path d="M17 3c0 4-5 5-5 9s5 5 5 9"/>' },
    Alert: { inner: '<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="13"/><line x1="12" y1="16" x2="12.01" y2="16"/>' },
    Merge: { inner: '<path d="M7 21V9"/><path d="M7 9a5 5 0 0 0 5 5h6"/><path d="M4 6l3-3 3 3"/><path d="M16 11l3 3-3 3"/>' },
    Trash: { inner: '<path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>' },
    Tag: { inner: '<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9-9-9z"/><circle cx="7.5" cy="7.5" r="1.3"/>' },
    Bold: { inner: '<path d="M7 5h6a3.5 3.5 0 0 1 0 7H7z"/><path d="M7 12h7a3.5 3.5 0 0 1 0 7H7z"/>', sw: 2 },
    Italic: { inner: '<path d="M19 5h-6"/><path d="M11 19H5"/><path d="M15 5 9 19"/>', sw: 2 },
    Underline: { inner: '<path d="M7 4v6a5 5 0 0 0 10 0V4"/><path d="M5 21h14"/>', sw: 2 },
    Strike: { inner: '<path d="M16 5H9.5a2.5 2.5 0 0 0-1 4.8"/><path d="M4 12h16"/><path d="M8 19h6.5a2.5 2.5 0 0 0 1-4.8"/>', sw: 2 },
    ListUl: { inner: '<line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>' },
    ListOl: { inner: '<line x1="10" y1="6" x2="20" y2="6"/><line x1="10" y1="12" x2="20" y2="12"/><line x1="10" y1="18" x2="20" y2="18"/><path d="M4 4v4"/><path d="M3.5 8h1.2"/><path d="M3.5 14h1.6l-1.6 2h1.6"/>' },
    History: { inner: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 4v4h4"/><path d="M12 8v4l3 2"/>' },
    Bell: { inner: '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>' },
    Refresh: { inner: '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 4v5h-5"/>' },
    Logout: { inner: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>' },
    Menu: { inner: '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>' },
    Archive: { inner: '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><line x1="10" y1="12" x2="14" y2="12"/>' },
    Pencil: { inner: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>' },
    Settings: { inner: '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 13.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0-1.1-2.7H1a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 2.6 7a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H7a1.6 1.6 0 0 0 1-1.5V1a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1 1.6 1.6 0 0 0-.3 1.8z"/>' },
    NotebookPen: { inner: '<rect x="4" y="3" width="16" height="18" rx="2"/><line x1="8.5" y1="3" x2="8.5" y2="21"/><path d="M12 16l1-3.5L17 8l2 2-4.5 4.5z"/>' },
    BookOpen: { inner: '<path d="M12 7v13"/><path d="M3 18a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1V17a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>' },
  };

  window.icon = function (name, size, extra) {
    const def = I[name];
    if (!def) return "";
    const s = size || 18;
    const sw = def.sw || 1.9;
    return `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round"${extra ? " " + extra : ""}>${def.inner}</svg>`;
  };
})();
