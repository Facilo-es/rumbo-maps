import { describe, it, expect, beforeEach, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { mountLocator } from '../src/locator.js';
import { flush, jsonResponse, fakeMapModule } from './helpers.js';

const fixture = JSON.parse(readFileSync(process.cwd() + '/test/fixtures/results.json', 'utf8'));
const ES = {
  awayKm: 'a {distance} km', countMany: '{n} ubicaciones cerca de {place}', directions: 'Cómo llegar', details: 'Ver detalles',
  none: 'No hay nada a menos de {within} {unit}. La más cercana es {title}, a {distance}.',
  findErr: 'No encontramos ese lugar. Revisa cómo está escrito o prueba con un código postal.',
};

function page({ lang = 'es', look = 'side', strings = ES } = {}) {
  const cfg = { lang, look, unit: 'km', endpoint: '/actions/rumbo-maps/locator/search', limit: 20, strings };
  document.body.innerHTML = `
  <section class="rumbo rumbo--${look}" data-rumbo-locator>
    <script type="application/json" data-rumbo-config>${JSON.stringify(cfg)}</script>
    <form data-r="form" method="get">
      <input type="hidden" name="l" value="signed-token">
      <input name="q" type="search" value="">
      <button type="submit">Buscar</button>
      <button type="button" data-r="me" hidden>Usar mi ubicación</button>
      <select name="radius"><option value="10">10 km</option><option value="25" selected>25 km</option></select>
      <input type="hidden" name="unit" value="km">
      <fieldset><label class="rumbo-chip"><input type="checkbox" name="filters[type][]" value="coffee">Coffee</label>
      <label class="rumbo-chip"><input type="checkbox" name="filters[type][]" value="wifi">Wi-Fi</label></fieldset>
    </form>
    <p data-r="msg" hidden></p>
    <p data-r="count" aria-live="polite"></p>
    <ol data-r="list"></ol>
    <div data-r="map"></div>
  </section>`;
  return document.querySelector('[data-rumbo-locator]');
}

async function searchFor(root, q, fetch) {
  const loc = mountLocator(root, { fetch, loadMap: () => Promise.resolve(fakeMapModule().mod) });
  root.querySelector('input[name=q]').value = q;
  root.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
  await flush();
  return loc;
}

describe('store locator', () => {
  beforeEach(() => { history.replaceState(null, '', '/cafes?utm_source=x'); });

  it('renders numbered cards from the endpoint, with Spanish distances and an aria-live count', async () => {
    const root = page();
    const fetch = vi.fn(() => jsonResponse(fixture));
    await searchFor(root, 'Madrid', fetch);
    const url = new URL(fetch.mock.calls[0][0]);
    expect(url.pathname).toBe('/actions/rumbo-maps/locator/search');
    expect(url.searchParams.get('q')).toBe('Madrid');
    expect(url.searchParams.get('l')).toBe('signed-token');
    expect(url.searchParams.get('radius')).toBe('25');
    expect(url.searchParams.get('limit')).toBe('20');
    const cards = root.querySelectorAll('.rumbo-card');
    expect(cards).toHaveLength(3);
    expect(cards[0].querySelector('.rumbo-card__n').textContent).toBe('1');
    expect(cards[0].querySelector('.rumbo-card__t').textContent).toBe('Grano Malasaña');
    expect(cards[0].querySelector('.rumbo-card__d').innerHTML).toBe('a <b>1,2</b> km');
    expect(cards[2].querySelector('.rumbo-card__d').innerHTML).toBe('a <b>13</b> km');
    expect(cards[0].querySelector('.rumbo-card__meta').textContent).toContain('Open until 20:00');
    expect(cards[0].querySelector('.rumbo-card__dir').textContent).toContain('Cómo llegar');
    expect(cards[0].querySelector('.rumbo-card__url').getAttribute('href')).toBe('/cafes/malasana');
    expect(cards[2].querySelector('.rumbo-card__url')).toBeNull();
    expect(cards[2].querySelector('.rumbo-card__dir').getAttribute('href')).toContain('openstreetmap.org/directions');
    expect(cards[0].tabIndex).toBe(0);
    expect(root.querySelector('[data-r="count"]').textContent).toBe('3 ubicaciones cerca de Madrid');
    expect(root.querySelector('[data-r="msg"]').hidden).toBe(true);
    expect(root.getAttribute('aria-busy')).toBe('false');
  });

  it('escapes titles and addresses from the server', async () => {
    const root = page();
    const evil = { ...fixture, results: [{ ...fixture.results[0], title: '<img src=x onerror=alert(1)>' }] };
    await searchFor(root, 'Madrid', () => jsonResponse(evil));
    expect(root.querySelector('img')).toBeNull();
    expect(root.querySelector('.rumbo-card__t').textContent).toBe('<img src=x onerror=alert(1)>');
  });

  it('empty state shows the nearest location with the spec copy', async () => {
    const root = page();
    const empty = { ok: true, results: [], total: 0, unit: 'km', within: 10, origin: { label: 'Soria' }, nearest: { ...fixture.results[2], distance: 41.3 } };
    await searchFor(root, 'Soria', () => jsonResponse(empty));
    const msg = root.querySelector('[data-r="msg"]');
    expect(msg.hidden).toBe(false);
    expect(msg.className).toContain('rumbo-msg--empty');
    expect(msg.textContent).toBe('No hay nada a menos de 10 km. La más cercana es Grano Chamartín, a 41 km.');
    expect(root.querySelectorAll('.rumbo-card')).toHaveLength(1);
    expect(root.querySelector('[data-r="count"]').textContent).toBe('');
  });

  it('empty state without a nearest one', async () => {
    const root = page({ lang: 'en', strings: {} });
    await searchFor(root, 'Nowhere', () => jsonResponse({ ok: true, results: [], within: 25, unit: 'km' }));
    expect(root.querySelector('[data-r="msg"]').textContent).toBe('Nothing within 25 km.');
    expect(root.querySelectorAll('.rumbo-card')).toHaveLength(0);
  });

  it('error states: not found, server text, network failure', async () => {
    let root = page();
    await searchFor(root, 'Mdrdd', () => jsonResponse({ ok: false, code: 'notFound', errors: [] }));
    let msg = root.querySelector('[data-r="msg"]');
    expect(msg.textContent).toBe(ES.findErr);
    expect(msg.className).toContain('rumbo-msg--error');

    root = page();
    await searchFor(root, 'Madrid', () => jsonResponse({ ok: false, code: 'rateLimited', errors: ['Too many searches. Wait a minute.'] }));
    expect(root.querySelector('[data-r="msg"]').textContent).toBe('Too many searches. Wait a minute.');

    root = page({ lang: 'en', strings: {} });
    await searchFor(root, 'Madrid', () => Promise.reject(new TypeError('Failed to fetch')));
    expect(root.querySelector('[data-r="msg"]').textContent).toBe("Search isn't working right now. Please try again in a moment.");
    expect(root.querySelector('.rumbo-skel')).toBeNull();
  });

  it('shows skeleton cards while loading', async () => {
    const root = page();
    let resolve;
    const loc = mountLocator(root, { fetch: () => new Promise((r) => { resolve = r; }) });
    root.querySelector('input[name=q]').value = 'Madrid';
    loc.search();
    expect(root.querySelectorAll('.rumbo-skel')).toHaveLength(3);
    expect(root.getAttribute('aria-busy')).toBe('true');
    resolve({ json: () => Promise.resolve(fixture) });
    await flush();
    expect(root.querySelector('.rumbo-skel')).toBeNull();
  });

  it('keeps the address bar in sync with replaceState, keeping other params', async () => {
    const root = page();
    const push = vi.spyOn(history, 'pushState');
    await searchFor(root, 'Madrid', () => jsonResponse(fixture));
    const u = new URL(location.href);
    expect(u.pathname).toBe('/cafes');
    expect(u.searchParams.get('utm_source')).toBe('x');
    expect(u.searchParams.get('q')).toBe('Madrid');
    expect(u.searchParams.get('radius')).toBe('25');
    expect(u.searchParams.get('unit')).toBe('km');
    expect(push).not.toHaveBeenCalled();
    root.querySelector('input[name=q]').value = 'Sevilla';
    root.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
    expect(new URL(location.href).searchParams.getAll('q')).toEqual(['Sevilla']);
    expect(new URL(location.href).searchParams.getAll('l').length).toBeLessThanOrEqual(1);
  });

  it('filter chips toggle, search again and land in the request and URL', async () => {
    const root = page();
    const fetch = vi.fn(() => jsonResponse(fixture));
    await searchFor(root, 'Madrid', fetch);
    const box = root.querySelector('input[value="wifi"]');
    box.checked = true;
    box.dispatchEvent(new Event('change', { bubbles: true }));
    await flush();
    expect(box.closest('.rumbo-chip').classList.contains('is-on')).toBe(true);
    expect(fetch).toHaveBeenCalledTimes(2);
    const url = new URL(fetch.mock.calls[1][0]);
    expect(url.searchParams.getAll('filters[type][]')).toEqual(['wifi']);
    expect(new URL(location.href).searchParams.getAll('filters[type][]')).toEqual(['wifi']);
    box.checked = false;
    box.dispatchEvent(new Event('change', { bubbles: true }));
    await flush();
    expect(box.closest('.rumbo-chip').classList.contains('is-on')).toBe(false);
    expect(new URL(location.href).searchParams.has('filters[type][]')).toBe(false);
  });

  it('cards and markers stay in sync, and Enter on a card pans the map', async () => {
    const root = page();
    const { ctl, mod } = fakeMapModule();
    const loc = mountLocator(root, { fetch: () => jsonResponse(fixture), loadMap: () => Promise.resolve(mod) });
    root.querySelector('input[name=q]').value = 'Madrid';
    await loc.search();
    await flush();
    expect(ctl.setMarkers).toHaveBeenLastCalledWith(expect.arrayContaining([expect.objectContaining({ id: 1, title: 'Grano Malasaña' })]));
    const card = root.querySelectorAll('.rumbo-card')[1];
    card.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    expect(card.classList.contains('is-active')).toBe(true);
    expect(ctl.focus).toHaveBeenCalledWith('2');
    const select = ctl.on.mock.calls.find((c) => c[0] === 'select')[1];
    select(3);
    expect(root.querySelectorAll('.rumbo-card')[2].classList.contains('is-active')).toBe(true);
    expect(document.activeElement).toBe(root.querySelectorAll('.rumbo-card')[2]);
  });

  it('List only never loads a map', async () => {
    const root = page({ look: 'list' });
    const loadMap = vi.fn();
    mountLocator(root, { fetch: () => jsonResponse(fixture), loadMap });
    await flush();
    expect(loadMap).not.toHaveBeenCalled();
  });

  it('Use my location sends coordinates and shows the spec error when refused', async () => {
    const root = page();
    const fetch = vi.fn(() => jsonResponse({ ...fixture, origin: { lat: 40.42, lng: -3.7 } }));
    const geo = { getCurrentPosition: vi.fn((ok) => ok({ coords: { latitude: 40.416775, longitude: -3.70379 } })) };
    Object.defineProperty(navigator, 'geolocation', { value: geo, configurable: true });
    const loc = mountLocator(root, { fetch });
    expect(root.querySelector('[data-r="me"]').hidden).toBe(false);
    loc.locate();
    await flush();
    const url = new URL(fetch.mock.calls[0][0]);
    expect(url.searchParams.get('lat')).toBe('40.4168');
    expect(url.searchParams.has('q')).toBe(false);
    expect(new URL(location.href).searchParams.get('lat')).toBe('40.417');
    geo.getCurrentPosition = vi.fn((ok, fail) => fail({ code: 1 }));
    loc.locate();
    expect(root.querySelector('[data-r="msg"]').textContent).toBe("We couldn't get your location. Type a town or postcode instead.");
  });
});
