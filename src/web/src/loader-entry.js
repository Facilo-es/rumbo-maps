import { createRumbo } from './loader.js';

(function () {
  if (window.Rumbo && window.Rumbo.scan) return; // loaded twice
  const cs = document.currentScript; // must be read now, while this script runs
  const r = createRumbo({ scriptSrc: cs && cs.src });
  window.Rumbo = r;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => r.scan());
  else r.scan();
})();
