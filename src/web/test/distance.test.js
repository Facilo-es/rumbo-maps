import { describe, it, expect } from 'vitest';
import { formatDistance, styleUrl, safeUrl } from '../src/util.js';
import { awayHtml, DEFAULT_STRINGS } from '../src/locator.js';

describe('distance formatting', () => {
  it('Spanish uses a decimal comma, one decimal under 10', () => {
    expect(formatDistance(1.24, 'es')).toBe('1,2');
    expect(formatDistance(9.96, 'es')).toBe('10');
    expect(formatDistance(3, 'es')).toBe('3');
  });
  it('English uses a decimal point', () => {
    expect(formatDistance(1.24, 'en')).toBe('1.2');
    expect(formatDistance(12.6, 'en')).toBe('13');
    expect(formatDistance(1234.4, 'en')).toBe('1,234');
  });
  it('builds the distance line with the spec copy', () => {
    expect(awayHtml(1.24, 'km', 'es', { awayKm: 'a {distance} km' })).toBe('a <b>1,2</b> km');
    expect(awayHtml(2.5, 'mi', 'en', DEFAULT_STRINGS)).toBe('<b>2.5</b> mi away');
  });
  it('bad input gives an empty string', () => {
    expect(formatDistance('x', 'es')).toBe('');
  });
});

describe('styles and urls', () => {
  it('maps OpenFreeMap names and passes Pro style URLs through', () => {
    expect(styleUrl('liberty')).toBe('https://tiles.openfreemap.org/styles/liberty');
    expect(styleUrl()).toBe('https://tiles.openfreemap.org/styles/positron');
    expect(styleUrl('https://api.maptiler.com/maps/streets/style.json?key=abc')).toBe('https://api.maptiler.com/maps/streets/style.json?key=abc');
  });
  it('blocks javascript: urls', () => {
    expect(safeUrl('javascript:alert(1)')).toBe('#');
    expect(safeUrl(' JavaScript:alert(1)')).toBe('#');
    expect(safeUrl('/cafes/a')).toBe('/cafes/a');
  });
});
