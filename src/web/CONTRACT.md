# Rumbo Maps front end: contract with the PHP side

Everything in `dist/` sits in one flat folder with stable names (no hashes). Publish the whole folder as one asset bundle.

| File | Where | How |
|---|---|---|
| `rumbo-loader.js` | front end, any page with a map or locator | `<script src=".../rumbo-loader.js" defer>` (classic script, never inline) |
| `rumbo-locator.css` | front end | `<link rel="stylesheet">` in `<head>` (the loader adds it too if missing, but linking it avoids a flash) |
| `rumbo-map.css`, `rumbo-map.js`, `rumbo-locator.js`, `rumbo-ml.js`, `rumbo-util.js`, `maplibre-gl-csp-worker.js` | loaded by the loader on demand | nothing to do |
| `fonts/geist.woff2` | used by `rumbo-locator.css` | nothing to do |
| `rumbo-field.js` + `rumbo-field.css` | control panel, Location field | register both (`registerJsFile`, `registerCssFile`); `rumbo-field.js` imports `rumbo-field-app.js` from its own folder |

The loader finds its folder from its own `src`. If the files are served from somewhere else, put `data-rumbo-base="https://cdn.example/rumbo/"` on the script tag, or `data-rumbo-src` on one element.

Globals: `window.Rumbo = {scan(root), consent(always), hasConsent(), gate(), baseFor()}`. Call `Rumbo.scan(el)` after inserting markup yourself (htmx/Sprig `htmx:load` is handled). In the CP, `rumbo-field.js` sets `window.RumboField = true` and the field app watches the DOM itself (new Matrix blocks, slideouts).

## CSP

- `script-src 'self'` (or the asset host), `worker-src 'self' blob:`, `child-src blob:` (old Safari).
- `connect-src https://tiles.openfreemap.org` (+ Pro provider hosts) and the site itself for the locator endpoint.
- `img-src https://tiles.openfreemap.org data: blob:`.
- `style-src 'self'`. No inline styles in markup are required: heights and colours are set through CSSOM. `accentColor` is only set through JS.
- MapLibre is the CSP build: no `eval`, no blob workers.

## Map styles

`style` / `mapStyle` is one of `liberty`, `bright`, `positron` (default), `dark`, `fiord`, which become `https://tiles.openfreemap.org/styles/<name>`, or any `https://` style URL (Pro providers: pass their full style URL with the key). All five OpenFreeMap URLs answered 200 on 2026-11-07. Attribution is always shown, never collapsed.

## Locator: `rumbo-maps/_locator/locator` (Twig)

Overrides: copy `_form`, `_card` to `templates/_rumbo/locator/` in the project.

| Variable | Type | Default | Notes |
|---|---|---|---|
| `id` | string | random | element id |
| `look` | `side` \| `first` \| `list` | `side` | Side by side, Map first, List only |
| `config` | hash | `{}` | the JS blob; keys below are merged over it |
| `endpoint` | url | `config.endpoint` or `actionUrl('rumbo-maps/locator/search')` | |
| `token` | string | `''` | signed section/field/filters, sent as `l` by the form and every fetch |
| `q` | string | request `q` | |
| `results` (alias `initialResults`) | array of result | `config.results` | server render without JS |
| `total` | int | `results|length` | |
| `origin` | `{label?, lat, lng}` \| null | `config.origin` | |
| `nearest` | result \| null | null | shown when `results` is empty and `q` set |
| `within` | number \| null | null | radius actually used |
| `unit` | `km` \| `mi` | `config.unit` / `km` | |
| `radiusOptions` | number[] | `[5,10,25,50,100]` | |
| `defaultRadius` | number | request `radius`, `within`, 25 | |
| `filters` | `[{handle,label,options:[{value,label,selected?}]}]` | `[]` | chips; request `filters[handle][]` also marks them |
| `heading`, `intro` | string | none | 40px heading; no heading means cards use `h2` |
| `cardHeading` | `h2`..`h6` | `h3` with heading, else `h2` | |
| `mapStyle` | style name or url | `config.mapStyle` / `config.style` / `positron` | |
| `consentRequired` | bool | false | privacy mode |
| `staticUrl` | url | none | image behind the privacy placeholder |
| `provider` | string | `OpenFreeMap` | name in the privacy text |
| `accentColor` | `#hex` | none | via JS (CSSOM) |
| `corners` | `round` \| `square` | `round` | |
| `fontInherit` | bool | false | use the site font instead of Geist |
| `error` | string | none | message shown (no-JS render) |
| `strings` | hash | none | override any JS string key below |

JS config blob keys (`<script type="application/json" data-rumbo-config>`): `lang, look, unit, endpoint, limit, site, results, origin, mapStyle|style, cardHeading, accentColor, markerColor, center:[lng,lat], zoom, strings`.

