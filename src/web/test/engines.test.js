// Provider adapters, each against a fake of its SDK's globals. No provider is ever contacted.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { flush } from './helpers.js';

function fakeGl() {
  const maps = [];
  class Map {
    constructor(o) { this.o = o; this.h = {}; this.controls = []; this.layout = { place: ['get', 'name'], housenum: ['get', 'housenumber'] }; maps.push(this); }
    on(ev, fn) { (this.h[ev] = this.h[ev] || []).push(fn); }
    fire(ev, v) { (this.h[ev] || []).forEach((f) => f(v)); }
    addControl(c) { this.controls.push(c); }
    getStyle() { return { layers: [{ id: 'place', type: 'symbol' }, { id: 'housenum', type: 'symbol' }, { id: 'water', type: 'fill' }] }; }
    getLayoutProperty(id, p) { return this.layout[id]; }
    setLayoutProperty(id, p, v) { this.layout[id] = v; }
    getZoom() { return 9; }
    getBounds() { return { getWest: () => -4, getSouth: () => 40, getEast: () => -3, getNorth: () => 41 }; }
    fitBounds(b, o) { this.fitted = { b, o }; }
    jumpTo(v) { this.view = v; }
    easeTo(v) { this.view = v; }
    remove() { this.removed = true; }
  }
  class Marker {
    constructor(o) { this.o = o; }
    setLngLat(ll) { this.ll = ll; return this; }
    setPopup(p) { this.popup = p; return this; }
    addTo() { return this; }
    getElement() { return this.o.element; }
    getLngLat() { return { lng: this.ll[0], lat: this.ll[1] }; }
    remove() { this.gone = true; }
  }
  class Popup { constructor(o) { this.o = o; } setHTML(h) { this.html = h; return this; } }
  class AttributionControl { constructor(o) { this.o = o; } }
  class NavigationControl { constructor(o) { this.o = o; } }
  class LngLatBounds { constructor() { this.pts = []; } extend(p) { this.pts.push(p); } }
  return { maps, Map, Marker, Popup, AttributionControl, NavigationControl, LngLatBounds };
}

const gl = fakeGl();
vi.mock('../src/ml.js', () => ({ default: gl }));

const base = () => ({ container: document.createElement('div'), center: [-3.7, 40.4], zoom: 12, style: 'positron', lang: 'es-ES' });

describe('MapLibre engine (OpenFreeMap, MapTiler)', () => {
  it('uses the OpenFreeMap style, keeps attribution expanded and labels the map in the site language', async () => {
    const { create } = await import('../src/engines/maplibre.js');
    const e = await create(Object.assign(base(), { provider: 'openfreemap' }));
    const m = e.native;
    expect(m.o.style).toBe('https://tiles.openfreemap.org/styles/positron');
    expect(m.o.attributionControl).toBe(false);
    expect(m.controls.find((c) => c instanceof gl.AttributionControl).o.compact).toBe(false);
    m.fire('load');
    await expect(e.loaded).resolves.toBeUndefined();
    expect(m.layout.place).toEqual(['coalesce', ['get', 'name:es'], ['get', 'name:latin'], ['get', 'name']]);
    expect(m.layout.housenum).toEqual(['get', 'housenumber']);
  });

  it('builds the MapTiler style with the browser key', async () => {
    const { create, maptilerStyle } = await import('../src/engines/maplibre.js');
    const e = await create(Object.assign(base(), { provider: 'maptiler', key: 'pk&1', style: 'dark' }));
    expect(e.native.o.style).toBe('https://api.maptiler.com/maps/dataviz-dark/style.json?key=pk%261');
    expect(maptilerStyle('https://example.com/s.json', 'k')).toBe('https://example.com/s.json');
  });

  it('reports a refused key with its HTTP status', async () => {
    const { create } = await import('../src/engines/maplibre.js');
    const onError = vi.fn();
    const e = await create(Object.assign(base(), { provider: 'maptiler', onError }));
    e.native.fire('error', { error: { message: 'Forbidden', status: 403 } });
    await expect(e.loaded).rejects.toThrow('Forbidden (HTTP 403)');
    expect(onError).toHaveBeenCalledWith('Forbidden', 403);
  });

  it('adds markers with popups, fits and reads bounds in [w,s,e,n]', async () => {
    const { create } = await import('../src/engines/maplibre.js');
    const e = await create(base());
    const el = document.createElement('button');
    const mk = e.addMarker(el, [-3, 40], { anchor: 'center', popup: '<b>x</b>' });
    expect(mk.el()).toBe(el);
    expect(mk.lngLat()).toEqual([-3, 40]);
    e.fit([[-3, 40], [-4, 41]], 50, 15);
    expect(e.native.fitted.o).toMatchObject({ padding: 50, maxZoom: 15 });
    expect(e.bounds()).toEqual([-4, 40, -3, 41]);
  });
});

