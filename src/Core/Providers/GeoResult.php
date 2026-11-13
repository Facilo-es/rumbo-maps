<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

use facilo\rumbo\Core\AddressParts;

final class GeoResult
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
        public readonly string $formatted,
        public readonly AddressParts $parts,
        public readonly string $source,
    ) {
    }

    /** Plain array for the geocode cache. */
    public function toArray(): array
    {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'formatted' => $this->formatted,
            'parts' => $this->parts->toArray(),
            'source' => $this->source,
        ];
    }

    /** Rebuilds a result stored with toArray(). */
    public static function fromArray(array $data): self
    {
        return new self(
            (float) ($data['lat'] ?? 0),
            (float) ($data['lng'] ?? 0),
            (string) ($data['formatted'] ?? ''),
            AddressParts::fromArray(is_array($data['parts'] ?? null) ? $data['parts'] : []),
            (string) ($data['source'] ?? ''),
        );
    }
}
