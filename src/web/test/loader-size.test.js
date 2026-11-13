import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { resolve } from 'node:path';

const dist = resolve(process.cwd(), 'dist');
const at = (f) => resolve(dist, f);
const gz = (f) => gzipSync(readFileSync(at(f)), { level: 9 }).length;

describe('dist budget', () => {
  it('has been built', () => {
    expect(existsSync(at('rumbo-loader.js'))).toBe(true);
  });
  it('rumbo-loader.js is under 10 KB gzipped', () => {
    expect(gz('rumbo-loader.js')).toBeLessThan(10240);
  });
  it('rumbo-field.js (CP loader) is under 10 KB gzipped', () => {
    expect(gz('rumbo-field.js')).toBeLessThan(10240);
  });
  it('loader has no inline-script or eval tricks and loads chunks dynamically', () => {
    const src = readFileSync(at('rumbo-loader.js'), 'utf8');
    expect(src).not.toMatch(/\beval\(|new Function\(/);
    expect(src).toMatch(/import\(/);
    expect(src).not.toMatch(/maplibre/i);
  });
});