describe('Mapbox engine', () => {
  afterEach(() => { delete window.mapboxgl; });
  it('sets the access token, maps the style and passes the language to Mapbox', async () => {
    window.mapboxgl = fakeGl();
    const { create } = await import('../src/engines/mapbox.js');
    const e = await create(Object.assign(base(), { provider: 'mapbox', key: 'pk.abc', style: 'liberty' }));
    expect(window.mapboxgl.accessToken).toBe('pk.abc');
    expect(e.native.o.style).toBe('mapbox://styles/mapbox/streets-v12');
    expect(e.native.o.language).toBe('es');
    expect(document.querySelector('link[href*="api.mapbox.com/mapbox-gl-js/"]')).not.toBeNull();
  });

  it('loads the SDK from Mapbox only when it is missing', async () => {
    const { create } = await import('../src/engines/mapbox.js');
    const p = create(Object.assign(base(), { key: 'pk' }));
    const s = document.querySelector('script[src*="api.mapbox.com"]');
    expect(s.src).toMatch(/mapbox-gl\.js$/);
    window.mapboxgl = fakeGl();
    s.onload();
    await expect(p).resolves.toHaveProperty('native');
  });
});

function fakeGoogle() {
  const listeners = {};
  class Map {
    constructor(el, o) { this.el = el; this.o = o; this.zoomV = o.zoom; }
    setZoom(z) { this.zoomV = z; }
    getZoom() { return this.zoomV; }
    setCenter(c) { this.c = c; }
    panTo(c) { this.c = c; }
    fitBounds(b, p) { this.fitted = { b, p }; this.zoomV = 20; }
    getBounds() { return { getNorthEast: () => ({ lat: () => 41, lng: () => -3 }), getSouthWest: () => ({ lat: () => 40, lng: () => -4 }) }; }
    addListener(ev, fn) { (listeners[ev] = listeners[ev] || []).push(fn); }
  }
  const event = {
    addListenerOnce: (m, ev, fn) => { (listeners[ev] = listeners[ev] || []).push(fn); },
    clearInstanceListeners: vi.fn(),
  };
  class AdvancedMarkerElement { constructor(o) { Object.assign(this, o); } }
  class InfoWindow { setContent(c) { this.c = c; } open(o) { this.opened = o; } close() {} }
  class LatLngBounds { constructor() { this.pts = []; } extend(p) { this.pts.push(p); } }
  const fire = (ev) => (listeners[ev] || []).forEach((f) => f());
  return { maps: { Map, event, marker: { AdvancedMarkerElement }, InfoWindow, LatLngBounds, ColorScheme: { DARK: 'DARK' } }, fire };
}

describe('Google engine', () => {
  afterEach(() => { delete window.google; delete window.gm_authFailure; });

  it('builds the SDK URL with the browser key, language and region', async () => {
    const { sdkUrl } = await import('../src/engines/google.js');
    const u = new URL(sdkUrl({ key: 'AIza', lang: 'es-ES' }));
    expect(u.origin).toBe('https://maps.googleapis.com');
    expect(Object.fromEntries(u.searchParams)).toMatchObject({ key: 'AIza', libraries: 'marker', language: 'es', region: 'ES', loading: 'async' });
  });

  it('uses the map ID, converts zoom, anchors markers and opens info windows', async () => {
    const g = fakeGoogle();
    window.google = g;
    const { create } = await import('../src/engines/google.js');
    const e = await create(Object.assign(base(), { provider: 'google', key: 'k', mapId: 'abc123', style: 'dark' }));
    const m = e.native;
    expect(m.o).toMatchObject({ mapId: 'abc123', zoom: 13, gestureHandling: 'cooperative', colorScheme: 'DARK' });
    expect(e.zoom()).toBe(12);
    const el = document.createElement('button');
    e.addMarker(el, [-3, 40], { anchor: 'center', popup: '<b>Hi</b>' });
    el.click();
    expect(el.parentNode.className).toBe('rumbo-at rumbo-at--c');
    e.fit([[-3, 40], [-4, 41]], 40, 15);
    g.fire('idle');
    expect(m.getZoom()).toBe(16);
    expect(e.bounds()).toEqual([-4, 40, -3, 41]);
    g.fire('tilesloaded');
    await expect(e.loaded).resolves.toBeUndefined();
  });

  it('turns gm_authFailure into the provider error with the API to enable', async () => {
    window.google = fakeGoogle();
    const onError = vi.fn();
    const { create } = await import('../src/engines/google.js');
    const e = await create(Object.assign(base(), { key: 'bad', onError }));
    window.gm_authFailure();
    await expect(e.loaded).rejects.toThrow(/Maps JavaScript API/);
    expect(onError).toHaveBeenCalled();
  });
});

