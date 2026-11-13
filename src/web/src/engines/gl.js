// Engine for MapLibre GL JS (OpenFreeMap, MapTiler, any style URL) and Mapbox GL JS: their APIs match.
// Every engine speaks the same small interface, used by map.js:
//   { native, addMarker(el, [lng,lat], {anchor, popup}) -> {remove, el, lngLat}, setView(ll, zoom, animate),
//     fit(points, padding, maxZoom), zoom(), bounds() -> [w,s,e,n] | null, onMove(fn), loaded, resize(), destroy() }
// Zoom levels are always MapLibre's (512 px tiles); engines with 256 px tiles convert.
import { deferred, lang2 } from './sdk.js';

/** Rewrites label layers to the page language where the tiles carry name:xx (OpenMapTiles, MapTiler). */
export function localize(map, lang) {
  const l = lang2(lang);
  if (!l) return 0;
  let n = 0;
  const style = map.getStyle && map.getStyle();
  ((style && style.layers) || []).forEach((layer) => {
    if (layer.type !== 'symbol') return;
    const tf = map.getLayoutProperty(layer.id, 'text-field');
    if (tf == null || !/name/.test(JSON.stringify(tf))) return; // house numbers, road refs stay as they are
    map.setLayoutProperty(layer.id, 'text-field', ['coalesce', ['get', 'name:' + l], ['get', 'name:latin'], ['get', 'name']]);
    n++;
  });
  return n;
}

export function glEngine(gl, o) {
  const loaded = deferred();
  const opts = {
    container: o.container,
    style: o.style,
    center: o.center,
    zoom: o.zoom,
    scrollZoom: !!o.scrollZoom,
    dragRotate: false,
    pitchWithRotate: false,
    touchPitch: false,
    attributionControl: false, // added below, never compact: attribution is always visible
  };
  if (o.mapbox && lang2(o.lang)) opts.language = lang2(o.lang); // Mapbox GL JS 3 translates its own styles
  const map = new gl.Map(opts);
  map.addControl(new gl.AttributionControl({ compact: false }));
  if (map.touchZoomRotate) map.touchZoomRotate.disableRotation();
  map.addControl(new gl.NavigationControl({ showCompass: false }), 'top-right');
  map.on('load', () => {
    if (!o.mapbox) localize(map, o.lang);
    loaded.resolve();
  });
  map.on('error', (e) => {
    const err = e && e.error;
    const status = err && (err.status || (err.response && err.response.status));
    const msg = (err && err.message) || 'Map error';
    if (o.onError) o.onError(msg, status);
    loaded.reject(new Error(status ? msg + ' (HTTP ' + status + ')' : msg));
  });

  return {
    native: map,
    loaded: loaded.promise,
    addMarker(el, ll, m = {}) {
      const mk = new gl.Marker({ element: el, anchor: m.anchor || 'bottom' }).setLngLat(ll);
      if (m.popup) mk.setPopup(new gl.Popup({ offset: 30, closeButton: true, maxWidth: '280px' }).setHTML(m.popup));
      mk.addTo(map);
      return { remove: () => mk.remove(), el: () => mk.getElement(), lngLat: () => { const p = mk.getLngLat(); return [p.lng, p.lat]; } };
    },
    setView(ll, zoom, animate) { map[animate ? 'easeTo' : 'jumpTo']({ center: ll, zoom }); },
    fit(points, padding, maxZoom) {
      const b = new gl.LngLatBounds();
      points.forEach((p) => b.extend(p));
      map.fitBounds(b, { padding, maxZoom, duration: 0 });
    },
    zoom: () => map.getZoom(),
    bounds() { const b = map.getBounds(); return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]; },
    onMove(fn) { map.on('moveend', fn); },
    resize() { map.resize(); },
    destroy() { map.remove(); },
  };
}
