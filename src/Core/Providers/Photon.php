<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

use facilo\rumbo\Core\AddressParts;
use facilo\rumbo\Core\Ports\HttpClient;

/**
 * Photon (komoot, OpenStreetMap data): the keyless type-ahead in the control panel.
 * Photon has no country filter, so results are filtered after the call.
 */
final class Photon extends HttpGeocoder
{
    public const PUBLIC_URL = 'https://photon.komoot.io';
    protected const LABEL = 'Photon';
    private const LANGUAGES = ['en', 'de', 'fr', 'it'];

    private readonly string $baseUrl;

    public function __construct(
        HttpClient $http,
        private readonly string $version,
        private readonly string $siteHost,
        string $baseUrl = self::PUBLIC_URL,
    ) {
        parent::__construct($http);
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : self::PUBLIC_URL, '/');
    }

    public function name(): string
    {
        return 'photon';
    }

    public function lookup(string $query, GeoOptions $o): array
    {
        $params = ['q' => $query, 'limit' => $o->countries === [] ? $o->limit : min(50, $o->limit * 4)];
        if (in_array($o->baseLanguage(), self::LANGUAGES, true)) {
            $params['lang'] = $o->baseLanguage();
        }
        if (($c = $o->biasCentre()) !== null) {
            $params['lat'] = self::num($c['lat']);
            $params['lon'] = self::num($c['lng']);
        }

        $results = self::inCountries($this->features($this->get('/api/', $params)), $o);

        return array_slice($results, 0, $o->limit);
    }

    public function reverse(float $lat, float $lng, GeoOptions $o): ?GeoResult
    {
        $params = ['lat' => self::num($lat), 'lon' => self::num($lng), 'limit' => 1];
        if (in_array($o->baseLanguage(), self::LANGUAGES, true)) {
            $params['lang'] = $o->baseLanguage();
        }

        return $this->features($this->get('/reverse', $params))[0] ?? null;
    }

    private function get(string $path, array $params): array
    {
        return $this->getJson($this->baseUrl . $path . '?' . http_build_query($params), [
            'User-Agent' => sprintf('RumboMaps/%s (+%s)', $this->version, $this->siteHost),
        ]);
    }

    /** @return list<GeoResult> */
    private function features(array $data): array
    {
        $out = [];
        foreach ((array) ($data['features'] ?? []) as $f) {
            $p = is_array($f['properties'] ?? null) ? $f['properties'] : [];
            $coords = $f['geometry']['coordinates'] ?? [];
            $street = self::pick($p, 'street');
            if ($street === null && in_array($p['osm_key'] ?? '', ['highway', 'place'], true) && ($p['type'] ?? '') === 'street') {
                $street = self::pick($p, 'name');
            }
            $parts = new AddressParts(
                self::pick($p, 'housenumber'),
                $street,
                self::pick($p, 'city', 'town', 'village'),
                self::pick($p, 'postcode'),
                self::pick($p, 'county'),
                self::pick($p, 'state'),
                self::pick($p, 'country'),
                self::countryCode($p['countrycode'] ?? null, $p['country'] ?? null),
            );
            $name = self::pick($p, 'name');
            $formatted = implode(', ', array_filter([
                $name !== $street ? $name : null,
                $parts->oneLine(),
            ]));
            $r = $this->result($coords[1] ?? null, $coords[0] ?? null, $formatted, $parts);
            if ($r !== null) {
                $out[] = $r;
            }
        }

        return $out;
    }
}
