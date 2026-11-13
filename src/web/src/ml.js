// MapLibre GL JS (CSP build) with its worker served from our own dist folder.
// Every built chunk lives directly in dist/, so this URL resolves wherever this code ends up.
import maplibregl from 'maplibre-gl/dist/maplibre-gl-csp.js';

const here = import.meta.url;
maplibregl.setWorkerUrl(new URL('maplibre-gl-csp-worker.js', here).href);

export default maplibregl;
