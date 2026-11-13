<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

interface Geocoder
{
    /**
     * Forward lookup of an address or place.
     *
     * @return list<GeoResult>
     * @throws ProviderError
     */
    public function lookup(string $query, GeoOptions $o): array;

    /** Reverse lookup of a point; null when nothing is there. @throws ProviderError */
    public function reverse(float $lat, float $lng, GeoOptions $o): ?GeoResult;

    /** Provider handle, also used as the location's `source`. */
    public function name(): string;
}
