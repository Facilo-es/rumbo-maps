// Rumbo Maps store locator: progressive enhancement over the server-rendered Twig partials.
// Without JavaScript the GET form and the server-rendered list work on their own.
import { fill, esc, safeUrl, formatDistance, readConfig, inkFor } from './util.js';

export const DEFAULT_STRINGS = {
  placeholder: 'Town, postcode or address',
  search: 'Search',
  useLocation: 'Use my location',
  directions: 'Directions',
  details: 'Details',
  countMany: '{n} locations near {place}',
  countOne: '1 location near {place}',
  countManyYou: '{n} locations near you',
  countOneYou: '1 location near you',
  countManyAll: '{n} locations',
  countOneAll: '1 location',
  awayKm: '{distance} km away',
  awayMi: '{distance} mi away',
  km: 'km',
  mi: 'mi',
  none: 'Nothing within {within} {unit}. The nearest is {title}, {distance} away.',
  noneAtAll: 'Nothing within {within} {unit}.',
  locErr: "We couldn't get your location. Type a town or postcode instead.",
  findErr: "We couldn't find that place. Check the spelling or try a postcode.",
  failed: "Search isn't working right now. Please try again in a moment.",
  cluster: '{n} locations, zoom in',
};

const FILTER_KEY = /^filters\[/;

/** Distance HTML: "<b>1,2</b> km away" / "a <b>1,2</b> km". */
export function awayHtml(distance, unit, lang, s) {
  const tpl = unit === 'mi' ? s.awayMi : s.awayKm;
  return esc(tpl).replace('{distance}', '<b>' + esc(formatDistance(distance, lang)) + '</b>');
}

export function cardHtml(r, i, ctx) {
  const { s, unit, lang, heading } = ctx;
  const h = /^h[2-6]$/.test(heading) ? heading : 'h3';
  const fields = r.fields && typeof r.fields === 'object' ? Object.values(r.fields).filter((v) => v != null && v !== '' && typeof v !== 'object') : [];
  let out = '<li class="rumbo-card" tabindex="0" data-rumbo-id="' + esc(r.id) + '" data-lat="' + esc(r.lat) + '" data-lng="' + esc(r.lng) + '">' +
    '<span class="rumbo-card__n" aria-hidden="true">' + (i + 1) + '</span>' +
    '<' + h + ' class="rumbo-card__t">' + esc(r.title) + '</' + h + '>';
  if (r.distance != null && r.distance !== '') out += '<span class="rumbo-card__d">' + awayHtml(r.distance, unit, lang, s) + '</span>';
  if (r.address) out += '<p class="rumbo-card__a">' + esc(r.address) + '</p>';
  if (fields.length) out += '<p class="rumbo-card__meta">' + fields.map((v) => '<span>' + esc(v) + '</span>').join('') + '</p>';
  out += '<p class="rumbo-card__links">';
  const dir = r.directionsUrl || 'https://www.openstreetmap.org/directions?route=%3B' + r.lat + '%2C' + r.lng;
  out += '<a class="rumbo-card__dir" href="' + esc(safeUrl(dir)) + '" target="_blank" rel="noopener">' + esc(s.directions) + '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 17L17 7M9 7h8v8"/></svg></a>';
  if (r.url) out += '<a class="rumbo-card__url" href="' + esc(safeUrl(r.url)) + '">' + esc(s.details) + '</a>';
  return out + '</p></li>';
}

export function countText(n, origin, s) {
  if (origin && origin.label) return n === 1 ? fill(s.countOne, { place: origin.label }) : fill(s.countMany, { n, place: origin.label });
  if (origin && isFinite(origin.lat)) return n === 1 ? s.countOneYou : fill(s.countManyYou, { n });
  return n === 1 ? s.countOneAll : fill(s.countManyAll, { n });
}

/**
 * mountLocator(root, { loadMap(slot) -> Promise<mapModule>, fetch, win })
 * Returns { search(params), state, destroy }.
 */
export function mountLocator(root, ctx = {}) {
  const win = ctx.win || window;
  const doc = root.ownerDocument;
  const doFetch = ctx.fetch || ((...a) => win.fetch(...a));
  const cfg = readConfig(root);
  const s = Object.assign({}, DEFAULT_STRINGS, cfg.strings || {});
  const lang = cfg.lang || doc.documentElement.lang || 'en';
  const endpoint = root.getAttribute('data-rumbo-endpoint') || cfg.endpoint || '';
  const look = cfg.look || 'side';
  const $ = (r) => root.querySelector('[data-r="' + r + '"]');
  const form = $('form');
  const input = form && form.querySelector('input[name="q"]');
  const list = $('list');
  const count = $('count');
  const msg = $('msg');
  const me = $('me');
  const slot = $('map');

  const state = {
    unit: cfg.unit === 'mi' ? 'mi' : 'km',
    origin: cfg.origin || null,
    results: Array.isArray(cfg.results) ? cfg.results : [],
    coords: null,
    seq: 0,
  };
  let mapCtl = null;
  let mapPending = null;
  let activeId = null;

  root.classList.add('rumbo--js');
  // Colours from settings, set through CSSOM so a strict CSP (no inline styles) still allows them.
  const COLOR = /^#[0-9a-f]{3,8}$/i;
  if (COLOR.test(cfg.accentColor || '')) {
    root.style.setProperty('--rumbo-accent', cfg.accentColor);
    if (inkFor(cfg.accentColor)) root.style.setProperty('--rumbo-accent-ink', inkFor(cfg.accentColor));
  }
  if (COLOR.test(cfg.markerColor || '')) {
    root.style.setProperty('--rumbo-marker', cfg.markerColor);
    if (inkFor(cfg.markerColor)) root.style.setProperty('--rumbo-marker-ink', inkFor(cfg.markerColor));
  }
  if (me) me.hidden = !(win.navigator && win.navigator.geolocation);

  function showMsg(text, kind) {
    if (!msg) return;
    msg.textContent = text || '';
    msg.hidden = !text;
    msg.className = 'rumbo-msg' + (kind ? ' rumbo-msg--' + kind : '');
  }

  function markers() {
    return state.results.map((r) => ({ id: r.id, lat: r.lat, lng: r.lng, title: r.title, address: r.address, url: r.url, directionsUrl: r.directionsUrl }));
  }

  function syncMap() {
    if (!mapCtl) return;
    mapCtl.setOrigin(state.origin && isFinite(state.origin.lat) ? state.origin : null);
    mapCtl.setMarkers(markers());
  }

  function ensureMap() {
    if (look === 'list' || !slot || !ctx.loadMap || mapPending) return mapPending;
    mapPending = ctx.loadMap(slot).then((mod) => {
      mapCtl = mod.mountMap(slot, {
        style: cfg.mapStyle || cfg.style,
        numbered: true,
        popups: false,
        cluster: false,
        scrollZoom: false,
        strings: s,
        center: state.origin && isFinite(state.origin.lng) ? [state.origin.lng, state.origin.lat] : cfg.center,
        zoom: cfg.zoom,
        provider: cfg.provider,
        lang: cfg.lang,
        markers: [],
      });
      if (mapCtl.ready) mapCtl.ready.catch(() => root.classList.add('rumbo--nomap'));
      mapCtl.on('select', (id) => activate(id, { fromMap: true }));
      syncMap();
      if (activeId != null) mapCtl.highlight(activeId);
      return mapCtl;
    }).catch((e) => {
      slot.classList.add('rumbo-failed');
      // No WebGL or a blocked map library: fall back to the full-width list, like no-JS visitors get.
      root.classList.add('rumbo--nomap');
      if (win.console) win.console.warn('[Rumbo Maps]', e);
    });
    return mapPending;
  }

  function cards() {
    return list ? Array.from(list.querySelectorAll('.rumbo-card')) : [];
  }

  function activate(id, opt = {}) {
    activeId = id;
    cards().forEach((c) => {
      const on = c.getAttribute('data-rumbo-id') === String(id);
      c.classList.toggle('is-active', on);
      if (on && opt.fromMap) {
        c.focus({ preventScroll: true });
        if (c.scrollIntoView) c.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    });
    if (mapCtl) {
      if (opt.pan) mapCtl.focus(id);
      else mapCtl.highlight(id);
    }
  }

  function render(data) {
    const unit = data.unit === 'mi' ? 'mi' : state.unit;
    state.unit = unit;
    state.origin = data.origin || state.origin;
    let results = Array.isArray(data.results) ? data.results : [];
    const within = data.within != null ? data.within : currentParams().get('radius');
    let note = '';
    if (!results.length) {
      const near = data.nearest;
      if (near) {
        note = fill(s.none, { within: within != null ? formatDistance(within, lang) : '', unit: s[unit], title: near.title, distance: formatDistance(near.distance, lang) + ' ' + s[unit] });
        results = [near];
      } else {
        note = fill(s.noneAtAll, { within: within != null ? formatDistance(within, lang) : '', unit: s[unit] });
      }
    }
    state.results = results;
    const c = { s, unit, lang, heading: cfg.cardHeading };
    if (list) list.innerHTML = results.map((r, i) => cardHtml(r, i, c)).join('');
    if (data.message) note = data.message;
    showMsg(note, note && !(data.results && data.results.length) ? 'empty' : '');
    if (count) count.textContent = data.results && data.results.length ? countText(data.total != null ? data.total : data.results.length, state.origin, s) : '';
    activeId = null;
    syncMap();
  }

  function setBusy(on) {
    root.setAttribute('aria-busy', on ? 'true' : 'false');
    if (!list) return;
    if (on) {
      list.setAttribute('aria-busy', 'true');
      list.classList.add('is-loading');
      if (!list.querySelector('.rumbo-card')) {
        list.innerHTML = '<li class="rumbo-skel" aria-hidden="true"></li>'.repeat(3);
      }
    } else {
      list.removeAttribute('aria-busy');
      list.classList.remove('is-loading');
      list.querySelectorAll('.rumbo-skel').forEach((n) => n.remove());
    }
  }

  function currentParams() {
    const p = new URLSearchParams();
    if (form) {
      new FormData(form).forEach((v, k) => {
        if (typeof v !== 'string') return;
        if (k === 'q') v = v.trim();
        if (v !== '' || k === 'q') p.append(k, v);
      });
    }
    if (state.coords) {
      p.set('lat', state.coords.lat.toFixed(4));
      p.set('lng', state.coords.lng.toFixed(4));
    }
    if (!p.has('unit')) p.set('unit', state.unit);
    if (!p.get('q')) p.delete('q');
    return p;
  }

  function syncUrl(p) {
    if (!win.history || !win.history.replaceState) return;
    const u = new URL(win.location.href);
    Array.from(u.searchParams.keys()).forEach((k) => {
      if (k === 'l' || k === 'q' || k === 'lat' || k === 'lng' || k === 'radius' || k === 'unit' || FILTER_KEY.test(k)) u.searchParams.delete(k);
    });
    p.forEach((v, k) => {
      if (k === 'lat' || k === 'lng') v = Number(v).toFixed(3); // coarse in the address bar
      u.searchParams.append(k, v);
    });
    win.history.replaceState(win.history.state, '', u.pathname + u.search + u.hash);
  }

  async function search() {
    const p = currentParams();
    const q = p.get('q') || '';
    if (!state.coords && q.length < 2 && !Array.from(p.keys()).some((k) => FILTER_KEY.test(k)) && !state.searched) {
      if (input) input.focus();
      return;
    }
    if (q && q.length < 2 && !state.coords) {
      showMsg(s.findErr, 'error');
      if (input) input.focus();
      return;
    }
    state.searched = true;
    syncUrl(p);
    const req = new URLSearchParams(p);
    if (cfg.limit) req.set('limit', cfg.limit);
    if (cfg.site) req.set('site', cfg.site);
    const url = new URL(endpoint || win.location.href, win.location.href);
    req.forEach((v, k) => url.searchParams.append(k, v));
    const my = ++state.seq;
    setBusy(true);
    let data;
    try {
      const res = await doFetch(url.href, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      data = await res.json().catch(() => null);
      if (!data) data = { ok: false };
    } catch (e) {
      data = { ok: false, network: true };
    }
    if (my !== state.seq) return;
    setBusy(false);
    if (!data.ok) {
      // Server text wins; "notFound" without text uses our copy; anything else (network, 500, bad JSON) is "failed".
      const e0 = Array.isArray(data.errors) ? data.errors[0] : null;
      showMsg(typeof e0 === 'string' && e0 ? e0 : data.code === 'notFound' ? s.findErr : s.failed, 'error');
      if (count) count.textContent = '';
      return;
    }
    render(data);
  }

  function locate() {
    const geo = win.navigator && win.navigator.geolocation;
    const failed = () => {
      if (me) me.removeAttribute('aria-busy');
      showMsg(s.locErr, 'error');
      if (input) input.focus();
    };
    if (!geo) return failed();
    if (me) me.setAttribute('aria-busy', 'true');
    geo.getCurrentPosition((pos) => {
      if (me) me.removeAttribute('aria-busy');
      state.coords = { lat: pos.coords.latitude, lng: pos.coords.longitude };
      state.origin = { lat: state.coords.lat, lng: state.coords.lng };
      if (input) input.value = '';
      search();
    }, failed, { timeout: 10000, maximumAge: 300000 });
  }

  // Events
  const off = [];
  const on = (el, ev, fn, o) => { if (!el) return; el.addEventListener(ev, fn, o); off.push(() => el.removeEventListener(ev, fn, o)); };
  on(form, 'submit', (e) => {
    e.preventDefault();
    state.coords = null;
    state.origin = null;
    search();
  });
  on(input, 'input', () => { state.coords = null; });
  on(form, 'change', (e) => {
    const t = e.target;
    if (t && (t.name === 'radius' || FILTER_KEY.test(t.name || ''))) {
      const chip = t.closest('.rumbo-chip');
      if (chip) chip.classList.toggle('is-on', !!t.checked);
      state.searched = state.searched || !!(input && input.value.trim()) || !!state.coords || state.results.length > 0;
      search();
    }
  });
  on(me, 'click', locate);
  on(list, 'mouseover', (e) => {
    const c = e.target.closest && e.target.closest('.rumbo-card');
    if (c && mapCtl) mapCtl.highlight(c.getAttribute('data-rumbo-id'));
  });
  on(list, 'mouseleave', () => { if (mapCtl) mapCtl.highlight(activeId); });
  on(list, 'focusin', (e) => {
    const c = e.target.closest && e.target.closest('.rumbo-card');
    if (c && c.getAttribute('data-rumbo-id') !== String(activeId)) activate(c.getAttribute('data-rumbo-id'));
  });
  on(list, 'click', (e) => {
    if (e.target.closest && e.target.closest('a')) return;
    const c = e.target.closest && e.target.closest('.rumbo-card');
    if (c) activate(c.getAttribute('data-rumbo-id'), { pan: true });
  });
  on(list, 'keydown', (e) => {
    const c = e.target.classList && e.target.classList.contains('rumbo-card') ? e.target : null;
    if (c && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      activate(c.getAttribute('data-rumbo-id'), { pan: true });
    }
  });

  // Mark server-rendered chips that are already checked (for browsers without :has()).
  if (form) form.querySelectorAll('.rumbo-chip input').forEach((i) => i.closest('.rumbo-chip').classList.toggle('is-on', i.checked));
  state.searched = !!(input && input.value.trim());
  ensureMap();

  return {
    state,
    search,
    locate,
    activate,
    get map() { return mapCtl; },
    destroy() { off.splice(0).forEach((f) => f()); if (mapCtl) mapCtl.destroy(); },
  };
}
