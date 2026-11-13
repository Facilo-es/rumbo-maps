<?php

namespace facilo\rumbo\Craft;

use facilo\rumbo\Core\AddressParts;
use facilo\rumbo\Core\Directions;
use facilo\rumbo\Plugin;
use Twig\Markup;

/**
 * The value of a Location field. Our own API is the documented one; the old plugin's
 * names (`.streetAddress`, `.city`, `.parts.address`, `.embed()`…) are answered too (F4).
 *
 * @property-read string $streetAddress
 */
class LocationValue implements \JsonSerializable
{
    public ?string $lat = null;
    public ?string $lng = null;
    public ?int $zoom = null;
    public string $address = '';
    /** @var array<string, string|null> */
    public array $parts = [];
    public ?string $countryCode = null;
    public ?float $serviceRadius = null;
    public string $source = 'manual';
    public ?string $geocodedAt = null;
    /** Set by a proximity query, in the unit asked for. */
    public ?float $distance = null;

    public ?int $elementId = null;
    public ?int $siteId = null;
    public ?int $fieldId = null;

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if ($key === 'zoom') {
                // Old content and form posts can hold the zoom as a string, or an empty one.
                $this->zoom = is_numeric($value) ? (int)$value : null;
            } elseif ($key === 'parts') {
                $parts = is_string($value) ? json_decode($value, true) : $value;
                $this->parts = is_array($parts) ? $parts : [];
            } elseif ($key === 'serviceRadius') {
                // The editor posts the radius as text, and an empty box means none.
                $this->serviceRadius = is_numeric($value) && (float)$value > 0 ? (float)$value : null;
            } elseif (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        $this->lat = self::decimal($this->lat);
        $this->lng = self::decimal($this->lng);
        $parts = AddressParts::fromArray($this->parts);
        $this->countryCode = $this->countryCode ?: $parts->countryCode;
        $this->parts = $parts->toArray();
        $this->parts['countryCode'] = $this->countryCode;
        // The old plugin called the street "address" inside parts.
        $this->parts['address'] = $this->parts['street'];
    }

    public static function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        // Stored with 9 decimals; printed without trailing zeros, the way the old plugin printed them.
        $text = is_string($value) ? trim($value) : number_format((float)$value, 9, '.', '');
        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    public function hasPosition(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    public function isEmpty(): bool
    {
        return !$this->hasPosition() && trim($this->address) === '';
    }

    /** The old plugin's streetAddress was the street name alone, without the number. */
    public function getStreetAddress(): string
    {
        return (string)($this->parts['street'] ?? '');
    }

    /** A plain link to directions in Google, Apple or OpenStreetMap. */
    public function directionsUrl(string $service = 'google'): string
    {
        return $this->hasPosition() ? Directions::url($service, (float)$this->lat, (float)$this->lng) : '';
    }

    /** One map with this location's marker (F2). */
    public function map(array $options = []): Markup
    {
        return Plugin::getInstance()->locations->renderMap([$this], $options);
    }

    /** The old plugin's name for map(). Pro, like the rest of the bridge. */
    public function embed(array $options = []): Markup
    {
        return Plugin::getInstance()->locations->legacyCall('embed', fn() => $this->map($options), new Markup('', 'UTF-8'));
    }

    public function img(array $options = []): string
    {
        return Plugin::getInstance()->locations->legacyCall('img', fn() => Plugin::getInstance()->locations->staticUrl([$this], $options), '');
    }

    public function imgSrcSet(array $options = []): string
    {
        return Plugin::getInstance()->locations->legacyCall('imgSrcSet', fn() => Plugin::getInstance()->locations->staticSrcSet([$this], $options), '');
    }

    public function __get(string $name): mixed
    {
        if ($name === 'streetAddress') {
            return $this->getStreetAddress();
        }
        // Old top-level part names: `.city`, `.postcode` and so on.
        return $this->parts[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'streetAddress' || array_key_exists($name, $this->parts);
    }

    public function __toString(): string
    {
        return $this->address;
    }

    /** The shape kept in the element's content JSON and sent to GraphQL. */
    public function toArray(): array
    {
        $parts = $this->parts;
        unset($parts['address']);
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'zoom' => $this->zoom,
            'address' => $this->address,
            'parts' => $parts,
            'countryCode' => $this->countryCode,
            'serviceRadius' => $this->serviceRadius,
            'source' => $this->source,
            'geocodedAt' => $this->geocodedAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
