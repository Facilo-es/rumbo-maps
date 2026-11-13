// Loads a provider's own SDK from the provider's own URL, once per page, only when that provider is used.
// MapLibre is the exception: it is bundled (rumbo-ml.js) and never comes from a CDN.

const pending = {};

/** Adds <script src=url> once. attrs are extra attributes (data-callback, crossorigin...). */
export function script(url, attrs, doc = document) {
  if (pending[url]) return pending[url];
  pending[url] = new Promise((resolve, reject) => {
    const s = doc.createElement('script');
    s.src = url;
    s.async = true;
    Object.keys(attrs || {}).forEach((k) => s.setAttribute(k, attrs[k]));
    s.onload = () => resolve();
    s.onerror = () => {
      delete pending[url];
      reject(new Error('Could not load ' + new URL(url).host + '. Check the site’s Content-Security-Policy and your connection.'));
    };
    doc.head.appendChild(s);
  });
  return pending[url];
}

/** Adds <link rel=stylesheet> once. */
export function css(url, doc = document) {
  if (doc.querySelector('link[href="' + url + '"]')) return;
  const l = doc.createElement('link');
  l.rel = 'stylesheet';
  l.href = url;
  doc.head.appendChild(l);
}

/** 'es-ES' -> 'es'. */
export function lang2(lang) {
  return String(lang || '').toLowerCase().split(/[-_]/)[0].replace(/[^a-z]/g, '') || '';
}

/** Rejects after ms with the message, unless p settles first. */
export function within(p, ms, message) {
  return new Promise((resolve, reject) => {
    const t = setTimeout(() => reject(new Error(message)), ms);
    p.then((v) => { clearTimeout(t); resolve(v); }, (e) => { clearTimeout(t); reject(e); });
  });
}

/** A deferred: { promise, resolve, reject }, settled once. */
export function deferred() {
  let done = false;
  const d = {};
  d.promise = new Promise((res, rej) => {
    d.resolve = (v) => { if (!done) { done = true; res(v); } };
    d.reject = (e) => { if (!done) { done = true; rej(e instanceof Error ? e : new Error(String(e))); } };
  });
  d.promise.catch(() => {}); // observed later, or never
  return d;
}

/** Wraps a marker element so a provider that anchors at bottom-centre can show it centred. */
export function anchorWrap(el, anchor, doc = document) {
  const w = doc.createElement('div');
  w.className = 'rumbo-at rumbo-at--' + (anchor === 'center' ? 'c' : 'b');
  w.appendChild(el);
  return w;
}

/** Points [[lng,lat]...] -> [w,s,e,n]. */
export function bbox(points) {
  let w = 180, s = 90, e = -180, n = -90;
  points.forEach(([x, y]) => { w = Math.min(w, x); e = Math.max(e, x); s = Math.min(s, y); n = Math.max(n, y); });
  return [w, s, e, n];
}

/** Turns HTML popup text into an element (provider popups that want a node). */
export function htmlEl(html, doc = document) {
  const d = doc.createElement('div');
  d.innerHTML = html;
  return d;
}
