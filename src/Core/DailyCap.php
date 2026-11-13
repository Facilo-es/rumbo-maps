<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

use DateTimeImmutable;

/**
 * How much bulk geocoding a site may still do today with one provider.
 */
final class DailyCap
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $used,
        public readonly ?int $cap,
        public readonly ?DateTimeImmutable $resumesAt,
    ) {
    }

    /** Lookups left today, or null when there is no cap. */
    public function remaining(): ?int
    {
        return $this->cap === null ? null : max(0, $this->cap - $this->used);
    }
}
