<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

/**
 * Keys and lifetimes for the geocode cache.
 */
final class CacheKey
{
    /** sha256 of the normalised provider, query, country list and language. */
    public static function hashFor(string $provider, string $query, array $countries, string $language): string
    {
        $query = preg_replace('/\s+/u', ' ', mb_strtolower(trim($query), 'UTF-8')) ?? $query;
        $countries = array_values(array_unique(array_map(static fn($c) => strtoupper(trim((string) $c)), $countries)));
        sort($countries);

        return hash('sha256', json_encode(
            [strtolower(trim($provider)), $query, $countries, strtolower(trim($language))],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** Days a provider's results may be cached: 30 for Google (its terms), 365 for the rest. */
    public static function ttlDays(string $provider): int
    {
        return strtolower($provider) === 'google' ? 30 : 365;
    }
}
