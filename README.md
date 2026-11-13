# Rumbo Maps

Put your shops, offices or venues on a map and let people find the nearest one. Rumbo Maps gives Craft a location field, maps and a ready store finder with search and "near me", with no API key needed. Coming from Maps by Ether Creative? Your locations and templates move over in one click: see [Moving from Maps](docs/moving-from-maps.md).

## Editions

| | Lite (free) | Pro (USD 49 per site, then USD 19 a year) |
|---|---|---|
| Location field with address search and a draggable pin | ✓ | ✓ |
| Maps for one location or many (`craft.rumbo.map`) | ✓ | ✓ |
| Move from Maps, and the old values (`.lat`, `.address`, `.city`…) | ✓ | ✓ |
| Distance search and `orderBy('distance')` | | ✓ |
| Store finder (`craft.rumbo.locator`) | | ✓ |
| Static map images, `.embed()` and the old `craft.maps.*` calls | | ✓ |
| Search insights: what people look for and where you have no shop nearby | | ✓ |
| Google, Mapbox, MapTiler, HERE and Apple with your own key | | ✓ |

## Requirements

Craft CMS 5.5 or later, PHP 8.2 or later, and MySQL, MariaDB or PostgreSQL. Rumbo Maps 2.x runs on Craft 6.

## Setup

1. Install Rumbo Maps from the Plugin Store.
2. Add a **Location** field to a section's field layout. Its handle is `location` in the examples below.
3. Out of the box, maps use OpenFreeMap and addresses are looked up with Nominatim, both free and keyless. To use another service, open **Settings → Rumbo Maps** and add its key. Use an environment variable such as `$GOOGLE_MAPS_KEY`, so the key stays out of project config.

## In your templates

### Values

```twig
{% set place = entry.location %}
{% if place.hasPosition() %}
  {{ place.address }}
  {{ place.lat }}, {{ place.lng }} (zoom {{ place.zoom }})
  {{ place.parts.street }} {{ place.parts.number }}, {{ place.parts.postcode }} {{ place.parts.city }}
  {{ place.parts.countryCode }}
  <a href="{{ place.directionsUrl() }}">Get directions</a>
{% endif %}
```

`directionsUrl()` takes `'google'` (the default), `'apple'` or `'osm'`.

### A map

```twig
{{ entry.location.map({ height: 320 }) }}

{# Many entries on one map, with a marker each #}
{{ craft.rumbo.map(craft.entries.section('stores').all(), { field: 'location' }) }}
```

### Distance search (Pro)

```twig
{% set stores = craft.entries
  .section('stores')
  .location({ near: 'Valencia', within: 25, unit: 'km' })
  .orderBy('distance')
  .all() %}
{% for store in stores %}
  {{ store.title }}: {{ store.location.distance|number_format(1) }} km
{% endfor %}
{% if not stores and craft.rumbo.queryError() %}We couldn't find that place.{% endif %}
```

`near` takes a place name or postcode, or `{ lat, lng }`.

### The store finder (Pro)

```twig
{{ craft.rumbo.locator({ section: 'stores' }) }}
```

| Option | Default | |
|---|---|---|
| `section` | all sections | Which entries to show |
| `field` | `location` | The Location field's handle |
| `within` | the settings' default radius | Search radius, 1 to 500 |
| `unit` | the settings' unit | `km` or `mi` |
| `limit` | the settings' results per search | 1 to 100 |
| `filters` | none | Field handles to filter by, shown as buttons above the results |
| `cardFields` | `['phone', 'website']` | Field handles shown on each result |
| `look` | the settings' layout | `side`, `mapFirst` or `list` |

The finder renders its first results on the server, so it works without JavaScript and search engines see them. With JavaScript, searches update the list and map without a page reload.

### Other helpers

| Helper | Returns |
|---|---|
| `craft.rumbo.lookup('Valencia', 'ES')` | The place found for that text, cached, or null |
| `craft.rumbo.distance(a, b, 'km')` | The distance between two locations |
| `craft.rumbo.img(place, { width: 600, height: 300 })` | A static map image URL (Pro) |
| `craft.rumbo.visitorLocation()` | The visitor's rough position from a GeoLite2 file set in the settings, or null (Pro) |
| `craft.rumbo.isPro()` | Whether Pro is active |

## Privacy and cookie banners

Rumbo sets no cookies. Showing a map sends the visitor's IP address to the map provider, as any web map does. If your cookie policy treats that as third-party content, turn on **Ask for consent before loading the map**. Until the visitor agrees, nothing is requested from the provider, and the map loads when they click **Show map** or when your banner sends consent:

```js
// Cookiebot
window.addEventListener('CookiebotOnAccept', () => Cookiebot.consent.marketing && document.dispatchEvent(new Event('rumbo:consent')));
// CookieYes
document.addEventListener('cookieyes_consent_update', e => e.detail.accepted.includes('functional') && document.dispatchEvent(new Event('rumbo:consent')));
// Klaro: in the service's callback
callback: consent => consent && document.dispatchEvent(new Event('rumbo:consent'))
```

You can also set `data-rumbo-consent="granted"` on `<html>` yourself.

Search insights store only the town or postcode searched, rounded to about 11 km, with no IP address, and are deleted after 13 months.

A paragraph you can adapt for your privacy policy:

> Our store finder shows maps from OpenFreeMap, based on OpenStreetMap data. When you open a map, your browser loads map images from OpenFreeMap's servers, which receive your IP address. We keep the place you search for, without anything that identifies you, to learn where customers look for us.

## Content Security Policy

Rumbo uses no inline scripts or handlers. With the default OpenFreeMap tiles, allow:

```
script-src 'self'; worker-src 'self' blob:; child-src blob:;
connect-src 'self' https://tiles.openfreemap.org;
img-src 'self' https://tiles.openfreemap.org data: blob:;
style-src 'self';
```

With another provider, add its hosts as its own documentation says.

## Map providers

OpenFreeMap is free, has no usage limits and needs no key, but comes with no guarantee. If your maps matter to your business, you can switch to Google, Mapbox, MapTiler, HERE or Apple in the settings with your own key, and that provider's terms then apply. Browser keys appear in your pages, so restrict them to your site's address in the provider's console. Server keys are never shown or logged.

Maps based on OpenStreetMap always show its attribution. Your own locations aren't OpenStreetMap data.

Google's terms allow keeping looked-up coordinates for at most 30 days, so with Google as the address service, Rumbo refreshes them in the background. Google lookups are only paired with Google maps.

## Support

Email support@facilo.es, or open an issue at https://github.com/Facilo-es/rumbo-maps/issues.
