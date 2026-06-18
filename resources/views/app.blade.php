<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Minhas Tarefas</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,500;1,600&family=Kalam:wght@400;700&display=swap" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&family=Inter:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('app/styles.css') }}" />
</head>
@php($prefs = $boot['prefs'] ?? ['chatPosition' => 'side', 'chatWidth' => 372, 'chatHeight' => 320, 'chatCollapsed' => false])
<body class="cmd-{{ $prefs['chatPosition'] === 'bottom' ? 'bottom' : 'side' }}{{ !empty($prefs['chatCollapsed']) ? ' chat-collapsed' : '' }}"
      style="--chat-w: {{ (int) $prefs['chatWidth'] }}px; --chat-h: {{ (int) $prefs['chatHeight'] }}px;">
  <div id="root"></div>

  {{-- Estado inicial injetado pelo servidor (substitui o antigo seed do data.js) --}}
  <script>
    window.__BOOT__ = @json($boot);
    window.__BASE__ = "{{ rtrim(url('/'), '/') }}";
  </script>

  {{-- Mesmos módulos do protótipo; apenas data.js e main.js foram adaptados para a API --}}
  <script src="{{ asset('app/data.js') }}"></script>
  <script src="{{ asset('app/icons-v.js') }}"></script>
  <script src="{{ asset('app/ui.js') }}"></script>
  <script src="{{ asset('app/render.js') }}"></script>
  <script src="{{ asset('app/notes-page.js') }}"></script>
  <script src="{{ asset('app/notes-modals.js') }}"></script>
  <script src="{{ asset('app/notes-views.js') }}"></script>
  <script src="{{ asset('app/notes-canvas.js') }}"></script>
  <script src="{{ asset('app/notes-audio.js') }}"></script>
  <script src="{{ asset('app/notes-reminder.js') }}"></script>
  <script src="{{ asset('app/notes-collab.js') }}"></script>
  <script src="{{ asset('app/notes-export.js') }}"></script>
  {{-- OCR client-side: Tesseract via CDN (sem build) + wrapper window.OCR --}}
  <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js" defer></script>
  <script src="{{ asset('app/notes-ocr.js') }}"></script>
  <script src="{{ asset('app/diary-page.js') }}"></script>
  <script src="{{ asset('app/projects-modal.js') }}"></script>
  <script src="{{ asset('app/workspaces-modal.js') }}"></script>
  <script src="{{ asset('app/share-modal.js') }}"></script>
  <script src="{{ asset('app/modal-v.js') }}"></script>
  <script src="{{ asset('app/main.js') }}"></script>
</body>
</html>
