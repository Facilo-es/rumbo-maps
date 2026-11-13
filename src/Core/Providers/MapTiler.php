<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

use facilo\rumbo\Core\AddressParts;
use facilo\rumbo\Core\Ports\HttpClient;

/**
 * MapTiler Geocoding API.
 */
final class MapTiler extends HttpGeocoder
{
    public const BASE_URL = 'https://api.maptiler.com/geocoding';
    protected const LABEL = 'MapTiler';
    protected const API_HINT = 'Geocoding';

    public function __construct(HttpClient $http, private readonly string $key)
    {
        parent::__construct($http, $key);
    }

    public function name(): string
    {
        return 'maptiler';
    }

    public function lookup(string $query, GeoOptions $o): array
    {
        $params = ['limit' => min(10, $o->limit)];
        if (($c = $o->biasCentre()) !== null) {
            $params['proximity'] = self::num($c['lng']) . ',' . self::num($c['lat']);
        }

        return $this->call(rawurlencode($query), $params, $o);
    }

    public function reverse(float $lat, float $lng, GeoOptions $o): ?GeoResult
    {
        return $this->call(self::num($lng) . ',' . self::num($lat), ['limit' => 1], $o)[0] ?? null;
    }

    /** @return list<GeoResult> */
    private function call(string $path, array $params, GeoOptions $o): array
    {
        if ($o->countries !== []) {
            $params['country'] = strtolower(implode(',', $o->countries));
        }
        if ($o->language !== '') {
            $params['language'] = $o->baseLanguage();
        }
        $params['key'] = $this->key;

        $data = $this->getJson(self::BASE_URL . '/' . $path . '.json?' . http_build_query($params));
        $out = [];
        foreach ((array) ($data['features'] ?? []) as $f) {
            $r = is_array($f) ? $this->parse($f) : null;
            if ($r !== null) {
                $out[] = $r;
            }
        }

        return $out;
    }

    private function parse(array $f): ?GeoResult
    {
        $byType = [];
        $countryCode = null;
        foreach ((array) ($f['context'] ?? []) as $c) {
            $type = strtok((string) ($c['id'] ?? ''), '.');
            $byType[$type] ??= $c['text'] ?? null;
            $countryCode ??= $c['country_code'] ?? null;
        }
        $type = strtok((string) ($f['id'] ?? ''), '.');
        $p = is_array($f['properties'] ?? null) ? $f['properties'] : [];
        $text = $f['text'] ?? null;
        $isAddress = in_array($type, ['address', 'road', 'street'], true) || in_array('address', (array) ($f['place_type'] ?? []), true);

        $parts = new AddressParts(
            $f['address'] ?? null,
            $isAddress ? $text : self::pick($byType, 'street', 'road'),
            self::pick($byType, 'municipality', 'place', 'locality') ?? (in_array($type, ['municipality', 'place'], true) ? $text : null),
            self::pick($byType, 'postal_code'),
            self::pick($byType, 'county', 'subregion'),
            self::pick($byType, 'region'),
            self::pick($byType, 'country'),
            self::countryCode($p['country_code'] ?? $countryCode, $byType['country'] ?? null),
        );
        $center = $f['center'] ?? ($f['geometry']['coordinates'] ?? []);

        return $this->result($center[1] ?? null, $center[0] ?? null, (string) ($f['place_name'] ?? ''), $parts);
    }
}
