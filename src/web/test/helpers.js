import { vi } from 'vitest';

/** IntersectionObserver stub: everything is "in view" as soon as it is observed. */
export function installIO(win = window) {
  win.IntersectionObserver = class {
    constructor(cb) { this.cb = cb; }
    observe(el) { Promise.resolve().then(() => this.cb([{ isIntersecting: true, intersectionRatio: 1, target: el }])); }
    disconnect() {}
    unobserve() {}
  };
}

export const flush = async (n = 6) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)); };

export function jsonResponse(data) {
  return Promise.resolve({ ok: true, json: () => Promise.resolve(data) });
}

export function fakeMapModule() {
  const ctl = { setMarkers: vi.fn(), setOrigin: vi.fn(), highlight: vi.fn(), focus: vi.fn(), on: vi.fn(), destroy: vi.fn() };
  return { ctl, mod: { mountMap: vi.fn(() => ctl) } };
}
