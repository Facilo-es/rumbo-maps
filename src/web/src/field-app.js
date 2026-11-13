// MapLibre adapter for the Location field editor.
import maplibregl from './ml.js';
import { watchFields, mountField } from './field.js';

export function createMap(container, opts) {
  const map = new maplibregl.Map({
    container,
    style: opts.style,
    center: opts.center,
    zoom: opts.zoom,
    dragRotate: false,
    pitchWithRotate: false,
    attributionControl: { compact: false },
  });
  map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
  let pin = null;
  const drag = [];
  const pinEl = () => {
    const e = document.createElement('div');
    e.className = 'rumbo-f-pin';
    e.innerHTML = '<svg viewBox="0 0 28 36" aria-hidden="true"><path d="M14 35s11-11.6 11-21A11 11 0 0 0 3 14c0 9.4 11 21 11 21z" fill="currentColor" stroke="#fff" stroke-width="2.5"/><circle cx="14" cy="14" r="4.2" fill="#fff"/></svg>';
    return e;
  };
  // Craft tabs and Matrix blocks can mount us while hidden: resize when we become visible.
  if (window.ResizeObserver) new ResizeObserver(() => map.resize()).observe(container);
  return {
    map,
    setPin(ll) {
      if (!pin) {
        pin = new maplibregl.Marker({ element: pinEl(), draggable: true, anchor: 'bottom' }).setLngLat(ll).addTo(map);
        pin.on('dragend', () => { const p = pin.getLngLat(); drag.forEach((f) => f([p.lng, p.lat])); });
      } else pin.setLngLat(ll);
    },
    removePin() { if (pin) { pin.remove(); pin = null; } },
    onDragEnd(fn) { drag.push(fn); },
    onClick(fn) { map.on('click', (e) => fn([e.lngLat.lng, e.lngLat.lat])); },
    onZoom(fn) { map.on('zoomend', (e) => { if (e.originalEvent) fn(map.getZoom()); }); }, // user zooms only, so opening an entry never dirties it
    flyTo(ll, zoom) { map.easeTo({ center: ll, zoom }); },
    getZoom() { return map.getZoom(); },
  };
}

export function start(doc) {
  return watchFields(doc || document, { createMap, lazy: true });
}

export { mountField };
