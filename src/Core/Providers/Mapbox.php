<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

use facilo\rumbo\Core\AddressParts;
use facilo\rumbo\Core\Ports\HttpClient;

/**
 * Mapbox Geocoding v6. Stored results need permanent=true (billed as permanent geocoding).
 */
final class Mapbox extends HttpGeocoder
{
    public const BASE_URL = 'https://api.mapbox.com/search/geocode/v6';
    protected const LABEL = 'Mapbox';
    protected const API_HINT = 'the Geocoding API (permanent)';

    public function __construct(HttpClient $http, private readonly string $token, private readonly bool $permanent = true)
    {
        parent::__construct($http, $token);
    }

    public function name(): string
    {
        return 'mapbox';
    }

    public function lookup(string $query, GeoOptions $o): array
    {
        $params = ['q' => $query, 'limit' => min(10, $o->limit)];
        if (($c = $o->biasCentre()) !== null) {
            $params['proximity'] = self::num($c['lng']) . ',' . self::num($c['lat']);
        }

        return $this->call('/forward', $params, $o);
    }

    public function reverse(float $lat, float $lng, GeoOptions $o): ?GeoResult
    {
        return $this->call('/reverse', ['longitude' => self::num($lng), 'latitude' => self::num($lat), 'limit' => 1], $o)[0] ?? null;
    }

    /** @return list<GeoResult> */
    private function call(string $path, array $params, GeoOptions $o): array
    {
        if ($o->countries !== []) {
            $params['country'] = strtolower(implode(',', $o->countries));
        }
        if ($o->language !== '') {
            $params['language'] = $o->language;
        }
        if ($this->permanent) {
            $params['permanent'] = 'true';
        }
        $params['access_token'] = $this->token;

        $data = $this->getJson(self::BASE_URL . $path . '?' . http_build_query($params));
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
        $p = is_array($f['properties'] ?? null) ? $f['properties'] : [];
        $ctx = is_array($p['context'] ?? null) ? $p['context'] : [];
        $name = static fn(string $k) => is_array($ctx[$k] ?? null) ? self::pick($ctx[$k], 'name') : null;
        $address = is_array($ctx['address'] ?? null) ? $ctx['address'] : [];
        $country = is_array($ctx['country'] ?? null) ? $ctx['country'] : [];

        $parts = new AddressParts(
            self::pick($address, 'address_number'),
            self::pick($address, 'street_name') ?? $name('street'),
            $name('place') ?? $name('locality'),
            $name('postcode'),
            $name('district'),
            $name('region'),
            self::pick($country, 'name'),
            self::countryCode($country['country_code'] ?? null, $country['name'] ?? null),
        );
        $coords = is_array($p['coordinates'] ?? null) ? $p['coordinates'] : [];
        $geom = $f['geometry']['coordinates'] ?? [];

        return $this->result(
            $coords['latitude'] ?? ($geom[1] ?? null),
            $coords['longitude'] ?? ($geom[0] ?? null),
            (string) ($p['full_address'] ?? $p['place_formatted'] ?? ''),
            $parts,
        );
    }
}
