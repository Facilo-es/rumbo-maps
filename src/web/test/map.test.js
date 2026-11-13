// The map controller against a fake engine: provider choice, queued calls, icons, clustering, failure.
import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { mountMap, providerOf, iconSrc } from '../src/map.js';
import { flush } from './helpers.js';

function fakeEngine() {
  const markers = [];
  const eng = {
    native: {},
    loaded: Promise.resolve(),
    markers,
    addMarker: vi.fn((el, ll, o) => { const m = { el: () => el, lngLat: () => ll, remove: vi.fn(), o }; markers.push(m); return m; }),
    setView: vi.fn(), fit: vi.fn(), zoom: () => 5, bounds: () => [-10, 35, 5, 45], onMove: vi.fn(), resize: vi.fn(), destroy: vi.fn(),
  };
  return eng;
}
const engines = (eng, seen) => {
  const mod = () => Promise.resolve({ create: (o) => { seen.push(o); return Promise.resolve(eng); } });
  return { maplibre: mod, mapbox: mod, google: mod, here: mod, apple: mod };
};

describe('providerOf', () => {
  it('normalises names and falls back to OpenFreeMap', () => {
    expect(providerOf({ provider: 'Google' }).name).toBe('google');
    expect(providerOf({ provider: { name: 'nonsense' } }).name).toBe('openfreemap');
    expect(providerOf({}).name).toBe('openfreemap');
  });
});

describe('iconSrc', () => {
  it('allows image URLs and image data URIs, nothing else', () => {
    expect(iconSrc('https://x.es/a.png')).toBe('https://x.es/a.png');
    expect(iconSrc('data:image/svg+xml;base64,PHN2Zy8+')).toMatch(/^data:image\/svg/);
    expect(iconSrc('javascript:alert(1)')).toBe('');
    expect(iconSrc('data:text/html,<b>')).toBe('');
  });
});

describe('mountMap', () => {
  it('passes provider, browser key, map ID, token URL and language to the engine', async () => {
    const seen = [];
    const el = document.createElement('div');
    const ctl = mountMap(el, { engines: engines(fakeEngine(), seen), provider: { name: 'google', key: 'AIza', mapId: 'm1' }, lang: 'es-ES', markers: [] });
    await ctl.ready;
    expect(seen[0]).toMatchObject({ provider: 'google', key: 'AIza', mapId: 'm1', lang: 'es-ES' });
    expect(el.getAttribute('data-rumbo-engine')).toBe('google');
  });

  it('queues markers and origin set before the SDK has loaded', async () => {
    const eng = fakeEngine();
    const el = document.createElement('div');
    const ctl = mountMap(el, { engines: engines(eng, []), markers: [] });
    ctl.setMarkers([{ id: 'a', lat: 40, lng: -3, title: 'A' }, { id: 'b', lat: 41, lng: -4, title: 'B' }]);
    ctl.setOrigin({ lat: 40.5, lng: -3.5 });
    expect(eng.addMarker).not.toHaveBeenCalled();
    await ctl.ready;
    expect(eng.markers.filter((m) => m.el().className === 'rumbo-you')).toHaveLength(1);
    expect(eng.markers.filter((m) => /rumbo-pin/.test(m.el().className))).toHaveLength(2);
    expect(eng.fit).toHaveBeenCalled();
    ctl.highlight('b');
    expect(eng.markers.find((m) => m.el().classList.contains('is-active')).lngLat()).toEqual([-4, 41]);
  });

  it('uses the per-entry icon over the map icon, centred, with a popup', async () => {
    const eng = fakeEngine();
    const ctl = mountMap(document.createElement('div'), {
      engines: engines(eng, []), markerIcon: 'https://x.es/map.svg',
      markers: [{ lat: 40, lng: -3, title: 'A', icon: 'https://x.es/own.png', html: '<p>Card</p>' }, { lat: 41, lng: -3, title: 'B' }],
    });
    await ctl.ready;
    const [a, b] = eng.markers;
    expect(a.el().querySelector('img').src).toBe('https://x.es/own.png');
    expect(b.el().querySelector('img').src).toBe('https://x.es/map.svg');
    expect(a.o.anchor).toBe('center');
    expect(a.o.popup).toContain('<p>Card</p>');
  });

  it('clusters large sets through the engine bounds and zoom', async () => {
    const eng = fakeEngine();
    const markers = Array.from({ length: 120 }, (_, i) => ({ lat: 40 + (i % 3) * 0.001, lng: -3 + (i % 4) * 0.001, title: 'P' + i }));
    const ctl = mountMap(document.createElement('div'), { engines: engines(eng, []), markers });
    await ctl.ready;
    expect(eng.markers.some((m) => m.el().className.includes('rumbo-cluster'))).toBe(true);
    expect(eng.markers.length).toBeLessThan(120);
  });

  it('falls back to the address list when the provider fails', async () => {
    const el = document.createElement('div');
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const bad = { maplibre: () => Promise.resolve({ create: () => Promise.reject(new Error('Could not load host')) }) };
    const ctl = mountMap(el, { engines: bad, markers: [] });
    await expect(ctl.ready).rejects.toThrow('Could not load host');
    await flush(2);
    expect(el.classList.contains('rumbo-failed')).toBe(true);
    expect(el.classList.contains('rumbo-map--live')).toBe(false);
    warn.mockRestore();
  });
});

describe('map.css', () => {
  it('keeps MapLibre markers absolutely placed', () => {
    // .rumbo-pin{position:relative} has the same weight as .maplibregl-marker{position:absolute} and comes
    // later, so without the override every marker after the first was pushed down out of the map.
    const css = readFileSync(process.cwd() + '/src/map.css', 'utf8');
    const rel = css.indexOf('.rumbo-pin,.rumbo-cluster{position:relative}');
    const abs = css.indexOf('.maplibregl-marker.rumbo-pin,.maplibregl-marker.rumbo-cluster{position:absolute}');
    expect(rel).toBeGreaterThan(-1);
    expect(abs).toBeGreaterThan(rel);
  });
});
