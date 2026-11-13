<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Ports;

use DateTimeImmutable;

/**
 * The {{%rumbo_geocache}} table. Hashes come from CacheKey::hashFor().
 */
interface GeoCache
{
    /** Returns the cached result for a hash, or null when missing or expired. */
    public function get(string $hash): ?array;

    /** Stores a result (a list of GeoResult::toArray() values) until $expiresAt. */
    public function set(string $hash, string $provider, array $result, DateTimeImmutable $expiresAt): void;
}