function fakeHere() {
  const h = {};
  const on = (o) => { o.h = {}; o.addEventListener = (ev, fn) => { (o.h[ev] = o.h[ev] || []).push(fn); }; o.fire = (ev, v) => (o.h[ev] || []).forEach((f) => f(v)); return o; };
  const provider = on({});
  const layer = { getProvider: () => provider };
  const H = {
    provider,
    service: { Platform: class { constructor(o) { h.platform = o; } createDefaultLayers(o) { h.layers = o; return { vector: { normal: { map: layer } } }; } } },
    Map: class {
      constructor(el, l, o) { on(this); this.o = o; this.zoomV = o.zoom; this.objs = []; h.map = this; }
      getViewPort() { return { resize() {}, setPadding() {} }; }
      getViewModel() { const self = this; return { setLookAtData(d) { self.look = d; if (d.zoom) self.zoomV = d.zoom; }, getLookAtData: () => ({ bounds: { getBoundingBox: () => ({ getLeft: () => -4, getBottom: () => 40, getRight: () => -3, getTop: () => 41 }) } }) }; }
      getZoom() { return this.zoomV; }
      setZoom(z) { this.zoomV = z; }
      addObject(o) { this.objs.push(o); }
      removeObject(o) { this.objs = this.objs.filter((x) => x !== o); }
      dispose() { this.disposed = true; }
    },
    mapevents: { Behavior: Object.assign(class { disable(f) { h.disabled = f; } }, { Feature: { WHEEL_ZOOM: 'wheel' } }), MapEvents: class {} },
    ui: { UI: { createDefault: (m, l, loc) => { h.locale = loc; return { addBubble: vi.fn(), removeBubble: vi.fn() }; } }, InfoBubble: class { constructor(p, o) { this.o = o; } } },
    map: { DomIcon: class { constructor(el, o) { this.el = el; this.o = o; } }, DomMarker: class { constructor(p, o) { this.p = p; this.o = o; } } },
    geo: { Rect: class { constructor(n, w, s, e) { this.r = [n, w, s, e]; } } },
  };
  return { H, h };
}

describe('HERE engine', () => {
  afterEach(() => { delete window.H; });
  it('uses the API key only, the site language and no wheel zoom by default', async () => {
    const { H, h } = fakeHere();
    window.H = H;
    const { create } = await import('../src/engines/here.js');
    const e = await create(Object.assign(base(), { provider: 'here', key: 'hk' }));
    expect(h.platform).toEqual({ apikey: 'hk' });
    expect(h.layers).toEqual({ lg: 'es' });
    expect(h.locale).toBe('es-ES');
    expect(h.disabled).toBe('wheel');
    expect(e.zoom()).toBe(12);
    expect(e.bounds()).toEqual([-4, 40, -3, 41]);
    e.fit([[-3, 40], [-4, 41]], 40, 15);
    expect(h.map.look.bounds.r).toEqual([41, -4, 40, -3]);
  });

  it('forwards clicks from the DOM icon clone and mirrors the highlight', async () => {
    const { H, h } = fakeHere();
    window.H = H;
    const { create } = await import('../src/engines/here.js');
    const e = await create(base());
    const el = document.createElement('button');
    el.className = 'rumbo-pin';
    const clicked = vi.fn();
    el.addEventListener('click', clicked);
    e.addMarker(el, [-3, 40], { popup: '<b>x</b>' });
    const icon = h.map.objs[0].o.icon;
    expect(icon.el.className).toBe('rumbo-here rumbo-here--b');
    const clone = icon.el.cloneNode(true);
    icon.o.onAttach(clone);
    clone.firstChild.click();
    expect(clicked).toHaveBeenCalled();
    el.classList.add('is-active');
    await flush(2);
    expect(clone.firstChild.className).toContain('is-active');
  });

  it('reports refused tiles with the API to enable', async () => {
    const { H } = fakeHere();
    window.H = H;
    const onError = vi.fn();
    const { create } = await import('../src/engines/here.js');
    const e = await create(Object.assign(base(), { onError }));
    H.provider.fire('error', { message: '401 Unauthorized' });
    await expect(e.loaded).rejects.toThrow(/Maps API for JavaScript/);
    expect(onError).toHaveBeenCalled();
  });
});

