<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

use facilo\rumbo\Core\AddressParts;
use facilo\rumbo\Core\Countries;
use facilo\rumbo\Core\Geo;
use facilo\rumbo\Core\Ports\HttpClient;
use Throwable;

/**
 * Shared plumbing for the HTTP geocoders: JSON requests, error handling and key scrubbing.
 */
abstract class HttpGeocoder implements Geocoder
{
    /** Human label used in error messages. */
    protected const LABEL = '';
    /** The API the key must have enabled, for error messages. */
    protected const API_HINT = '';

    /** @var list<string> */
    private array $secrets;

    public function __construct(protected readonly HttpClient $http, string ...$secrets)
    {
        $this->secrets = array_values(array_filter($secrets, static fn($s) => $s !== ''));
    }

    /** GETs a URL and decodes its JSON, throwing ProviderError on any HTTP or JSON failure. */
    protected function getJson(string $url, array $headers = []): array
    {
        try {
            $response = $this->http->get($url, $headers);
        } catch (Throwable $e) {
            throw $this->error(0, 'Request failed: ' . $e->getMessage());
        }

        $data = json_decode($response->body, true);
        if (!$response->isOk()) {
            throw $this->error($response->status, $this->errorMessage(is_array($data) ? $data : [], $response->body));
        }
        if (!is_array($data)) {
            throw $this->error($response->status, 'The response was not valid JSON');
        }

        return $data;
    }

    /** Builds a ProviderError with this provider's label, hint and secrets. */
    protected function error(int $status, string $message): ProviderError
    {
        return new ProviderError(static::LABEL, $status, $message, static::API_HINT, $this->secrets);
    }

    /** Reads the provider's own error text from an error body. */
    protected function errorMessage(array $data, string $raw): string
    {
        foreach (['error_description', 'error_message', 'message', 'title', 'detail'] as $key) {
            if (is_string($data[$key] ?? null) && $data[$key] !== '') {
                return $data[$key];
            }
        }
        if (is_array($data['error'] ?? null) && is_string($data['error']['message'] ?? null)) {
            return $data['error']['message'];
        }
        if (is_string($data['error'] ?? null)) {
            return $data['error'];
        }

        return trim(strip_tags(substr($raw, 0, 200)));
    }

    /** Builds a result, or null when the point is missing or out of range. */
    protected function result(mixed $lat, mixed $lng, string $formatted, AddressParts $parts): ?GeoResult
    {
        if (!is_numeric($lat) || !is_numeric($lng) || !Geo::isValid((float) $lat, (float) $lng)) {
            return null;
        }
        if ($formatted === '') {
            $formatted = $parts->oneLine();
        }

        return new GeoResult((float) $lat, (float) $lng, $formatted, $parts, $this->name());
    }

    /** Country code from a 2- or 3-letter code or, failing that, the country name. */
    protected static function countryCode(mixed $code, mixed $name = null): ?string
    {
        if (is_string($code)) {
            $code = trim($code);
            if (preg_match('/^[A-Za-z]{2}$/', $code)) {
                return strtoupper($code);
            }
            if (preg_match('/^[A-Za-z]{3}$/', $code) && ($a2 = Countries::fromAlpha3($code)) !== null) {
                return $a2;
            }
        }

        return is_string($name) ? Countries::codeFor($name) : null;
    }

    /** First non-empty string among the given keys. */
    protected static function pick(array $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $v = $data[$key] ?? null;
            if ((is_string($v) && trim($v) !== '') || is_int($v)) {
                return trim((string) $v);
            }
        }

        return null;
    }

    /** Coordinates formatted for URLs, without trailing zeros. */
    protected static function num(float $value): string
    {
        $s = rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }

    /** Drops results outside the allowed countries, for APIs without a country filter. */
    protected static function inCountries(array $results, GeoOptions $o): array
    {
        if ($o->countries === []) {
            return $results;
        }

        return array_values(array_filter(
            $results,
            static fn(GeoResult $r) => in_array($r->parts->countryCode, $o->countries, true),
        ));
    }
}
