<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

use facilo\rumbo\Core\AddressParts;
use facilo\rumbo\Core\Countries;
use facilo\rumbo\Core\Ports\HttpClient;

/**
 * HERE Geocoding & Search v7, authenticated with an API key (apiKey=) only.
 */
final class Here extends HttpGeocoder
{
    public const GEOCODE_URL = 'https://geocode.search.hereapi.com/v1/geocode';
    public const REVGEOCODE_URL = 'https://revgeocode.search.hereapi.com/v1/revgeocode';
    protected const LABEL = 'HERE';
    protected const API_HINT = 'Geocoding & Search';

    public function __construct(HttpClient $http, private readonly string $apiKey)
    {
        parent::__construct($http, $apiKey);
    }

    public function name(): string
    {
        return 'here';
    }

    public function lookup(string $query, GeoOptions $o): array
    {
        $params = ['q' => $query, 'limit' => min(20, $o->limit)];
        if ($o->countries !== []) {
            $alpha3 = array_filter(array_map(Countries::alpha3(...), $o->countries));
            if ($alpha3 !== []) {
                $params['in'] = 'countryCode:' . implode(',', $alpha3);
            }
        }
        if (($c = $o->biasCentre()) !== null) {
            $params['at'] = self::num($c['lat']) . ',' . self::num($c['lng']);
        }

        return self::inCountries($this->call(self::GEOCODE_URL, $params, $o), $o);
    }

    public function reverse(float $lat, float $lng, GeoOptions $o): ?GeoResult
    {
        return $this->call(self::REVGEOCODE_URL, ['at' => self::num($lat) . ',' . self::num($lng), 'limit' => 1], $o)[0] ?? null;
    }

    /** @return list<GeoResult> */
    private function call(string $url, array $params, GeoOptions $o): array
    {
        if ($o->language !== '') {
            $params['lang'] = $o->language;
        }
        $params['apiKey'] = $this->apiKey;

        $data = $this->getJson($url . '?' . http_build_query($params));
        $out = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            $r = is_array($item) ? $this->parse($item) : null;
            if ($r !== null) {
                $out[] = $r;
            }
        }

        return $out;
    }

    private function parse(array $item): ?GeoResult
    {
        $a = is_array($item['address'] ?? null) ? $item['address'] : [];
        $parts = new AddressParts(
            self::pick($a, 'houseNumber'),
            self::pick($a, 'street'),
            self::pick($a, 'city'),
            self::pick($a, 'postalCode'),
            self::pick($a, 'county'),
            self::pick($a, 'state'),
            self::pick($a, 'countryName'),
            self::countryCode($a['countryCode'] ?? null, $a['countryName'] ?? null),
        );
        $pos = is_array($item['position'] ?? null) ? $item['position'] : [];

        return $this->result($pos['lat'] ?? null, $pos['lng'] ?? null, (string) ($a['label'] ?? $item['title'] ?? ''), $parts);
    }
}
