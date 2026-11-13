<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

/**
 * Converts data from "Maps" by Ether Creative to Rumbo's format, from its data format only
 * (spec section 6, "Move from Maps mapping").
 */
final class EtherMapping
{
    /** Rumbo part => old part names, current format first, then the 2019 legacy format. */
    private const PART_KEYS = [
        'number' => ['number', 'street_number'],
        'street' => ['address', 'route'],
        'city' => ['city', 'locality', 'postal_town'],
        'postcode' => ['postcode', 'postal_code'],
        'county' => ['county', 'administrative_area_level_2'],
        'state' => ['state', 'administrative_area_level_1'],
        'country' => ['country'],
    ];

    private const DEFAULT_LAT = '40.416800000';
    private const DEFAULT_LNG = '-3.703800000';
    private const DEFAULT_ZOOM = 15;
    private const DEFAULT_EDITOR_HEIGHT = 400;

    private const STYLE_LABELS = [
        'liberty' => 'OpenFreeMap Liberty',
        'bright' => 'OpenFreeMap Bright',
        'positron' => 'OpenFreeMap Positron',
        'dark' => 'OpenFreeMap Dark',
    ];

    private const BRANDS = [
        'carto' => 'Carto', 'here' => 'HERE', 'openstreetmap' => 'OpenStreetMap', 'mapbox' => 'Mapbox',
        'maptiler' => 'MapTiler', 'google' => 'Google', 'wikimedia' => 'Wikimedia', 'apple' => 'Apple',
    ];

    /** Geocoding services Rumbo has a driver for, old name => Rumbo provider. */
    private const PROVIDERS = [
        'nominatim' => 'nominatim', 'google' => 'google', 'mapbox' => 'mapbox',
        'here' => 'here', 'apple' => 'apple', 'maptiler' => 'maptiler',
    ];

    /**
     * Converts one old location (the {{%maps}} row merged with the field's content JSON).
     *
     * @return array{ok:true,row:array}|array{ok:false,reason:'empty'|'missing-lat-lng'}
     */
    public static function convert(array $oldRow): array
    {
        $parts = self::parts($oldRow['parts'] ?? null);
        $address = is_scalar($oldRow['address'] ?? null) ? trim((string) $oldRow['address']) : '';
        $lat = self::decimal($oldRow['lat'] ?? null, 90);
        $lng = self::decimal($oldRow['lng'] ?? null, 180);

        if ($lat === null || $lng === null) {
            $blank = self::isBlank($oldRow['lat'] ?? null) && self::isBlank($oldRow['lng'] ?? null);
            $reason = $blank && $address === '' && $parts->isEmpty() ? 'empty' : 'missing-lat-lng';

            return ['ok' => false, 'reason' => $reason];
        }

        $zoom = is_numeric($oldRow['zoom'] ?? null) ? (int) $oldRow['zoom'] : self::DEFAULT_ZOOM;

        return [
            'ok' => true,
            'row' => [
                'lat' => $lat,
                'lng' => $lng,
                'zoom' => max(0, min(22, $zoom)),
                'address' => $address !== '' ? $address : $parts->oneLine(),
                'parts' => $parts->toArray(),
                'countryCode' => $parts->countryCode,
                'source' => 'import',
            ],
        ];
    }

    /** Maps old address parts (array or JSON, current or legacy format) to Rumbo parts. */
    public static function parts(mixed $old): AddressParts
    {
        if (is_string($old)) {
            $old = json_decode($old, true);
        }
        if (!is_array($old)) {
            return new AddressParts();
        }

        $values = [];
        foreach (self::PART_KEYS as $rumbo => $oldKeys) {
            $values[$rumbo] = self::firstString($old, $oldKeys) ?? self::firstString($old, array_map(
                static fn($k) => $k . '_short',
                $oldKeys,
            ));
        }

        $short = self::firstString($old, ['country_short']);
        if ($short !== null && preg_match('/^[A-Za-z]{2}$/', $short)) {
            $values['countryCode'] = strtoupper($short);
            if ($values['country'] === $short) {
                $values['country'] = null;
            }
        } elseif ($values['country'] !== null) {
            $values['countryCode'] = Countries::codeFor($values['country']);
        }

        return AddressParts::fromArray($values);
    }

    /**
     * Maps the old field's settings to Rumbo field settings.
     *
     * @return array{settings:array,notCarried:list<string>}
     */
    public static function convertSettings(array $old): array
    {
        $notCarried = [];

        $showLatLng = isset($old['showLatLng'])
            ? self::bool($old['showLatLng'])
            : !self::bool($old['hideLatLng'] ?? false);

        $countries = self::countries($old['countryRestriction'] ?? null);
        if ($countries === []) {
            $countries = self::countries($old['country'] ?? null);
        }

        $settings = [
            'defaultLat' => is_numeric($old['lat'] ?? null) ? (float) $old['lat'] : (float) self::DEFAULT_LAT,
            'defaultLng' => is_numeric($old['lng'] ?? null) ? (float) $old['lng'] : (float) self::DEFAULT_LNG,
            'defaultZoom' => is_numeric($old['zoom'] ?? null) ? (int) $old['zoom'] : self::DEFAULT_ZOOM,
            'editorHeight' => is_numeric($old['height'] ?? null) && (int) $old['height'] > 0
                ? (int) $old['height']
                : self::DEFAULT_EDITOR_HEIGHT,
            'showLatLng' => $showLatLng,
            'countries' => $countries,
            'searchBias' => self::boundary($old),
            'mapStyle' => 'liberty',
            'serviceRadius' => false,
        ];

        $reasons = [
            'showW3WGrid' => 'what3words grid → not in Rumbo',
            'showW3WField' => 'what3words field → not in Rumbo',
            'typeRestriction' => 'Search type restriction → Rumbo searches all address types',
            'showCurrentLocation' => '"Use current location" button in the editor → not in Rumbo',
        ];
        foreach ($reasons as $key => $reason) {
            if (self::bool($old[$key] ?? false)) {
                $notCarried[] = $reason;
            }
        }

        return ['settings' => $settings, 'notCarried' => $notCarried];
    }

