import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mountField, watchFields } from '../src/field.js';
import { flush, jsonResponse } from './helpers.js';

function fakeAdapter() {
  const a = { pin: null, zoom: 12, handlers: {} };
  return Object.assign(a, {
    setPin: vi.fn((ll) => { a.pin = ll; }), removePin: vi.fn(() => { a.pin = null; }),
    onDragEnd: (fn) => { a.handlers.drag = fn; }, onClick: (fn) => { a.handlers.click = fn; }, onZoom: (fn) => { a.handlers.zoom = fn; },
    flyTo: vi.fn(), getZoom: () => a.zoom,
  });
}

const N = 'fields[place]';
function field(value, extra = {}) {
  const cfg = { ...extra, name: N, value, suggestUrl: '/actions/rumbo-maps/geocode/suggest', reverseUrl: '/actions/rumbo-maps/geocode/reverse', countries: ['es'] };
  const parts = ['number', 'street', 'city', 'postcode', 'county', 'state', 'country', 'countryCode'].map((p) => `<input type="hidden" name="${N}[parts][${p}]" value="">`).join('');
  document.body.innerHTML = `<div class="rumbo-field" data-rumbo-field><script type="application/json" data-rumbo-field-config>${JSON.stringify(cfg)}</script>
    <input type="hidden" name="${N}[lat]" value=""><input type="hidden" name="${N}[lng]" value=""><input type="hidden" name="${N}[zoom]" value="">
    <input type="hidden" name="${N}[source]" value=""><input type="text" name="${N}[address]" value="">${parts}</div>`;
  return document.querySelector('[data-rumbo-field]');
}
const val = (n) => document.querySelector(`[name="${N}[${n}]"]`).value;

const PICK = { lat: 40.4167754, lng: -3.7037902, address: 'Calle Mayor 1, 28013 Madrid, España', source: 'nominatim',
  parts: { number: '1', street: 'Calle Mayor', city: 'Madrid', postcode: '28013', state: 'Comunidad de Madrid', country: 'España', countryCode: 'ES' } };

