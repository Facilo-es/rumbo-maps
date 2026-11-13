// Google Maps JavaScript API with Advanced Markers, loaded from Google only when Google is the map provider.
// Styles come from the customer's map ID (Cloud console). Google zoom = MapLibre zoom + 1.
import { script, deferred, lang2, anchorWrap, htmlEl } from './sdk.js';
import { styleName } from './styles.js';

const CB = '__rumboGoogleReady';

export function sdkUrl(o) {
  const q = new URLSearchParams({ key: o.key || '', v: 'weekly', libraries: 'marker', loading: 'async', callback: CB });
  const l = lang2(o.lang);
  const r = String(o.lang || '').match(/^[a-z]{2}[-_]([a-z]{2})$/i);
  if (l) q.set('language', l);
  if (r) q.set('region', r[1].toUpperCase());
  return 'https://maps.googleapis.com/maps/api/js?' + q.toString();
}

const AUTH = 'Google rejected the browser key. Turn on the Maps JavaScript API for this key and allow this website under its restrictions.';

function loadSdk(o, win) {
  if (win.google && win.google.maps && win.google.maps.Map) return Promise.resolve();
  if (win[CB + 'P']) return win[CB + 'P'];
  win[CB + 'P'] = new Promise((resolve, reject) => {
    win[CB] = () => resolve();
    script(sdkUrl(o)).catch(reject);
  });
  return win[CB + 'P'];
}

export function create(o) {
  const win = o.win || window;
  const failed = deferred();
  // Google calls this global on a bad, restricted or disabled key.
  const prev = win.gm_authFailure;
  win.gm_authFailure = function () {
    if (o.onError) o.onError(AUTH);
    failed.reject(new Error(AUTH));
    if (typeof prev === 'function') prev();
  };
  return loadSdk(o, win).then(() => {
    const g = win.google.maps;
    const loaded = deferred();
    failed.promise.catch((e) => loaded.reject(e));
    const opts = {
      center: { lat: o.center[1], lng: o.center[0] },
      zoom: Math.round(o.zoom + 1),
      mapId: o.mapId || 'DEMO_MAP_ID', // Advanced Markers need a map ID
      gestureHandling: o.scrollZoom ? 'greedy' : 'cooperative',
      streetViewControl: false,
      mapTypeControl: false,
      fullscreenControl: false,
      rotateControl: false,
      clickableIcons: false,
    };
    if (styleName(o.style) === 'dark' && g.ColorScheme) opts.colorScheme = g.ColorScheme.DARK;
    const map = new g.Map(o.container, opts);
    let info = null;
    g.event.addListenerOnce(map, 'tilesloaded', () => loaded.resolve());

    return {
      native: map,
      loaded: loaded.promise,
      addMarker(el, ll, m = {}) {
        const mk = new g.marker.AdvancedMarkerElement({
          map,
          position: { lat: ll[1], lng: ll[0] },
          content: anchorWrap(el, m.anchor, el.ownerDocument),
          title: el.getAttribute('aria-label') || '',
        });
        if (m.popup) {
          el.addEventListener('click', () => {
            info = info || new g.InfoWindow({ maxWidth: 280 });
            info.setContent(htmlEl(m.popup, el.ownerDocument));
            info.open({ anchor: mk, map });
          });
        }
        return { remove: () => { mk.map = null; }, el: () => el, lngLat: () => ll };
      },
      setView(ll, zoom, animate) {
        const c = { lat: ll[1], lng: ll[0] };
        map.setZoom(Math.round(zoom + 1));
        if (animate) map.panTo(c); else map.setCenter(c);
      },
      fit(points, padding, maxZoom) {
        const b = new g.LatLngBounds();
        points.forEach((p) => b.extend({ lat: p[1], lng: p[0] }));
        map.fitBounds(b, padding);
        g.event.addListenerOnce(map, 'idle', () => { if (map.getZoom() > maxZoom + 1) map.setZoom(maxZoom + 1); });
      },
      zoom: () => (map.getZoom() || 1) - 1,
      bounds() {
        const b = map.getBounds();
        if (!b) return null;
        const ne = b.getNorthEast(), sw = b.getSouthWest();
        return [sw.lng(), sw.lat(), ne.lng(), ne.lat()];
      },
      onMove(fn) { map.addListener('idle', fn); },
      resize() {},
      destroy() { g.event.clearInstanceListeners(map); if (info) info.close(); o.container.innerHTML = ''; },
    };
  });
}
