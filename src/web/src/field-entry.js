// Classic script for the Craft control panel: loads the field editor (and MapLibre) as a module
// from the same folder, on DOMContentLoaded.
(function () {
  if (window.RumboField) return;
  const cs = document.currentScript;
  const base = new URL('./', (cs && (cs.getAttribute('data-rumbo-base') || cs.src)) || document.baseURI);
  window.RumboField = true;
  const go = () => {
    import(/* @vite-ignore */ new URL('rumbo-field-app.js', base).href)
      .then((m) => m.start(document))
      .catch((e) => console.warn('[Rumbo Maps]', e));
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go);
  else go();
})();
