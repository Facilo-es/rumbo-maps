import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createRumbo } from '../src/loader.js';
import { installIO, flush, fakeMapModule } from './helpers.js';

function setup(html, opts = {}) {
  document.body.innerHTML = html;
  const { mod } = fakeMapModule();
  const importer = vi.fn(() => Promise.resolve(mod));
  const r = createRumbo({ win: window, doc: document, importer, scriptSrc: 'https://shop.test/assets/rumbo/rumbo-loader.js', ...opts });
  return { r, importer, mod };
}
const MAP = '<div data-rumbo-map data-rumbo-consent="required" data-rumbo-provider="OpenFreeMap"><script type="application/json" data-rumbo-config>{"markers":[{"lat":40.4,"lng":-3.7,"title":"A"}]}</script></div>';
const mapCalls = (importer) => importer.mock.calls.filter(([u]) => /rumbo-map\.js$/.test(u));

describe('privacy mode (consent gating)', () => {
  let fetchSpy;
  beforeEach(() => {
    installIO();
    localStorage.clear();
    delete window.RumboConsent;
    document.documentElement.removeAttribute('data-rumbo-consent');
    document.head.innerHTML = '';
    fetchSpy = vi.fn(() => Promise.reject(new Error('no network in tests')));
    window.fetch = fetchSpy;
  });

  it('loads nothing from the map provider before consent and shows the placeholder', async () => {
    const { r, importer } = setup(MAP);
    r.scan();
    await flush();
    expect(mapCalls(importer)).toHaveLength(0);
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(document.querySelector('link[href*="rumbo-map.css"]')).toBeNull();
    expect(document.querySelector('img')).toBeNull();
    const ph = document.querySelector('[data-rumbo-placeholder]');
    expect(ph.hidden).toBe(false);
    expect(ph.textContent).toContain('This map is provided by OpenFreeMap. Loading it sends your IP address to them.');
    expect(ph.querySelector('[data-rumbo-show]').textContent).toBe('Show map');
    expect(ph.querySelector('[data-rumbo-always]').textContent).toBe('Always show maps on this site');
  });

  it('Show map loads this map only, without remembering', async () => {
    const { r, importer, mod } = setup(MAP + MAP.replace('data-rumbo-map', 'data-rumbo-map id="two"'));
    r.scan();
    await flush();
    document.querySelector('[data-rumbo-show]').click();
    await flush();
    expect(mapCalls(importer)).toHaveLength(1);
    expect(importer.mock.calls[0][0]).toBe('https://shop.test/assets/rumbo/rumbo-map.js');
    expect(mod.mountMap).toHaveBeenCalledTimes(1);
    expect(localStorage.getItem('rumbo:consent')).toBeNull();
  });

  it('"Always show maps" loads every waiting map and is remembered', async () => {
    const { r, importer } = setup(MAP + MAP);
    r.scan();
    await flush();
    document.querySelector('[data-rumbo-always]').click();
    await flush();
    expect(mapCalls(importer)).toHaveLength(2);
    expect(localStorage.getItem('rumbo:consent')).toBe('always');
    const again = setup(MAP);
    again.r.scan();
    await flush();
    expect(mapCalls(again.importer)).toHaveLength(1);
  });

  it('loads on the rumbo:consent event from a cookie banner', async () => {
    const { r, importer } = setup(MAP);
    r.scan();
    await flush();
    expect(mapCalls(importer)).toHaveLength(0);
    document.dispatchEvent(new Event('rumbo:consent'));
    await flush();
    expect(mapCalls(importer)).toHaveLength(1);
  });

  it('loads at once with window.RumboConsent === true', async () => {
    window.RumboConsent = true;
    const { r, importer } = setup(MAP);
    r.scan();
    await flush();
    expect(mapCalls(importer)).toHaveLength(1);
    expect(document.querySelector('[data-rumbo-placeholder]')).toBeNull();
  });

  it('loads at once with <html data-rumbo-consent="granted">', async () => {
    document.documentElement.setAttribute('data-rumbo-consent', 'granted');
    const { r, importer } = setup(MAP);
    r.scan();
    await flush();
    expect(mapCalls(importer)).toHaveLength(1);
  });

  it('uses the server placeholder and static image when given', async () => {
    const html = '<div data-rumbo-map data-rumbo-consent="required"><div class="rumbo-consent" data-rumbo-placeholder hidden><img src="/static.png" alt=""><p>Este mapa lo ofrece OpenFreeMap.</p><button data-rumbo-show>Mostrar mapa</button></div></div>';
    const { r, importer } = setup(html);
    r.scan();
    await flush();
    expect(document.querySelectorAll('[data-rumbo-placeholder]')).toHaveLength(1);
    expect(document.querySelector('[data-rumbo-placeholder]').hidden).toBe(false);
    document.querySelector('[data-rumbo-show]').click();
    await flush();
    expect(mapCalls(importer)).toHaveLength(1);
    expect(document.querySelector('[data-rumbo-placeholder]').hidden).toBe(true);
  });

  it('maps without privacy mode load when near the screen, from data-rumbo-src', async () => {
    const { r, importer } = setup('<div data-rumbo-map data-rumbo-src="/cdn/rumbo/"></div>');
    r.scan();
    await flush();
    expect(importer.mock.calls[0][0]).toBe('http://localhost:3000/cdn/rumbo/rumbo-map.js');
    expect(document.querySelector('link[href$="rumbo-map.css"]')).not.toBeNull();
  });

  it('the locator list works before consent; only its map waits', async () => {
    const locMod = { mountLocator: vi.fn((el, ctx) => { ctx.loadMap(el.querySelector('[data-r="map"]')); }) };
    document.body.innerHTML = '<section data-rumbo-locator data-rumbo-consent="required"><ol data-r="list"></ol><div data-r="map"></div></section>';
    const { mod } = fakeMapModule();
    const importer = vi.fn((u) => Promise.resolve(/locator/.test(u) ? locMod : mod));
    createRumbo({ importer, scriptSrc: '/dist/rumbo-loader.js' }).scan();
    await flush();
    expect(locMod.mountLocator).toHaveBeenCalled();
    expect(mapCalls(importer)).toHaveLength(0);
    expect(document.querySelector('[data-r="map"]').classList.contains('rumbo-waiting')).toBe(true);
  });
});
