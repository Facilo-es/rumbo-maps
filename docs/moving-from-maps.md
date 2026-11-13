# Moving from Maps

Rumbo Maps copies every location and field setting from **Maps by Ether Creative** in one go. Your field handles stay the same, so your templates keep working. Rumbo Maps isn't made by or linked to Ether Creative: we only read its database rows to move them.

> **Keep Maps installed until the move is done.** Uninstalling Maps deletes its `maps` table, and with it every location. Rumbo reads that table straight from the database and never runs Maps' own code, so the move works even if Maps no longer loads on your Craft version.

## Before you start

1. Back up your database.
2. Install Rumbo Maps from the Plugin Store, with Maps still installed. Lite is free and moves everything.
3. Open **Utilities → Move from Maps**. The table lists each Maps field, where it's used, how many locations it holds and which settings will come across.

## 1. Check your templates

Press **Check my templates**. Rumbo reads (never runs) every file in `templates/`, plus any extra folders listed in `extraTemplateRoots` in `config/rumbo-maps.php`, and lists each old call with its file and line:

| Badge | Meaning |
|---|---|
| **Works in Lite** | Keeps working on the free edition: `.lat`, `.lng`, `.zoom`, `.address`, `.parts.*`, `.streetAddress` and the old part names such as `.city` and `.postcode` |
| **Needs Pro** | Keeps working with Rumbo Maps Pro: distance search (`location`, `radius`, `coordinate`, `orderBy('distance')`), `.distance`, `.embed()`, `.img()`, `.imgSrcSet()` and `craft.maps.*` |
| **Change needed** | A call Rumbo doesn't copy. The list gives the replacement, for example `craft.maps.mapToken` isn't needed because Rumbo maps need no token |

In Lite, a Pro-only call never breaks a page. It returns an empty result or an empty string, writes one warning a day to the Craft log and shows a notice in the control panel. Distance queries in Lite ignore the location filter and keep their normal order.

## 2. Try it first

Press **Try it first (nothing changes)**, or run:

```
php craft rumbo-maps/move-from-maps --dry-run
```

You get the report without anything being saved: fields, locations per field, empty maps that would be skipped and settings that can't come across. The dry run counts live entries. The real move also copies drafts and revisions.

## 3. Move

Press **Move my locations**, or run:

```
php craft rumbo-maps/move-from-maps
php craft rumbo-maps/move-from-maps --field=myMap   # one field only
```

For each field, Rumbo:

- switches the field type to Rumbo Maps, keeping its handle, name, instructions and field layouts;
- copies every location (each element, site and field) in batches of 500 as a queue job, which picks up where it left off if it's cut off;
- compares the counts with the old table. If they differ, that field is rolled back and the report says why.

The move is a normal field type change in project config. When you apply project config on production, the data there is converted the same way and the report goes to the Craft log.

## 4. After the move

1. Look over your pages.
2. Uninstall Maps.
3. You're ready for Craft 6: Rumbo Maps 2.x runs on it with the same field data.

## What comes across

| Maps | Rumbo Maps |
|---|---|
| Owner, site and field | The same element, site and field |
| `lat`, `lng`, `zoom`, `address` | The same |
| Parts: number, address, city, postcode, county, state, country | `number`, `street`, `city`, `postcode`, `county`, `state`, `country`. `countryCode` is filled from the country name, and left empty if it can't be matched |
| Parts in the older 2019 format | Converted the same way |
| planet, system, arm, galaxy, group, cluster, supercluster | Dropped |
| Default location and zoom, editor height, hide coordinates | The same |
| Country restriction | The field's country restriction |
| Boundary restriction | The search bias area |
| Geocoding service (Nominatim, Google, Mapbox, HERE, Apple, MapTiler) | The same service. Add its key in the plugin settings, next to **Address lookups** |
| Map tiles | OpenFreeMap: dark styles become Dark, light and grey styles Positron, street styles Bright, and anything else Liberty. Old Carto, Wikimedia and HERE tiles have no exact match, and the report says which style they got |
| what3words grid and field, search type restriction, "Use current location" in the editor | Not in Rumbo. The report lists them |

Empty maps, and rows with no latitude or longitude, are skipped and listed in the report.

## Distances

Rumbo works out distances on the mean Earth radius (6,371.0088 km). Maps used a slightly smaller figure, so a distance shown with one decimal can differ by about 0.15%, for example 24.6 km where Maps showed 24.7 km. Which locations fall inside a radius can change only for one right on its edge.

## Old calls are a bridge

The old Twig calls are kept in Rumbo Maps 1.x and 2.x so your site keeps working, and `craft.maps` is only registered while Maps itself isn't installed. For new templates, use Rumbo's own API, described in [the guide](README.md).
