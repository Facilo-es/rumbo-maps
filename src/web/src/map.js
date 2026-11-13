// Rumbo Maps map controller. Draws markers, popups, clusters and the visitor's origin on any provider's map
// through a small engine (engines/*.js): MapLibre (bundled, OpenFreeMap + MapTiler), Mapbox GL JS, Google,
// HERE and Apple MapKit JS. Provider SDKs are fetched from the provider only when that provider is chosen.
import Supercluster from 'supercluster';
import { readConfig, esc, safeUrl, fill } from './util.js';

const ENGINES = {
  maplibre: () => import('./engines/maplibre.js'),
  mapbox: () => import('./engines/mapbox.js'),
  google: () => import('./engines/google.js'),
  here: () => import('./engines/here.js'),
  apple: () => import('./engines/apple.js'),
};
const ENGINE_OF = { openfreemap: 'maplibre', maptiler: 'maplibre', mapbox: 'mapbox', google: 'google', here: 'here', apple: 'apple' };

/** cfg.provider as {name, key?, mapId?, tokenUrl?}; a plain string is a name. Unknown names fall back to OpenFreeMap. */
export function providerOf(cfg) {
  const p = typeof cfg.provider === 'string' ? { name: cfg.provider } : Object.assign({}, cfg.provider || {});
  p.name = String(p.name || 'openfreemap').toLowerCase();
  if (!ENGINE_OF[p.name]) p.name = 'openfreemap';
  return p;
}

const PIN_SVG = '<svg viewBox="0 0 28 36" aria-hidden="true" focusable="false"><path d="M14 35s11-11.6 11-21A11 11 0 0 0 3 14c0 9.4 11 21 11 21z" fill="currentColor" stroke="#fff" stroke-width="2.5"/><circle cx="14" cy="14" r="4.2" fill="#fff"/></svg>';

/** Marker images: http(s)/relative URLs, or an image data: URI (an inline SVG the server encoded). */
export function iconSrc(u) {
  const s = String(u == null ? '' : u).trim();
  if (/^data:image\/(svg\+xml|png|gif|webp|jpeg)[;,]/i.test(s)) return s;
  const v = safeUrl(s);
  return v === '#' ? '' : v;
}

function px(v) {
  if (v == null || v === '') return '';
  return /^\d+(\.\d+)?$/.test(String(v)) ? v + 'px' : String(v);
}

function directions(m) {
  return safeUrl(m.directionsUrl) || 'https://www.openstreetmap.org/directions?route=%3B' + m.lat + '%2C' + m.lng;
}

function popupHtml(m, s) {
  if (m.html) return m.html; // rendered server-side by the developer's card partial (trusted Twig output)
  const t = m.url ? '<a href="' + esc(safeUrl(m.url)) + '">' + esc(m.title) + '</a>' : esc(m.title);
  return '<div class="rumbo-pop"><strong class="rumbo-pop__t">' + t + '</strong>' +
    (m.address ? '<span class="rumbo-pop__a">' + esc(m.address) + '</span>' : '') +
    '<a class="rumbo-pop__dir" href="' + esc(directions(m)) + '" target="_blank" rel="noopener">' + esc(s.directions || 'Directions') + '</a></div>';
}

/**
 * Mount a map in `el`. Config comes from the element's JSON blob, merged with `opts`:
 * { provider:{name,key,mapId,tokenUrl}, style, lang, center:[lng,lat], zoom,
 *   markers:[{id?,lat,lng,title,url?,address?,directionsUrl?,html?,icon?}], markerIcon,
 *   cluster, fitBounds, height, scrollZoom, numbered, popups, strings }
 * Returns a controller at once: { map, ready, loaded, setMarkers, highlight, focus, setOrigin, fit, on, destroy }.
 * Calls made before the provider's SDK has loaded are kept and applied when it has.
 * `ready` resolves with the engine once the map exists; `loaded` once the provider has drawn it
 * (both reject with the provider's message on a bad key, a blocked script or no WebGL).
 */
