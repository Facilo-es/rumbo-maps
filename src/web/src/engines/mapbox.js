// Mapbox GL JS, loaded from Mapbox's own URL only when Mapbox is the map provider.
import { glEngine } from './gl.js';
import { script, css } from './sdk.js';
import { customStyle, styleName } from './styles.js';

export const VERSION = 'v3.34.2';
const BASE = 'https://api.mapbox.com/mapbox-gl-js/' + VERSION + '/mapbox-gl';
const STYLES = { positron: 'light-v11', dark: 'dark-v11', liberty: 'streets-v12', bright: 'outdoors-v12', fiord: 'navigation-night-v1' };

export function mapboxStyle(style) {
  return customStyle(style) || 'mapbox://styles/mapbox/' + (STYLES[styleName(style)] || 'light-v11');
}

export function create(o) {
  const win = o.win || window;
  css(BASE + '.css');
  return (win.mapboxgl ? Promise.resolve() : script(BASE + '.js')).then(() => {
    const gl = win.mapboxgl;
    gl.accessToken = o.key || '';
    return glEngine(gl, Object.assign({}, o, { style: mapboxStyle(o.style), mapbox: true }));
  });
}
