// Rumbo Maps Location field editor (Craft control panel). Map-library free so it can be tested;
// the MapLibre adapter is passed in by field-app.js.

export const FIELD_STRINGS = {
  searchPlaceholder: 'Search for an address or place',
  updateFromPin: 'Update address from pin',
  clear: 'Clear',
  street: 'Street and number',
  city: 'City',
  postcode: 'Postcode',
  county: 'County',
  state: 'State or region',
  country: 'Country',
  latitude: 'Latitude',
  longitude: 'Longitude',
  notFound: "We couldn't find that address. Try adding a town or postcode, or place the pin by hand.",
  providerError: '{provider} said: {message}. Check your key on the Test my keys page.',
};

const PARTS = ['number', 'street', 'city', 'postcode', 'county', 'state', 'country', 'countryCode'];
let uid = 0;

function el(doc, tag, cls, attrs) {
  const e = doc.createElement(tag);
  if (cls) e.className = cls;
  if (attrs) Object.keys(attrs).forEach((k) => e.setAttribute(k, attrs[k]));
  return e;
}

/**
 * mountField(root, { createMap(container, opts) -> adapter, fetch, win })
 * adapter: { setPin([lng,lat]), removePin(), onDragEnd(fn([lng,lat])), onClick(fn([lng,lat])),
 *            onZoom(fn(z)), flyTo([lng,lat], zoom), getZoom() }
 */
