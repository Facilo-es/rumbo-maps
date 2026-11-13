// Rumbo Maps loader. Small on purpose (budget: 10 KB gzipped, see test/loader-size.test.js).
// Finds [data-rumbo-map] and [data-rumbo-locator], waits until they are near the viewport,
// waits for consent when the element asks for it, and only then imports the heavy map chunk.
// No inline scripts: config comes from data- attributes and <script type="application/json">.

const STORE_KEY = 'rumbo:consent';
const DEFAULT_TEXT = {
  text: 'This map is provided by {provider}. Loading it sends your IP address to them.',
  show: 'Show map',
  always: 'Always show maps on this site',
};

export function createRumbo(opts = {}) {
  const win = opts.win || window;
  const doc = opts.doc || win.document;
  const importer = opts.importer || ((url) => import(/* @vite-ignore */ url));
  const scriptSrc = opts.scriptSrc || '';
  let granted = false;
  const waiting = [];
  const cssDone = {};

  function storage() {
    try { return win.localStorage; } catch (e) { return null; }
  }

  function hasConsent() {
    if (granted || win.RumboConsent === true) return true;
    if (doc.documentElement.getAttribute('data-rumbo-consent') === 'granted') return true;
    const s = storage();
    return !!(s && s.getItem(STORE_KEY) === 'always');
  }

  function consent(always) {
    granted = true;
    if (always) {
      const s = storage();
      if (s) try { s.setItem(STORE_KEY, 'always'); } catch (e) { /* private mode */ }
    }
    waiting.splice(0).forEach((fn) => fn());
  }

  const onEvent = () => consent(false);
  doc.addEventListener('rumbo:consent', onEvent);
  win.addEventListener('rumbo:consent', onEvent);

  /** Folder URL the dist files live in, robust to Craft's published asset URLs. */
  function baseFor(el) {
    const tag = doc.querySelector('script[data-rumbo-base]');
    let src = (el && el.getAttribute('data-rumbo-src')) || (tag && (tag.getAttribute('data-rumbo-base') || tag.src)) || scriptSrc || 'dist/';
    let url = new URL(src, doc.baseURI);
    if (/\.m?js$/.test(url.pathname)) url = new URL('./', url);
    else if (!/\/$/.test(url.pathname)) url = new URL(url.pathname + '/' + url.search, url);
    return url;
  }

  function load(name, el) {
    return importer(new URL('rumbo-' + name + '.js', baseFor(el)).href);
  }

  /** Adds a dist stylesheet once, unless the page already links it (the plugin normally does). */
  function css(name, el) {
    const href = new URL('rumbo-' + name + '.css', baseFor(el)).href;
    if (cssDone[name] || doc.querySelector('link[href*="rumbo-' + name + '.css"]')) return;
    cssDone[name] = 1;
    const link = doc.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    doc.head.appendChild(link);
  }

  function loadMapModule(el) {
    css('map', el);
    return load('map', el);
  }

  function nearView(el) {
    return new Promise((resolve) => {
      const IO = win.IntersectionObserver;
      if (!IO) return resolve();
      const io = new IO((entries) => {
        if (entries.some((e) => e.isIntersecting || e.intersectionRatio > 0)) {
          io.disconnect();
          resolve();
        }
      }, { rootMargin: '200px' });
      io.observe(el);
    });
  }

  function placeholder(slot, owner) {
    let ph = slot.querySelector('[data-rumbo-placeholder]');
    if (!ph) {
      const t = DEFAULT_TEXT;
      const provider = owner.getAttribute('data-rumbo-provider') || 'OpenFreeMap';
      ph = doc.createElement('div');
      ph.className = 'rumbo-consent';
      ph.setAttribute('data-rumbo-placeholder', '');
      const stat = owner.getAttribute('data-rumbo-static');
      if (stat) {
        const img = doc.createElement('img');
        img.className = 'rumbo-consent__img';
        img.alt = '';
        img.src = stat;
        ph.appendChild(img);
      }
      const box = doc.createElement('div');
      box.className = 'rumbo-consent__box';
      const p = doc.createElement('p');
      p.textContent = t.text.replace(/\{provider\}/g, provider);
      const show = doc.createElement('button');
      show.type = 'button';
      show.className = 'rumbo-btn';
      show.setAttribute('data-rumbo-show', '');
      show.textContent = t.show;
      const always = doc.createElement('button');
      always.type = 'button';
      always.className = 'rumbo-link';
      always.setAttribute('data-rumbo-always', '');
      always.textContent = t.always;
      box.append(p, show, always);
      ph.appendChild(box);
      slot.appendChild(ph);
    }
    ph.hidden = false;
    return ph;
  }

  /** Resolves when the slot is near the viewport and, if required, the visitor agreed. */
  function gate(slot, owner) {
    owner = owner || slot;
    return nearView(slot).then(() => {
      const required = (owner.getAttribute('data-rumbo-consent') || slot.getAttribute('data-rumbo-consent')) === 'required';
      if (!required || hasConsent()) return;
      const ph = placeholder(slot, owner);
      slot.classList.add('rumbo-waiting');
      return new Promise((resolve) => {
        const done = () => {
          ph.hidden = true;
          slot.classList.remove('rumbo-waiting');
          resolve();
        };
        waiting.push(done);
        ph.addEventListener('click', (e) => {
          const b = e.target.closest && e.target.closest('[data-rumbo-show],[data-rumbo-always]');
          if (!b) return;
          if (b.hasAttribute('data-rumbo-always')) return consent(true);
          const i = waiting.indexOf(done);
          if (i > -1) waiting.splice(i, 1);
          done();
        });
      });
    });
  }

  function fail(el, err) {
    el.classList.add('rumbo-failed');
    if (win.console) win.console.warn('[Rumbo Maps]', err);
  }

  function scan(root) {
    root = root || doc;
    const all = [];
    root.querySelectorAll('[data-rumbo-locator]:not([data-rumbo-ready])').forEach((el) => {
      el.setAttribute('data-rumbo-ready', '');
      css('locator', el);
      all.push(load('locator', el).then((m) => m.mountLocator(el, {
        loadMap: (slot) => gate(slot, el).then(() => loadMapModule(el)),
      })).catch((e) => fail(el, e)));
    });
    root.querySelectorAll('[data-rumbo-map]:not([data-rumbo-ready])').forEach((el) => {
      el.setAttribute('data-rumbo-ready', '');
      css('locator', el);
      all.push(gate(el, el).then(() => loadMapModule(el)).then((m) => m.mountMap(el)).catch((e) => fail(el, e)));
    });
    return Promise.all(all);
  }

  // Sprig / htmx swap new markup in: enhance it too.
  doc.addEventListener('htmx:load', (e) => e.target && e.target.querySelectorAll && scan(e.target));

  return { scan, consent, hasConsent, gate, baseFor };
}