String keys: `placeholder, search, useLocation, directions, details, countMany, countOne, countManyYou, countOneYou, countManyAll, countOneAll, awayKm, awayMi, km, mi, none, noneAtAll, locErr, findErr, failed, cluster`. Twig translates them all with `|t('rumbo-maps')`.

### Search request

`GET {endpoint}?l=<token>&q=<text>&radius=<n>&unit=km|mi&filters[<handle>][]=<value>...&lat=<4dp>&lng=<4dp>&limit=<n>&site=<handle>` with `Accept: application/json`, same-origin credentials. `q` is omitted when using the visitor's location; `lat/lng` are only sent then. Validation on the server: radius 0–500, limit 1–100, q 2–120 chars.

### Search response

```json
{"ok": true,
 "results": [{"id": 12, "lat": 40.41, "lng": -3.70, "title": "Grano Malasaña", "address": "Calle del Pez 12, Madrid",
              "distance": 1.24, "directionsUrl": "https://...", "url": "https://...", "fields": {"hours": "Open until 20:00"}}],
 "total": 7, "unit": "km", "within": 25, "origin": {"label": "Madrid", "lat": 40.4168, "lng": -3.7038},
 "nearest": null, "message": null}
```

- `distance` is a number in `unit`; the front end formats it (1 decimal under 10, none from 10).
- `fields` values that are strings or numbers are shown as a meta line; the first one is highlighted in the accent colour.
- `directionsUrl` is optional (fallback: OpenStreetMap directions).
- Empty `results` + `nearest` gives "Nothing within … The nearest is …" and shows that one card.
- `message` (optional) replaces the empty-state text.

Errors: `{"ok": false, "code": "notFound"|"invalid"|"rateLimited", "errors": ["Text for the visitor"]}`. The first string is shown; without one, `notFound` shows the spec's "We couldn't find that place…" and anything else (or a network error) shows "Search isn't working right now…".

The URL bar is kept in sync with `history.replaceState` (q, radius, unit, filters, lat/lng rounded to 3 decimals), so the same URL renders the same results server-side.

## Map: `rumbo-maps/_map/map` (Twig)

| Variable | Type | Notes |
|---|---|---|
| `id` | string | random default |
| `config` | hash | `{style, center:[lng,lat], zoom, markers:[{id,lat,lng,title,address,url,directionsUrl,html,icon}], cluster, fitBounds, scrollZoom, markerColor, strings}` |
| `height` | int px | default 400, set via `data-rumbo-height` |
| `consentRequired` | bool | privacy mode |
| `staticUrl` | url | shown in the no-JS fallback and behind the privacy placeholder |
| `attributionNote` | string | small text under the no-JS list |
| `provider`, `corners`, `fontInherit` | as above | |

`markers[].html` is trusted popup HTML (author content, Twig-escaped by the server); everything else is escaped by the JS. Clustering switches on automatically above 50 markers, or with `cluster: true`.

## Privacy mode

`data-rumbo-consent="required"` on the locator or map. Before consent nothing is requested from the map provider (no style, no tiles, no MapLibre chunk). The map loads when the visitor clicks "Show map", or when any of these is true: `document.dispatchEvent(new Event('rumbo:consent'))` (or on `window`), `window.RumboConsent = true` before the loader runs, `<html data-rumbo-consent="granted">`, or `localStorage['rumbo:consent'] === 'always'` (set by "Always show maps on this site"). Cookie banner snippet:

```js
// Cookiebot
window.addEventListener('CookiebotOnAccept', () => Cookiebot.consent.marketing && document.dispatchEvent(new Event('rumbo:consent')));
```

## Location field (control panel)

```html
<div class="rumbo-field" data-rumbo-field>
  <script type="application/json" data-rumbo-field-config>{...}</script>
  <!-- the server's inputs, names kept: fields[handle][lat|lng|zoom|address|source] and fields[handle][parts][number|street|city|postcode|county|state|country|countryCode] -->
</div>
```

Config: `{name:"fields[handle]", value:{lat,lng,zoom,address,parts:{...},source}, defaultCenter:[lng,lat], defaultZoom, height, showLatLng, countries:["es"], bias:[lng,lat]|null, style, suggestUrl, reverseUrl, strings:{searchPlaceholder, updateFromPin, clear, street, city, postcode, county, state, country, latitude, longitude, notFound, providerError}}`.

- `suggestUrl`: `GET ?q=&lang=&countries=es,pt&bias=lng,lat` (after 3 characters and 350 ms).
- `reverseUrl`: `GET ?lat=&lng=&lang=`.
- Both answer `{ok:true, results:[{lat,lng,address,parts,source}]}` or `{ok:false, error:"text"}`. The error text is shown under the search box; an empty `results` shows `notFound`.
- Picking a suggestion fills address + parts, moves the pin, sets `source` to the provider. Dragging the pin sets lat/lng only and `source=manual`. "Update address from pin" fills address + parts from `reverseUrl`. Clear empties every input.
- Works with several fields per page and inside Matrix (MutationObserver).
