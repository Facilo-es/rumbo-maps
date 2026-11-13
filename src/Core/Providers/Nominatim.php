<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

use facilo\rumbo\Core\AddressParts;
use facilo\rumbo\Core\Ports\HttpClient;
use facilo\rumbo\Core\Throttle;

/**
 * OpenStreetMap Nominatim (public or self-hosted). Server-side lookups only, never type-ahead.
 * The public service is held to one request a second through the Throttle.
 */
final class Nominatim extends HttpGeocoder
{
    public const PUBLIC_URL = 'https://nominatim.openstreetmap.org';
    protected const LABEL = 'OpenStreetMap (Nominatim)';

    private readonly string $baseUrl;

    public function __construct(
        HttpClient $http,
        private readonly string $version,
        private readonly string $siteHost,
        string $baseUrl = self::PUBLIC_URL,
        private readonly ?Throttle $throttle = null,
    ) {
        parent::__construct($http);
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : self::PUBLIC_URL, '/');
    }

    public function name(): string
    {
        return 'nominatim';
    }

    /** True when this talks to the public nominatim.openstreetmap.org service. */
    public function isPublic(): bool
    {
        return strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST)) === 'nominatim.openstreetmap.org';
    }

    /** The User-Agent Nominatim's policy requires. */
    public function userAgent(): string
    {
        return sprintf('RumboMaps/%s (+%s)', $this->version, $this->siteHost);
    }

    public function lookup(string $query, GeoOptions $o): array
    {
        $params = [
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => $o->limit,
        ];
        if ($o->countries !== []) {
            $params['countrycodes'] = strtolower(implode(',', $o->countries));
        }
        if ($o->biasBox !== null) {
            $b = $o->biasBox;
            $params['viewbox'] = implode(',', array_map(self::num(...), [$b['minLng'], $b['maxLat'], $b['maxLng'], $b['minLat']]));
        }

        $data = $this->request('/search', $params, $o);

        return array_values(array_filter(array_map($this->parse(...), array_filter($data, 'is_array'))));
    }

    public function reverse(float $lat, float $lng, GeoOptions $o): ?GeoResult
    {
        $data = $this->request('/reverse', [
            'lat' => self::num($lat),
            'lon' => self::num($lng),
            'format' => 'jsonv2',
            'addressdetails' => 1,
        ], $o);

        return isset($data['error']) ? null : $this->parse($data);
    }

    private function request(string $path, array $params, GeoOptions $o): array
    {
        if ($o->language !== '') {
            $params['accept-language'] = $o->language;
        }
        if ($this->throttle !== null && $this->isPublic()) {
            $this->throttle->waitTurn('nominatim', 1.0);
        }

        $data = $this->getJson($this->baseUrl . $path . '?' . http_build_query($params), [
            'User-Agent' => $this->userAgent(),
            'Accept' => 'application/json',
        ]);

        if (is_array($data['error'] ?? null)) {
            throw $this->error(200, (string) ($data['error']['message'] ?? 'Unknown error'));
        }

        return $data;
    }

    private function parse(array $item): ?GeoResult
    {
        $a = is_array($item['address'] ?? null) ? $item['address'] : [];
        $parts = new AddressParts(
            self::pick($a, 'house_number'),
            self::pick($a, 'road', 'pedestrian', 'square', 'footway', 'street'),
            self::pick($a, 'city', 'town', 'village', 'municipality', 'hamlet'),
            self::pick($a, 'postcode'),
            self::pick($a, 'county', 'province', 'state_district'),
            self::pick($a, 'state', 'region'),
            self::pick($a, 'country'),
            self::countryCode($a['country_code'] ?? null, $a['country'] ?? null),
        );

        return $this->result($item['lat'] ?? null, $item['lon'] ?? null, (string) ($item['display_name'] ?? ''), $parts);
    }
}
