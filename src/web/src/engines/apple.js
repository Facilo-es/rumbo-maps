// Apple MapKit JS, loaded from Apple only when Apple is the map provider. The site mints a short-lived
// token from the customer's Apple key (tokenUrl); the private key never reaches the browser.
// MapKit has no zoom levels, so zoom is converted to and from the visible longitude span.
import { script, deferred, lang2, anchorWrap, htmlEl } from './sdk.js';
import { styleName } from './styles.js';

export const SDK = 'https://cdn.apple-mapkit.com/mk/5.x.x/mapkit.core.js';
const CB = '__rumboMapkitReady';

function loadSdk(o, win) {
  if (win.mapkit && win.mapkit.Map) return Promise.resolve(false);
  if (win[CB + 'P']) return win[CB + 'P'];
  win[CB + 'P'] = new Promise((resolve, reject) => {
    win[CB] = () => resolve(true);
    script(SDK, { crossorigin: 'anonymous', 'data-callback': CB, 'data-libraries': 'map,annotations' }).catch(reject);
  });
  return win[CB + 'P'];
}

/** MapLibre zoom (512 px tiles) <-> longitude span for a container this wide. */
export const spanFor = (zoom, width) => (360 * (width || 600)) / (512 * Math.pow(2, zoom));
export const zoomFor = (span, width) => Math.log2((360 * (width || 600)) / (512 * Math.max(span, 1e-9)));

export function create(o) {
  const win = o.win || window;
  const failed = deferred();
  return loadSdk(o, win).then((fresh) => {
    const mk = win.mapkit;
    if (fresh || !mk.__rumbo) {
      mk.__rumbo = 1;
      mk.init({
        authorizationCallback(done) {
          win.fetch(o.tokenUrl, { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.text() : Promise.reject(new Error('Token request failed (HTTP ' + r.status + ')'))))
            .then(done, (e) => { if (o.onError) o.onError(e.message); failed.reject(e); });
        },
        language: lang2(o.lang) || undefined,
      });
    }
    mk.addEventListener('error', (e) => {
      const msg = 'Apple MapKit said: ' + ((e && e.status) || 'error') + '. Check the team ID, key ID and private key, and that the key has MapKit JS enabled.';
      if (o.onError) o.onError(msg);
      failed.reject(new Error(msg));
    });
    const width = () => o.container.clientWidth || 600;
    const height = () => o.container.clientHeight || 400;
    const region = (ll, zoom) => {
      const lng = spanFor(zoom, width());
      const lat = lng * (height() / width()) * Math.cos((ll[1] * Math.PI) / 180);
      return new mk.CoordinateRegion(new mk.Coordinate(ll[1], ll[0]), new mk.CoordinateSpan(lat, lng));
    };
    const map = new mk.Map(o.container, {
      region: region(o.center, o.zoom),
      isRotationEnabled: false,
      showsCompass: mk.FeatureVisibility ? mk.FeatureVisibility.Hidden : undefined,
      showsMapTypeControl: false,
      colorScheme: styleName(o.style) === 'dark' && mk.Map.ColorSchemes ? mk.Map.ColorSchemes.Dark : undefined,
    });
    const loaded = deferred();
    failed.promise.catch((e) => loaded.reject(e));
    map.addEventListener('region-change-end', () => loaded.resolve());
    setTimeout(() => loaded.resolve(), 3000); // MapKit has no "tiles loaded" event; no error by then counts as working

    return {
      native: map,
      loaded: loaded.promise,
      addMarker(el, ll, m = {}) {
        const opts = { title: el.getAttribute('aria-label') || '' };
        if (m.popup) opts.callout = { calloutElementForAnnotation: () => { const c = htmlEl(m.popup, el.ownerDocument); c.className = 'rumbo-callout'; return c; } };
        const a = new mk.Annotation(new mk.Coordinate(ll[1], ll[0]), () => anchorWrap(el, m.anchor, el.ownerDocument), opts);
        map.addAnnotation(a);
        return { remove: () => map.removeAnnotation(a), el: () => el, lngLat: () => ll };
      },
      setView(ll, zoom, animate) { map.setRegionAnimated(region(ll, zoom), !!animate); },
      fit(points, padding, maxZoom) {
        let n = -90, s = 90, w = 180, e = -180;
        points.forEach(([x, y]) => { n = Math.max(n, y); s = Math.min(s, y); w = Math.min(w, x); e = Math.max(e, x); });
        map.padding = new mk.Padding(padding, padding, padding, padding);
        const r = new mk.BoundingRegion(n, e, s, w).toCoordinateRegion();
        const minSpan = spanFor(maxZoom, width());
        if (r.span.longitudeDelta < minSpan) r.span = new mk.CoordinateSpan(minSpan * (height() / width()), minSpan);
        map.setRegionAnimated(r, false);
      },
      zoom: () => zoomFor(map.region.span.longitudeDelta, width()),
      bounds() {
        const b = map.region.toBoundingRegion();
        return [b.westLongitude, b.southLatitude, b.eastLongitude, b.northLatitude];
      },
      onMove(fn) { map.addEventListener('region-change-end', fn); },
      resize() {},
      destroy() { map.destroy(); },
    };
  });
}