export function mountMap(el, opts) {
  const o = Object.assign({}, opts || {});
  const engines = o.engines || ENGINES;
  delete o.engines;
  const cfg = Object.assign({}, readConfig(el), o);
  const s = cfg.strings || {};
  const provider = providerOf(cfg);
  const h = px(el.getAttribute('data-rumbo-height') || cfg.height);
  if (h) el.style.height = h;

  const canvas = document.createElement('div');
  canvas.className = 'rumbo-map__canvas';
  el.appendChild(canvas);
  el.classList.add('rumbo-map--live');
  el.setAttribute('data-rumbo-engine', provider.name);
  if (/^#[0-9a-f]{3,8}$/i.test(cfg.markerColor || '')) el.style.setProperty('--rumbo-marker', cfg.markerColor);

  const markersIn = (cfg.markers || []).filter((m) => isFinite(m.lat) && isFinite(m.lng));
  const first = markersIn[0];
  const handlers = {};
  const emit = (ev, v) => (handlers[ev] || []).forEach((fn) => fn(v));
  let eng = null;
  let dead = false;
  let items = [];
  let shown = {}; // key -> engine marker handle
  let active = null;
  let index = null;
  let origin = null; // [lng, lat]
  let originMarker = null;

  const errors = [];
  const ready = engines[ENGINE_OF[provider.name]]().then((mod) => mod.create({
    container: canvas,
    provider: provider.name,
    key: provider.key || '',
    mapId: provider.mapId || '',
    tokenUrl: provider.tokenUrl || '',
    style: cfg.style,
    lang: cfg.lang || document.documentElement.lang || '',
    center: cfg.center || (first ? [+first.lng, +first.lat] : [-3.7038, 40.4168]),
    zoom: cfg.zoom != null ? +cfg.zoom : 13,
    scrollZoom: !!cfg.scrollZoom,
    onError: (msg) => { errors.push(msg); emit('error', msg); },
  })).then((e) => {
    if (dead) { e.destroy(); return e; }
    eng = e;
    e.onMove(() => { if (index) render(); });
    if (origin) drawOrigin();
    fit();
    render();
    return e;
  });
  ready.catch((e) => {
    el.classList.add('rumbo-failed');
    el.classList.remove('rumbo-map--live'); // the server-rendered address list shows instead
    if (window.console) window.console.warn('[Rumbo Maps]', e && e.message ? e.message : e);
  });
  const loaded = ready.then((e) => e.loaded);
  loaded.catch(() => {});

  function markerEl(m, i) {
    const b = document.createElement('button');
    b.type = 'button';
    const icon = iconSrc(m.icon || cfg.markerIcon);
    if (cfg.numbered) {
      b.className = 'rumbo-pin rumbo-pin--n';
      b.textContent = String(i + 1);
      b.setAttribute('aria-label', (i + 1) + '. ' + (m.title || ''));
    } else {
      b.className = 'rumbo-pin';
      if (icon) {
        const img = document.createElement('img');
        img.src = icon;
        img.alt = '';
        b.classList.add('rumbo-pin--img');
        b.appendChild(img);
      } else b.innerHTML = PIN_SVG;
      b.setAttribute('aria-label', m.title || '');
    }
    b.addEventListener('click', (e) => {
      e.stopPropagation();
      highlight(m.id);
      emit('select', m.id);
    });
    return b;
  }

  function makeMarker(m, i) {
    const icon = !cfg.numbered && iconSrc(m.icon || cfg.markerIcon);
    const popup = cfg.popups !== false && !cfg.numbered ? popupHtml(m, s) : null;
    return eng.addMarker(markerEl(m, i), [m.lng, m.lat], { anchor: cfg.numbered || icon ? 'center' : 'bottom', popup });
  }

  function clusterEl(c) {
    const n = c.properties.point_count;
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'rumbo-cluster' + (n >= 100 ? ' rumbo-cluster--l' : n >= 10 ? ' rumbo-cluster--m' : '');
    b.textContent = c.properties.point_count_abbreviated;
    b.setAttribute('aria-label', fill(s.cluster || '{n} locations, zoom in', { n }));
    b.addEventListener('click', (e) => {
      e.stopPropagation();
      const z = Math.min(index.getClusterExpansionZoom(c.properties.cluster_id), 18);
      eng.setView(c.geometry.coordinates, z, true);
    });
    return b;
  }

  function render() {
    if (!eng) return;
    const next = {};
    if (index) {
      const b = eng.bounds() || [-180, -85, 180, 85];
      const list = index.getClusters([Math.max(-180, b[0] - 1), Math.max(-90, b[1] - 1), Math.min(180, b[2] + 1), Math.min(90, b[3] + 1)], Math.round(eng.zoom()));
      list.forEach((c) => {
        const key = c.properties.cluster ? 'c' + c.properties.cluster_id : 'm' + c.properties.i;
        next[key] = shown[key] || (c.properties.cluster
          ? eng.addMarker(clusterEl(c), c.geometry.coordinates, { anchor: 'center' })
          : makeMarker(items[c.properties.i], c.properties.i));
      });
    } else {
      items.forEach((m, i) => { next['m' + i] = shown['m' + i] || makeMarker(m, i); });
    }
    Object.keys(shown).forEach((k) => { if (!next[k]) shown[k].remove(); });
    shown = next;
    if (active != null) highlight(active);
  }

  function fit() {
    if (!eng || !items.length) return;
    if (items.length === 1 || cfg.fitBounds === false) {
      eng.setView([items[0].lng, items[0].lat], cfg.zoom != null ? +cfg.zoom : 14, false);
      return;
    }
    const pts = items.map((m) => [m.lng, m.lat]);
    if (origin) pts.push(origin);
    eng.fit(pts, el.clientWidth < 500 ? 40 : 70, 15);
  }

  function setMarkers(list) {
    Object.keys(shown).forEach((k) => shown[k].remove());
    shown = {};
    active = null;
    items = (list || []).filter((m) => isFinite(m.lat) && isFinite(m.lng)).map((m, i) => Object.assign({ id: i }, m, { lat: +m.lat, lng: +m.lng }));
    const useCluster = cfg.cluster === true || (cfg.cluster !== false && !cfg.numbered && items.length > 50);
    index = null;
    if (useCluster) {
      index = new Supercluster({ radius: 60, maxZoom: 16 });
      index.load(items.map((m, i) => ({ type: 'Feature', properties: { i }, geometry: { type: 'Point', coordinates: [m.lng, m.lat] } })));
    }
    fit();
    render();
  }

  function keyOf(id) {
    const i = items.findIndex((m) => String(m.id) === String(id));
    return i < 0 ? null : 'm' + i;
  }

  function highlight(id) {
    active = id;
    const key = keyOf(id);
    Object.keys(shown).forEach((k) => {
      const on = k === key;
      const e = shown[k].el();
      e.classList.toggle('is-active', on);
      e.style.zIndex = on ? '3' : '';
    });
  }

  function focus(id) {
    const m = items.find((x) => String(x.id) === String(id));
    if (!m) return;
    highlight(id);
    if (eng) eng.setView([m.lng, m.lat], Math.max(eng.zoom(), 13), true);
  }

  function drawOrigin() {
    if (originMarker) originMarker.remove();
    originMarker = null;
    if (!eng || !origin) return;
    const d = document.createElement('span');
    d.className = 'rumbo-you';
    d.setAttribute('aria-hidden', 'true');
    originMarker = eng.addMarker(d, origin, { anchor: 'center' });
  }

  function setOrigin(o2) {
    origin = o2 && isFinite(o2.lat) && isFinite(o2.lng) ? [+o2.lng, +o2.lat] : null;
    drawOrigin();
  }

  if (cfg.origin) setOrigin(cfg.origin);
  setMarkers(markersIn);

  return {
    get map() { return eng ? eng.native : null; },
    get engine() { return eng; },
    provider: provider.name,
    ready,
    loaded,
    errors,
    setMarkers,
    highlight,
    focus,
    setOrigin,
    fit,
    on(ev, fn) { (handlers[ev] = handlers[ev] || []).push(fn); },
    destroy() { dead = true; if (eng) eng.destroy(); canvas.remove(); el.classList.remove('rumbo-map--live'); },
  };
}
