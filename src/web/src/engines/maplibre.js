// OpenFreeMap (no key) and MapTiler (the customer's key) on the bundled MapLibre GL JS.
import maplibregl from '../ml.js';
import { glEngine } from './gl.js';
import { styleUrl } from '../util.js';
import { customStyle, styleName } from './styles.js';

const MAPTILER = { positron: 'dataviz', dark: 'dataviz-dark', liberty: 'streets-v2', bright: 'bright-v2', fiord: 'backdrop' };

export function maptilerStyle(style, key) {
  const own = customStyle(style);
  if (own) return own;
  return 'https://api.maptiler.com/maps/' + (MAPTILER[styleName(style)] || 'dataviz') + '/style.json?key=' + encodeURIComponent(key || '');
}

export function create(o) {
  const style = o.provider === 'maptiler' ? maptilerStyle(o.style, o.key) : styleUrl(o.style);
  return Promise.resolve(glEngine(maplibregl, Object.assign({}, o, { style })));
}
