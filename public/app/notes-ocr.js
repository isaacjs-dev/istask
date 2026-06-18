/* OCR client-side das Notas (Fase 2). Expõe window.OCR.recognize(imageUrlOrBlob)
   usando a global Tesseract carregada via CDN (<script> em app.blade.php). A
   primeira execução baixa os dados do modelo (alguns segundos). Idioma: português
   + inglês. Sem dependência de build/Vite — segue o padrão script-src do app. */
(function () {
  function recognize(image) {
    if (!window.Tesseract || typeof window.Tesseract.recognize !== "function") {
      return Promise.reject(new Error("Tesseract indisponível"));
    }
    return window.Tesseract.recognize(image, "por+eng")
      .then((res) => (res && res.data && res.data.text) || "");
  }

  window.OCR = { recognize, available: () => !!(window.Tesseract && window.Tesseract.recognize) };
})();