function fakeMapkit() {
  const mk = {
    h: {},
    init: vi.fn(),
    addEventListener(ev, fn) { (mk.h[ev] = mk.h[ev] || []).push(fn); },
    fire(ev, v) { (mk.h[ev] || []).forEach((f) => f(v)); },
    Coordinate: class { constructor(lat, lng) { this.latitude = lat; this.longitude = lng; } },
    CoordinateSpan: class { constructor(a, b) { this.latitudeDelta = a; this.longitudeDelta = b; } },
    CoordinateRegion: class { constructor(c, s) { this.center = c; this.span = s; } toBoundingRegion() { return { westLongitude: -4, southLatitude: 40, eastLongitude: -3, northLatitude: 41 }; } },
    BoundingRegion: class { constructor(n, e, s, w) { this.b = [n, e, s, w]; } toCoordinateRegion() { return new mk.CoordinateRegion(new mk.Coordinate(40.5, -3.5), new mk.CoordinateSpan(0, 0)); } },
    Padding: class {},
    Annotation: class { constructor(c, f, o) { this.c = c; this.f = f; this.o = o; } },
    Map: class {
      constructor(el, o) { this.o = o; this.region = o.region; this.anns = []; this.h = {}; mk.map = this; }
      addEventListener(ev, fn) { (this.h[ev] = this.h[ev] || []).push(fn); }
      setRegionAnimated(r) { this.region = r; }
      addAnnotation(a) { this.anns.push(a); }
      removeAnnotation(a) { this.anns = this.anns.filter((x) => x !== a); }
      destroy() {}
    },
  };
  mk.Map.ColorSchemes = { Dark: 'dark' };
  return mk;
}

describe('Apple MapKit JS engine', () => {
  afterEach(() => { delete window.mapkit; delete window.fetch; });
  it('gets its token from the site, never from a key in the page, and converts zoom both ways', async () => {
    const mk = fakeMapkit();
    window.mapkit = mk;
    window.fetch = vi.fn(() => Promise.resolve({ ok: true, text: () => Promise.resolve('jwt.token') }));
    const { create, spanFor, zoomFor } = await import('../src/engines/apple.js');
    const e = await create(Object.assign(base(), { provider: 'apple', tokenUrl: '/actions/rumbo-maps/keys/mapkit-token' }));
    const init = mk.init.mock.calls[0][0];
    expect(init.language).toBe('es');
    const done = vi.fn();
    init.authorizationCallback(done);
    await flush(2);
    expect(window.fetch.mock.calls[0][0]).toBe('/actions/rumbo-maps/keys/mapkit-token');
    expect(done).toHaveBeenCalledWith('jwt.token');
    expect(zoomFor(spanFor(11, 800), 800)).toBeCloseTo(11, 6);
    expect(e.zoom()).toBeCloseTo(12, 6);
    expect(e.bounds()).toEqual([-4, 40, -3, 41]);
    const el = document.createElement('button');
    e.addMarker(el, [-3, 40], { anchor: 'center', popup: '<b>x</b>' });
    const a = mk.map.anns[0];
    expect(a.f().className).toBe('rumbo-at rumbo-at--c');
    expect(a.o.callout.calloutElementForAnnotation().className).toBe('rumbo-callout');
  });

  it('reports a refused token with what to check', async () => {
    const mk = fakeMapkit();
    window.mapkit = mk;
    const onError = vi.fn();
    const { create } = await import('../src/engines/apple.js');
    const e = await create(Object.assign(base(), { onError }));
    mk.fire('error', { status: 'Unauthorized' });
    await expect(e.loaded).rejects.toThrow(/team ID, key ID and private key/);
  });
});

describe('Provider SDKs come only from the provider', () => {
  it('does not import any provider SDK into the map bundle', async () => {
    const fs = await import('node:fs');
    const src = fs.readFileSync(process.cwd() + '/src/map.js', 'utf8');
    expect(src).not.toMatch(/googleapis|api\.mapbox|js\.api\.here|apple-mapkit|unpkg|jsdelivr/);
  });
});