    /**
     * Maps the old plugin's settings (map tiles, geocoding service and their tokens).
     * Token and key settings are kept as they are, including environment variable names.
     *
     * @return array{settings:array,notCarried:list<string>}
     */
    public static function convertPluginSettings(array $old): array
    {
        $notCarried = [];
        $settings = [];

        $tiles = is_string($old['mapTiles'] ?? null) ? trim($old['mapTiles']) : '';
        $settings['mapStyle'] = self::styleForTiles($tiles);
        if ($tiles !== '') {
            $notCarried[] = sprintf(
                'Map tiles “%s” → %s',
                self::tilesLabel($tiles),
                self::STYLE_LABELS[$settings['mapStyle']],
            );
        }

        $service = is_string($old['geoService'] ?? null) ? strtolower(trim($old['geoService'])) : '';
        $settings['provider'] = self::PROVIDERS[$service] ?? 'nominatim';
        if ($service !== '' && !isset(self::PROVIDERS[$service])) {
            $notCarried[] = sprintf('Address service “%s” → not in Rumbo, OpenStreetMap (Nominatim) used', $old['geoService']);
        }

        foreach ($old as $key => $value) {
            if (is_string($key) && preg_match('/(token|key)$/i', $key)) {
                $settings[$key] = $value;
            }
        }

        return ['settings' => $settings, 'notCarried' => $notCarried];
    }

    /** Nearest OpenFreeMap style for an old tile set name. */
    public static function styleForTiles(string $tiles): string
    {
        $t = strtolower($tiles);

        return match (true) {
            (bool) preg_match('/dark|night|black/', $t) => 'dark',
            (bool) preg_match('/positron|light|gr[ae]y|silver/', $t) => 'positron',
            (bool) preg_match('/streets|outdoors|bright/', $t) => 'bright',
            default => 'liberty',
        };
    }

    private static function tilesLabel(string $tiles): string
    {
        $words = preg_split('/[.\-_\s]+/', $tiles, -1, PREG_SPLIT_NO_EMPTY) ?: [$tiles];

        return implode(' ', array_map(
            static fn($w) => self::BRANDS[strtolower($w)] ?? ucfirst($w),
            $words,
        ));
    }

    /** bbox from the four boundaryRestriction* values, or null unless all four are set. */
    private static function boundary(array $old): ?array
    {
        $keys = ['boundaryRestrictionNELat', 'boundaryRestrictionNELng', 'boundaryRestrictionSWLat', 'boundaryRestrictionSWLng'];
        foreach ($keys as $key) {
            if (!is_numeric($old[$key] ?? null)) {
                return null;
            }
        }

        $neLat = (float) $old['boundaryRestrictionNELat'];
        $neLng = (float) $old['boundaryRestrictionNELng'];
        $swLat = (float) $old['boundaryRestrictionSWLat'];
        $swLng = (float) $old['boundaryRestrictionSWLng'];
        if (!Geo::isValid($neLat, $neLng) || !Geo::isValid($swLat, $swLng)) {
            return null;
        }

        return [
            'minLat' => min($neLat, $swLat),
            'minLng' => $swLng,
            'maxLat' => max($neLat, $swLat),
            'maxLng' => $neLng,
        ];
    }

    /** @return list<string> */
    private static function countries(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $codes = [];
        foreach ($value as $item) {
            if (is_string($item) && ($code = Countries::codeFor($item)) !== null) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /** A decimal string with exactly 9 decimals, as stored, or null when blank or out of range. */
    private static function decimal(mixed $value, int $max): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = sprintf('%.9F', $value);
        }
        if (!is_string($value) || !preg_match('/^\s*([+-]?)(\d+)(?:\.(\d*))?\s*$/', $value, $m)) {
            return null;
        }

        $decimals = $m[3] ?? '';
        if (strlen($decimals) > 9) {
            $out = sprintf('%.9F', (float) $value);
        } else {
            $int = ltrim($m[2], '0');
            $out = ($m[1] === '-' ? '-' : '') . ($int === '' ? '0' : $int) . '.' . str_pad($decimals, 9, '0');
        }

        return abs((float) $out) <= $max ? $out : null;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $value;
    }

    private static function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $v = $data[$key] ?? null;
            if (is_int($v) || is_float($v)) {
                return (string) $v;
            }
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }
}
