<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

/**
 * The address parts Rumbo stores, in its own part names.
 */
final class AddressParts
{
    public const KEYS = ['number', 'street', 'city', 'postcode', 'county', 'state', 'country', 'countryCode'];

    public readonly ?string $number;
    public readonly ?string $street;
    public readonly ?string $city;
    public readonly ?string $postcode;
    public readonly ?string $county;
    public readonly ?string $state;
    public readonly ?string $country;
    public readonly ?string $countryCode;

    public function __construct(
        mixed $number = null,
        mixed $street = null,
        mixed $city = null,
        mixed $postcode = null,
        mixed $county = null,
        mixed $state = null,
        mixed $country = null,
        mixed $countryCode = null,
    ) {
        $this->number = self::clean($number);
        $this->street = self::clean($street);
        $this->city = self::clean($city);
        $this->postcode = self::clean($postcode);
        $this->county = self::clean($county);
        $this->state = self::clean($state);
        $this->country = self::clean($country);

        $code = self::clean($countryCode);
        $this->countryCode = $code !== null && preg_match('/^[A-Za-z]{2}$/', $code) ? strtoupper($code) : null;
    }

    /** Builds parts from an array using Rumbo's part names; other keys are ignored. */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['number'] ?? null,
            $data['street'] ?? null,
            $data['city'] ?? null,
            $data['postcode'] ?? null,
            $data['county'] ?? null,
            $data['state'] ?? null,
            $data['country'] ?? null,
            $data['countryCode'] ?? null,
        );
    }

    /** The 8 parts, in a fixed order, null when unknown. */
    public function toArray(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $this->$key;
        }

        return $out;
    }

    /** True when no part is set. */
    public function isEmpty(): bool
    {
        return array_filter($this->toArray(), static fn($v) => $v !== null) === [];
    }

    /** One line in the usual European order: "Street Number, Postcode City, State, Country". */
    public function oneLine(): string
    {
        $street = trim(($this->street ?? '') . ' ' . ($this->number ?? ''));
        $locality = trim(($this->postcode ?? '') . ' ' . ($this->city ?? ''));
        $state = $this->state !== $this->city ? $this->state : null;

        return implode(', ', array_filter(
            [$street, $locality, $state, $this->country],
            static fn($v) => $v !== null && $v !== '',
        ));
    }

    private static function clean(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }
}
