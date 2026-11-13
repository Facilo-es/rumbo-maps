<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

use facilo\rumbo\Core\AddressParts;
use facilo\rumbo\Core\Ports\HttpClient;

/**
 * Apple Maps Server API. Takes a short-lived access token that the caller has already
 * exchanged from the Maps auth token; this class never sees the private key.
 */
final class Apple extends HttpGeocoder
{
    public const BASE_URL = 'https://maps-api.apple.com/v1';
    protected const LABEL = 'Apple Maps';
    protected const API_HINT = 'the Maps Server API';

    public function __construct(HttpClient $http, private readonly string $accessToken)
    {
        parent::__construct($http, $accessToken);
    }

    public function name(): string
    {
        return 'apple';
    }

    public function lookup(string $query, GeoOptions $o): array
    {
        $params = ['q' => $query];
        if ($o->countries !== []) {
            $params['limitToCountries'] = implode(',', $o->countries);
        }
        if (($c = $o->biasCentre()) !== null) {
            $params['searchLocation'] = self::num($c['lat']) . ',' . self::num($c['lng']);
        }

        return array_slice($this->call('/geocode', $params, $o), 0, $o->limit);
    }

    public function reverse(float $lat, float $lng, GeoOptions $o): ?GeoResult
    {
        return $this->call('/reverseGeocode', ['loc' => self::num($lat) . ',' . self::num($lng)], $o)[0] ?? null;
    }

    /** @return list<GeoResult> */
    private function call(string $path, array $params, GeoOptions $o): array
    {
        if ($o->language !== '') {
            $params['lang'] = $o->language;
        }

        $data = $this->getJson(self::BASE_URL . $path . '?' . http_build_query($params), [
            'Authorization' => 'Bearer ' . $this->accessToken,
        ]);
        $out = [];
        foreach ((array) ($data['results'] ?? []) as $item) {
            $r = is_array($item) ? $this->parse($item) : null;
            if ($r !== null) {
                $out[] = $r;
            }
        }

        return $out;
    }

    private function parse(array $item): ?GeoResult
    {
        $s = is_array($item['structuredAddress'] ?? null) ? $item['structuredAddress'] : [];
        $parts = new AddressParts(
            self::pick($s, 'subThoroughfare'),
            self::pick($s, 'thoroughfare'),
            self::pick($s, 'locality'),
            self::pick($s, 'postCode'),
            self::pick($s, 'subAdministrativeArea'),
            self::pick($s, 'administrativeArea'),
            self::pick($item, 'country'),
            self::countryCode($item['countryCode'] ?? null, $item['country'] ?? null),
        );
        $c = is_array($item['coordinate'] ?? null) ? $item['coordinate'] : [];
        $lines = array_filter((array) ($item['formattedAddressLines'] ?? []), 'is_string');

        return $this->result($c['latitude'] ?? null, $c['longitude'] ?? null, implode(', ', $lines), $parts);
    }
}
