<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Ports;

use DateTimeImmutable;

/**
 * The {{%rumbo_locations}} table. Rows use the keys of EtherMapping::convert()'s row
 * (lat, lng, zoom, address, parts, countryCode, source) plus serviceRadius and geocodedAt.
 */
interface LocationStore
{
    /** Inserts or updates the row for one element, site and field. */
    public function upsert(int $elementId, int $siteId, int $fieldId, array $row): void;

    /** Deletes the row for one element, site and field, if any. */
    public function delete(int $elementId, int $siteId, int $fieldId): void;

    /** Returns the row for one element, site and field, or null. */
    public function find(int $elementId, int $siteId, int $fieldId): ?array;

    /** Rows with this source looked up before $before, oldest first (each with elementId, siteId, fieldId). */
    public function geocodedBefore(string $source, DateTimeImmutable $before, int $limit): array;
}
