// HERE Maps API for JavaScript 3.1, API key only (never app_id/app_code), loaded from HERE only when chosen.
// HERE zoom = MapLibre zoom + 1. DomIcon clones the element on every attach, so the clone forwards clicks.
import { script, css, deferred, lang2, htmlEl } from './sdk.js';
import { styleName } from './styles.js';

const BASE = 'https://js.api.here.com/v3/3.1/';
export const SCRIPTS = ['mapsjs-core.js', 'mapsjs-service.js', 'mapsjs-mapevents.js', 'mapsjs-ui.js'].map((f) => BASE + f);

function loadSdk(win) {
  if (win.H && win.H.ui && win.H.mapevents) return Promise.resolve();
  // core first, then the rest in order: each module extends window.H
  return SCRIPTS.reduce((p, u) => p.then(() => script(u, { charset: 'utf-8' })), Promise.resolve());
}

export function create(o) {
  const win = o.win || window;
  css(BASE + 'mapsjs-ui.css');
  return loadSdk(win).then(() => {
    const H = win.H;
    const l = lang2(o.lang) || 'en';
    const platform = new H.service.Platform({ apikey: o.key || '' });
    const layers = platform.createDefaultLayers({ lg: l });
    const base = styleName(o.style) === 'dark' && layers.raster && layers.raster.normal && layers.raster.normal.mapnight
      ? layers.raster.normal.mapnight : layers.vector.normal.map;
    const map = new H.Map(o.container, base, {
      center: { lat: o.center[1], lng: o.center[0] },
      zoom: o.zoom + 1,
      pixelRatio: win.devicePixelRatio || 1,
    });
    const behavior = new H.mapevents.Behavior(new H.mapevents.MapEvents(map));
    if (!o.scrollZoom && behavior.disable && H.mapevents.Behavior.Feature) behavior.disable(H.mapevents.Behavior.Feature.WHEEL_ZOOM);
    const ui = H.ui.UI.createDefault(map, layers, l === 'es' ? 'es-ES' : 'en-US');
    const loaded = deferred();
    const provider = base.getProvider && base.getProvider();
    if (provider && provider.addEventListener) {
      provider.addEventListener('error', (e) => {
        const msg = 'HERE refused the map tiles' + (e && e.message ? ': ' + e.message : '') + '. Check the API key and that Maps API for JavaScript is enabled for it.';
        if (o.onError) o.onError(msg);
        loaded.reject(new Error(msg));
      });
    }
    map.addEventListener('mapviewchangeend', () => loaded.resolve());
    const onResize = () => map.getViewPort().resize();
    win.addEventListener('resize', onResize);
    let bubble = null;

    return {
      native: map,
      loaded: loaded.promise,
      addMarker(el, ll, m = {}) {
        const holder = el.ownerDocument.createElement('div');
        holder.className = 'rumbo-here rumbo-here--' + (m.anchor === 'center' ? 'c' : 'b');
        holder.appendChild(el);
        let live = null;
        const icon = new H.map.DomIcon(holder, {
          onAttach(clone) {
            live = clone.firstChild;
            live.className = el.className;
            clone.addEventListener('click', (e) => { e.stopPropagation(); el.click(); });
          },
          onDetach() { live = null; },
        });
        const mk = new H.map.DomMarker({ lat: ll[1], lng: ll[0] }, { icon });
        if (m.popup) {
          el.addEventListener('click', () => {
            if (bubble) ui.removeBubble(bubble);
            bubble = new H.ui.InfoBubble({ lat: ll[1], lng: ll[0] }, { content: htmlEl(m.popup, el.ownerDocument) });
            ui.addBubble(bubble);
          });
        }
        map.addObject(mk);
        // Highlight state is set on el; mirror it on the live clone.
        const mirror = win.MutationObserver ? new win.MutationObserver(() => { if (live) live.className = el.className; }) : null;
        if (mirror) mirror.observe(el, { attributes: true, attributeFilter: ['class'] });
        return { remove: () => { map.removeObject(mk); if (mirror) mirror.disconnect(); }, el: () => el, lngLat: () => ll };
      },
      setView(ll, zoom, animate) { map.getViewModel().setLookAtData({ position: { lat: ll[1], lng: ll[0] }, zoom: zoom + 1 }, !!animate); },
      fit(points, padding, maxZoom) {
        let n = -90, s = 90, w = 180, e = -180;
        points.forEach(([x, y]) => { n = Math.max(n, y); s = Math.min(s, y); w = Math.min(w, x); e = Math.max(e, x); });
        map.getViewPort().setPadding(padding, padding, padding, padding);
        map.getViewModel().setLookAtData({ bounds: new H.geo.Rect(n, w, s, e) });
        if (map.getZoom() > maxZoom + 1) map.setZoom(maxZoom + 1);
      },
      zoom: () => map.getZoom() - 1,
      bounds() {
        const r = map.getViewModel().getLookAtData().bounds;
        const b = r && (r.getBoundingBox ? r.getBoundingBox() : r);
        return b ? [b.getLeft(), b.getBottom(), b.getRight(), b.getTop()] : null;
      },
      onMove(fn) { map.addEventListener('mapviewchangeend', fn); },
      resize: onResize,
      destroy() { win.removeEventListener('resize', onResize); map.dispose(); },
    };
  });
}
