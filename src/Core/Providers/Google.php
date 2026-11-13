<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

use facilo\rumbo\Core\AddressParts;
use facilo\rumbo\Core\Ports\HttpClient;

/**
 * Google Geocoding API (server key). Google results may only be shown on Google maps and
 * cached for 30 days; the Craft side enforces both.
 */
final class Google extends HttpGeocoder
{
    public const GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';
    public const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';
    protected const LABEL = 'Google';
    protected const API_HINT = 'Geocoding API';

    public function __construct(HttpClient $http, private readonly string $key)
    {
        parent::__construct($http, $key);
    }

    public function name(): string
    {
        return 'google';
    }

    public function lookup(string $query, GeoOptions $o): array
    {
        $params = ['address' => $query];
        if ($o->countries !== []) {
            $params['components'] = implode('|', array_map(static fn($c) => "country:$c", $o->countries));
        }
        if ($o->biasBox !== null) {
            $b = $o->biasBox;
            $params['bounds'] = self::num($b['minLat']) . ',' . self::num($b['minLng']) . '|' . self::num($b['maxLat']) . ',' . self::num($b['maxLng']);
        }

        return array_slice($this->call($params, $o), 0, $o->limit);
    }

    public function reverse(float $lat, float $lng, GeoOptions $o): ?GeoResult
    {
        return $this->call(['latlng' => self::num($lat) . ',' . self::num($lng)], $o)[0] ?? null;
    }

    /**
     * Describes a Places API (New) Autocomplete request for the given input, for the caller to send
     * with POST. The key goes in the X-Goog-Api-Key header.
     *
     * @return array{method:string,url:string,headers:array<string,string>,body:string}
     */
    public function autocompleteRequest(string $input, GeoOptions $o, ?string $sessionToken = null): array
    {
        $body = ['input' => $input];
        if ($o->countries !== []) {
            $body['includedRegionCodes'] = array_map('strtolower', array_slice($o->countries, 0, 15));
        }
        if ($o->language !== '') {
            $body['languageCode'] = $o->language;
        }
        if ($o->biasBox !== null) {
            $b = $o->biasBox;
            $body['locationBias'] = ['rectangle' => [
                'low' => ['latitude' => $b['minLat'], 'longitude' => $b['minLng']],
                'high' => ['latitude' => $b['maxLat'], 'longitude' => $b['maxLng']],
            ]];
        }
        if ($sessionToken !== null && $sessionToken !== '') {
            $body['sessionToken'] = $sessionToken;
        }

        return [
            'method' => 'POST',
            'url' => self::AUTOCOMPLETE_URL,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $this->key,
            ],
            'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    /** @return list<GeoResult> */
    private function call(array $params, GeoOptions $o): array
    {
        if ($o->language !== '') {
            $params['language'] = $o->language;
        }
        $params['key'] = $this->key;

        $data = $this->getJson(self::GEOCODE_URL . '?' . http_build_query($params));
        $status = (string) ($data['status'] ?? '');
        if ($status === 'ZERO_RESULTS') {
            return [];
        }
        if ($status !== 'OK') {
            throw $this->error(200, trim($status . ': ' . ($data['error_message'] ?? ''), ': '));
        }

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
        $long = [];
        $short = [];
        foreach ((array) ($item['address_components'] ?? []) as $c) {
            foreach ((array) ($c['types'] ?? []) as $type) {
                $long[$type] ??= $c['long_name'] ?? null;
                $short[$type] ??= $c['short_name'] ?? null;
            }
        }

        $parts = new AddressParts(
            self::pick($long, 'street_number'),
            self::pick($long, 'route'),
            self::pick($long, 'locality', 'postal_town', 'administrative_area_level_3'),
            self::pick($long, 'postal_code'),
            self::pick($long, 'administrative_area_level_2'),
            self::pick($long, 'administrative_area_level_1'),
            self::pick($long, 'country'),
            self::countryCode($short['country'] ?? null, $long['country'] ?? null),
        );
        $loc = $item['geometry']['location'] ?? [];

        return $this->result($loc['lat'] ?? null, $loc['lng'] ?? null, (string) ($item['formatted_address'] ?? ''), $parts);
    }
}
