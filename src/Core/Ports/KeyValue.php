<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Ports;

/**
 * Shared key-value store (Craft's cache component on the Craft side).
 */
interface KeyValue
{
    /** Returns the stored value, or null when missing or expired. */
    public function get(string $key): mixed;

    /** Stores a value for $ttlSeconds (0 = no expiry). */
    public function set(string $key, mixed $value, int $ttlSeconds): void;

    /** Adds $by to an integer value (missing counts as 0) and returns the new value. */
    public function increment(string $key, int $by, int $ttlSeconds): int;
}