export function mountField(root, ctx = {}) {
  if (root.__rumboField) return root.__rumboField;
  const doc = root.ownerDocument;
  const win = ctx.win || doc.defaultView;
  const doFetch = ctx.fetch || ((...a) => win.fetch(...a));
  const blob = root.querySelector('script[type="application/json"][data-rumbo-field-config]');
  let cfg = {};
  try { cfg = JSON.parse((blob && blob.textContent) || '{}') || {}; } catch (e) { cfg = {}; }
  const s = Object.assign({}, FIELD_STRINGS, cfg.strings || {});
  const name = cfg.name || 'fields[location]';
  const lang = (doc.documentElement.lang || 'en').slice(0, 2);
  const id = 'rumbo-f' + (++uid);

  function input(key) {
    const n = name + '[' + key + ']';
    let i = Array.from(root.querySelectorAll('input,select,textarea')).find((x) => x.name === n);
    if (!i) {
      i = el(doc, 'input', '', { type: 'hidden', name: n });
      root.appendChild(i);
    }
    return i;
  }
  const inLat = input('lat');
  const inLng = input('lng');
  const inZoom = input('zoom');
  const inSource = input('source');
  const inAddress = input('address');
  const inParts = {};
  PARTS.forEach((p) => { inParts[p] = input('parts][' + p); });

  let internal = false;
  function set(i, v) {
    const val = v == null ? '' : String(v);
    if (i.value === val) return;
    i.value = val;
    internal = true; // Craft's draft autosave listens for these; our own handlers ignore them
    i.dispatchEvent(new win.Event('input', { bubbles: true }));
    i.dispatchEvent(new win.Event('change', { bubbles: true }));
    internal = false;
  }

  // Initial value from config when the inputs were rendered empty.
  const v0 = cfg.value || {};
  if (inLat.value === '' && v0.lat != null) {
    inLat.value = v0.lat; inLng.value = v0.lng; inZoom.value = v0.zoom == null ? '' : v0.zoom;
    inSource.value = v0.source || '';
    if (inAddress.value === '') inAddress.value = v0.address || '';
    PARTS.forEach((p) => { if (inParts[p].value === '' && v0.parts && v0.parts[p] != null) inParts[p].value = v0.parts[p]; });
  }

  // UI: search with suggestions, error line, map, buttons. Server-rendered inputs stay as they are.
  const top = el(doc, 'div', 'rumbo-f-top');
  const searchWrap = el(doc, 'div', 'rumbo-f-search');
  const search = el(doc, 'input', 'text fullwidth rumbo-f-q', {
    type: 'search', role: 'combobox', 'aria-autocomplete': 'list', 'aria-expanded': 'false',
    'aria-controls': id + '-list', placeholder: s.searchPlaceholder, 'aria-label': s.searchPlaceholder, autocomplete: 'off',
  });
  search.value = inAddress.value; // the saved address, as after picking a suggestion
  const sugg = el(doc, 'ul', 'rumbo-f-sugg', { id: id + '-list', role: 'listbox' });
  sugg.hidden = true;
  searchWrap.append(search, sugg);
  const err = el(doc, 'p', 'rumbo-f-error error', { role: 'alert' });
  err.hidden = true;
  const mapBox = el(doc, 'div', 'rumbo-f-map');
  if (cfg.height) mapBox.style.height = (/^\d+$/.test(String(cfg.height)) ? cfg.height + 'px' : cfg.height);
  const actions = el(doc, 'div', 'rumbo-f-actions');
  const btnRev = el(doc, 'button', 'btn small', { type: 'button', 'data-f': 'reverse' });
  btnRev.textContent = s.updateFromPin;
  const btnClear = el(doc, 'button', 'btn small', { type: 'button', 'data-f': 'clear' });
  btnClear.textContent = s.clear;
  actions.append(btnRev, btnClear);
  top.append(searchWrap, err, mapBox, actions);
  if (blob && blob.nextSibling) root.insertBefore(top, blob.nextSibling);
  else root.insertBefore(top, root.firstChild);

  if (cfg.showLatLng) {
    const row = el(doc, 'div', 'rumbo-f-coords');
    [[inLat, s.latitude], [inLng, s.longitude]].forEach(([i, label]) => {
      const f = el(doc, 'div', 'rumbo-f-coord');
      const l = el(doc, 'label', '', { for: id + '-' + i.name.replace(/\W+/g, '') });
      l.textContent = label;
      i.id = id + '-' + i.name.replace(/\W+/g, '');
      i.type = 'text';
      i.classList.add('text');
      i.setAttribute('inputmode', 'decimal');
      f.append(l, i);
      row.appendChild(f);
    });
    root.insertBefore(row, inZoom); // lat and lng keep their place in the form, so Craft sees no change
  }
  root.classList.add('rumbo-field--js');

  // State
  let adapter = null;
  let items = [];
  let cursor = -1;
  let timer = null;
  let seq = 0;

  function coords() {
    const lat = parseFloat(inLat.value);
    const lng = parseFloat(inLng.value);
    return isFinite(lat) && isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 ? [lng, lat] : null;
  }

  function showError(t, keyLink) {
    err.textContent = t || '';
    err.hidden = !t;
    if (t && keyLink && cfg.keyTestUrl) {
      const a = doc.createElement('a');
      a.href = cfg.keyTestUrl;
      a.target = '_blank';
      a.className = 'rumbo-field__keytest';
      a.textContent = cfg.keyTestLabel || 'Test my keys';
      err.appendChild(doc.createTextNode(' '));
      err.appendChild(a);
    }
  }

  function errorText(data) {
    if (data && data.error) return data.error;
    return s.providerError.replace('{provider}', cfg.provider || 'The address service').replace('{message}', 'no answer');
  }

  function fillAddress(r) {
    set(inAddress, r.address || '');
    PARTS.forEach((p) => set(inParts[p], r.parts && r.parts[p] != null ? r.parts[p] : ''));
  }

  function setPin(ll, fly) {
    if (!adapter) return;
    if (!ll) return adapter.removePin();
    adapter.setPin(ll);
    if (fly) adapter.flyTo(ll, Math.max(adapter.getZoom() || 0, 15));
  }

  function setCoords(ll, source) {
    set(inLat, +ll[1].toFixed(7));
    set(inLng, +ll[0].toFixed(7));
    if (source) set(inSource, source);
    if (adapter && inZoom.value === '') set(inZoom, Math.round(adapter.getZoom()));
  }

  function closeList() {
    sugg.hidden = true;
    sugg.innerHTML = '';
    items = [];
    cursor = -1;
    search.setAttribute('aria-expanded', 'false');
    search.removeAttribute('aria-activedescendant');
  }

  function pick(r) {
    closeList();
    showError('');
    fillAddress(r);
    const ll = [+r.lng, +r.lat];
    set(inZoom, 15);
    setCoords(ll, r.source || cfg.provider || 'geocoder');
    setPin(ll, true);
    search.value = r.address || '';
  }

  function renderList() {
    sugg.innerHTML = '';
    items.forEach((r, i) => {
      const li = el(doc, 'li', 'rumbo-f-opt' + (i === cursor ? ' is-on' : ''), { role: 'option', id: id + '-o' + i, 'aria-selected': i === cursor ? 'true' : 'false' });
      li.textContent = r.address || '';
      li.addEventListener('mousedown', (e) => { e.preventDefault(); pick(r); });
      sugg.appendChild(li);
    });
    sugg.hidden = !items.length;
    search.setAttribute('aria-expanded', items.length ? 'true' : 'false');
    if (cursor > -1) search.setAttribute('aria-activedescendant', id + '-o' + cursor);
    else search.removeAttribute('aria-activedescendant');
  }

  async function get(url, params) {
    const u = new URL(url, win.location.href);
    Object.keys(params).forEach((k) => { if (params[k] != null && params[k] !== '') u.searchParams.set(k, params[k]); });
    try {
      const res = await doFetch(u.href, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      return (await res.json().catch(() => null)) || { ok: false };
    } catch (e) {
      return { ok: false };
    }
  }

  async function suggest(q) {
    if (!cfg.suggestUrl) return;
    const my = ++seq;
    const data = await get(cfg.suggestUrl, {
      q, lang,
      countries: (cfg.countries || []).join(','),
      bias: Array.isArray(cfg.bias) ? cfg.bias.join(',') : '',
    });
    if (my !== seq) return;
    if (!data.ok) { closeList(); return showError(errorText(data), true); }
    items = (data.results || []).filter((r) => isFinite(r.lat) && isFinite(r.lng));
    cursor = -1;
    showError(items.length ? '' : s.notFound);
    renderList();
  }

  async function reverse() {
    const ll = coords();
    if (!ll || !cfg.reverseUrl) return;
    btnRev.classList.add('loading');
    const data = await get(cfg.reverseUrl, { lat: ll[1], lng: ll[0], lang });
    btnRev.classList.remove('loading');
    if (!data.ok) return showError(errorText(data), true);
    const r = (data.results || [])[0];
    if (!r) return showError(s.notFound);
    showError('');
    fillAddress(r); // the pin and coordinates stay where the editor put them
  }

  function clear() {
    [inLat, inLng, inZoom, inSource, inAddress].concat(PARTS.map((p) => inParts[p])).forEach((i) => set(i, ''));
    search.value = '';
    closeList();
    showError('');
    setPin(null);
  }

  // Events
  search.addEventListener('input', () => {
    clearTimeout(timer);
    const q = search.value.trim();
    if (q.length < 3) { seq++; closeList(); return; }
    timer = setTimeout(() => suggest(q), 350);
  });
  search.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!items.length) return;
      e.preventDefault();
      cursor = (cursor + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      renderList();
    } else if (e.key === 'Enter') {
      e.preventDefault(); // never submit the entry form from the search box
      if (items.length) pick(items[Math.max(cursor, 0)]);
    } else if (e.key === 'Escape') {
      if (!sugg.hidden) { e.preventDefault(); closeList(); }
    }
  });
  search.addEventListener('blur', () => setTimeout(closeList, 150));
  btnRev.addEventListener('click', reverse);
  btnClear.addEventListener('click', clear);
  [inLat, inLng].forEach((i) => i.addEventListener('change', () => {
    if (internal) return;
    const ll = coords();
    if (ll) { set(inSource, 'manual'); setPin(ll, true); }
  }));

  function onDrag(ll) {
    setCoords(ll, 'manual'); // coordinates only; the address stays as typed
  }

  function attach(a) {
    adapter = a;
    const ll = coords();
    if (ll) adapter.setPin(ll);
    adapter.onDragEnd(onDrag);
    adapter.onClick((p) => { if (!coords()) { setCoords(p, 'manual'); setPin(p); } });
    adapter.onZoom((z) => { if (coords()) set(inZoom, Math.round(z)); });
  }

  if (ctx.createMap) {
    const start = () => {
      const ll = coords();
      attach(ctx.createMap(mapBox, {
        style: cfg.style || 'https://tiles.openfreemap.org/styles/positron',
        center: ll || cfg.defaultCenter || [-3.7038, 40.4168],
        zoom: ll ? (parseFloat(inZoom.value) || 15) : (cfg.defaultZoom || 12),
      }));
    };
    if (ctx.lazy && win.IntersectionObserver) {
      const io = new win.IntersectionObserver((en) => { if (en.some((x) => x.isIntersecting)) { io.disconnect(); start(); } }, { rootMargin: '200px' });
      io.observe(mapBox);
    } else start();
  }

  const api = { pick, reverse, clear, suggest, onDrag, attach, inputs: { lat: inLat, lng: inLng, zoom: inZoom, source: inSource, address: inAddress, parts: inParts } };
  root.__rumboField = api;
  return api;
}

/** Init every field now and every field added later (Matrix, slideouts). */
export function watchFields(doc, ctx) {
  const run = (root) => {
    if (root.matches && root.matches('[data-rumbo-field]')) mountField(root, ctx);
    if (root.querySelectorAll) root.querySelectorAll('[data-rumbo-field]').forEach((f) => mountField(f, ctx));
  };
  run(doc);
  const mo = new doc.defaultView.MutationObserver((list) => list.forEach((m) => m.addedNodes.forEach((n) => { if (n.nodeType === 1) run(n); })));
  mo.observe(doc.body, { childList: true, subtree: true });
  return mo;
}
