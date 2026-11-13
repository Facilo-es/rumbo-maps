// Small helpers shared by the locator and the map driver.

/** Replace {key} placeholders. Values are inserted as given (escape first if needed). */
export function fill(str, vars) {
  return String(str).replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m));
}

export function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/** Only http(s), protocol-relative and relative URLs pass; anything else (javascript:, data:) becomes "#". */
export function safeUrl(u) {
  const s = String(u == null ? '' : u).trim();
  if (!s) return '';
  if (/^(https?:)?\/\//i.test(s) || /^[/?#.]/.test(s) || !/^[a-z][a-z0-9+.-]*:/i.test(s)) return s;
  return '#';
}

/**
 * Format a distance number for the page language: one decimal under 10, none from 10 up.
 * formatDistance(1.234, 'es') -> "1,2"; formatDistance(1.234, 'en') -> "1.2"; formatDistance(12.6, 'es') -> "13"
 */
export function formatDistance(value, lang) {
  const n = Number(value);
  if (!isFinite(n)) return '';
  const digits = n < 10 ? 1 : 0;
  try {
    return new Intl.NumberFormat(lang || undefined, { minimumFractionDigits: 0, maximumFractionDigits: digits }).format(n);
  } catch (e) {
    return n.toFixed(digits);
  }
}

/** Read the JSON config blob inside an element: <script type="application/json" data-rumbo-config>. */
export function readConfig(el) {
  const s = el.querySelector('script[type="application/json"][data-rumbo-config]');
  if (!s) return {};
  try {
    return JSON.parse(s.textContent || '{}') || {};
  } catch (e) {
    return {};
  }
}

export const OPENFREEMAP_STYLES = ['liberty', 'bright', 'positron', 'dark', 'fiord'];

/** 'positron' -> OpenFreeMap style URL; full https URLs (Mapbox, MapTiler with key) pass through. */
export function styleUrl(style) {
  const s = String(style || 'positron').trim();
  if (/^https:\/\//i.test(s)) return s;
  return 'https://tiles.openfreemap.org/styles/' + (OPENFREEMAP_STYLES.includes(s) ? s : 'positron');
}

/** Text colour for a solid background: white, or near-black when white would be hard to read (WCAG contrast). */
export function inkFor(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
  if (!m) return null;
  const lum = (h) => [0, 2, 4].map((i) => {
    const v = parseInt(h.substr(i, 2), 16) / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  }).reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
  const l = lum(m[1]);
  return (1.05 / (l + 0.05)) >= ((l + 0.05) / (lum('14201b') + 0.05)) ? '#fff' : '#14201b';
}