describe('Location field editor', () => {
  let adapter;
  beforeEach(() => { adapter = fakeAdapter(); });

  it('picking a suggestion fills address, parts and pin, and sets the provider as source', () => {
    const f = mountField(field(), { createMap: () => adapter });
    f.pick(PICK);
    expect(val('address')).toBe(PICK.address);
    expect(val('parts][city')).toBe('Madrid');
    expect(val('parts][countryCode')).toBe('ES');
    expect(val('lat')).toBe('40.4167754');
    expect(val('source')).toBe('nominatim');
    expect(adapter.pin).toEqual([-3.7037902, 40.4167754]);
  });

  it('dragging the pin updates lat/lng only and sets source to manual', () => {
    const f = mountField(field(), { createMap: () => adapter });
    f.pick(PICK);
    adapter.handlers.drag([-3.71, 40.42]);
    expect(val('lat')).toBe('40.42');
    expect(val('lng')).toBe('-3.71');
    expect(val('source')).toBe('manual');
    expect(val('address')).toBe(PICK.address);
    expect(val('parts][street')).toBe('Calle Mayor');
  });

  it('Clear empties every input and removes the pin', () => {
    const root = field();
    mountField(root, { createMap: () => adapter }).pick(PICK);
    root.querySelector('[data-f="clear"]').click();
    root.querySelectorAll('input[name^="fields"]').forEach((i) => expect(i.value).toBe(''));
    expect(adapter.removePin).toHaveBeenCalled();
  });

  it('type-ahead waits for 3 characters and 350 ms, typing never moves a dragged pin, errors show under the box', async () => {
    vi.useFakeTimers();
    const fetch = vi.fn(() => jsonResponse({ ok: true, results: [PICK] }));
    const root = field({ lat: 40.4, lng: -3.7, zoom: 14, address: 'Old', source: 'manual', parts: {} });
    mountField(root, { createMap: () => adapter, fetch });
    const q = root.querySelector('.rumbo-f-q');
    q.value = 'Ca'; q.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(400);
    expect(fetch).not.toHaveBeenCalled();
    q.value = 'Calle M'; q.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(300);
    expect(fetch).not.toHaveBeenCalled();
    vi.advanceTimersByTime(60);
    expect(fetch).toHaveBeenCalledTimes(1);
    const u = new URL(fetch.mock.calls[0][0], location.href);
    expect(u.searchParams.get('q')).toBe('Calle M');
    expect(u.searchParams.get('countries')).toBe('es');
    vi.useRealTimers();
    await flush();
    expect(root.querySelectorAll('.rumbo-f-opt')).toHaveLength(1);
    expect(val('lat')).toBe('40.4');
    expect(adapter.pin).toEqual([-3.7, 40.4]);

    fetch.mockImplementation(() => jsonResponse({ ok: false, error: 'Nominatim said: rate limit. Check your key on the Test my keys page.' }));
    await root.__rumboField.suggest('Calle X');
    const err = root.querySelector('.rumbo-f-error');
    expect(err.hidden).toBe(false);
    expect(err.textContent).toContain('rate limit');
  });

  it('a provider error links to the key test page when the editor may open it', async () => {
    const fetch = vi.fn(() => jsonResponse({ ok: false, error: 'Google said: REQUEST_DENIED.' }));
    const root = field(null, { keyTestUrl: '/admin/utilities/rumbo-keys', keyTestLabel: 'Probar mis claves' });
    mountField(root, { createMap: () => adapter, fetch });
    await root.__rumboField.suggest('Calle X');
    const a = root.querySelector('.rumbo-f-error a.rumbo-field__keytest');
    expect(a.getAttribute('href')).toBe('/admin/utilities/rumbo-keys');
    expect(a.textContent).toBe('Probar mis claves');
    expect(root.querySelector('.rumbo-f-error').textContent).toContain('REQUEST_DENIED');

    const root2 = field(null);
    mountField(root2, { createMap: () => adapter, fetch });
    await root2.__rumboField.suggest('Calle X');
    expect(root2.querySelector('.rumbo-f-error a')).toBeNull();
  });

  it('Update address from pin fills the address but keeps the pin', async () => {
    const fetch = vi.fn(() => jsonResponse({ ok: true, results: [PICK] }));
    const root = field({ lat: 40.41, lng: -3.70, zoom: 15, address: '', source: 'manual', parts: {} });
    mountField(root, { createMap: () => adapter, fetch });
    root.querySelector('[data-f="reverse"]').click();
    await flush();
    expect(new URL(fetch.mock.calls[0][0], location.href).searchParams.get('lat')).toBe('40.41');
    expect(val('address')).toBe(PICK.address);
    expect(val('lat')).toBe('40.41');
    expect(val('source')).toBe('manual');
  });

  it('opening a saved location shows its address and changes nothing (no draft in Craft)', () => {
    const root = field();
    root.querySelector(`[name="${N}[address]"]`).value = PICK.address;
    root.querySelector(`[name="${N}[lat]"]`).value = '40.4167754';
    root.querySelector(`[name="${N}[lng]"]`).value = '-3.7037902';
    const changed = vi.fn();
    root.addEventListener('input', changed); root.addEventListener('change', changed);
    mountField(root, { createMap: () => adapter });
    expect(root.querySelector('.rumbo-f-q').value).toBe(PICK.address);
    expect(changed).not.toHaveBeenCalled();
  });

  it('mounts several fields and fields added later (Matrix)', async () => {
    document.body.innerHTML = '';
    const mo = watchFields(document, { createMap: () => fakeAdapter() });
    const holder = document.createElement('div');
    holder.innerHTML = '<div data-rumbo-field><script type="application/json" data-rumbo-field-config>{"name":"fields[a]"}</script></div><div data-rumbo-field><script type="application/json" data-rumbo-field-config>{"name":"fields[b]"}</script></div>';
    document.body.appendChild(holder);
    await flush();
    const roots = document.querySelectorAll('[data-rumbo-field]');
    expect(roots[0].__rumboField).toBeTruthy();
    expect(roots[1].__rumboField).toBeTruthy();
    expect(roots[1].querySelector('[name="fields[b][lat]"]')).not.toBeNull();
    mo.disconnect();
  });
});
