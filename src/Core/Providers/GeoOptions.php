<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

final class GeoOptions
{
    /** @var list<string> ISO alpha-2, upper case */
    public readonly array $countries;
    public readonly string $language;
    /** @var array{minLat:float,minLng:float,maxLat:float,maxLng:float}|null */
    public readonly ?array $biasBox;
    public readonly int $limit;

    public function __construct(array $countries = [], string $language = '', ?array $biasBox = null, int $limit = 5)
    {
        $this->countries = array_values(array_unique(array_map(
            static fn($c) => strtoupper(trim((string) $c)),
            array_filter($countries, static fn($c) => is_string($c) && preg_match('/^\s*[A-Za-z]{2}\s*$/', $c)),
        )));
        $this->language = preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language) ? $language : '';
        $this->biasBox = self::box($biasBox);
        $this->limit = max(1, min(50, $limit));
    }

    /** Centre of the bias box, or null. */
    public function biasCentre(): ?array
    {
        if ($this->biasBox === null) {
            return null;
        }

        return [
            'lat' => ($this->biasBox['minLat'] + $this->biasBox['maxLat']) / 2,
            'lng' => ($this->biasBox['minLng'] + $this->biasBox['maxLng']) / 2,
        ];
    }

    /** Two-letter language, e.g. "es" for "es-ES", or ''. */
    public function baseLanguage(): string
    {
        return strtolower(substr($this->language, 0, 2));
    }

    private static function box(?array $box): ?array
    {
        if ($box === null) {
            return null;
        }
        foreach (['minLat', 'minLng', 'maxLat', 'maxLng'] as $k) {
            if (!is_numeric($box[$k] ?? null)) {
                return null;
            }
        }

        return [
            'minLat' => (float) $box['minLat'],
            'minLng' => (float) $box['minLng'],
            'maxLat' => (float) $box['maxLat'],
            'maxLng' => (float) $box['maxLng'],
        ];
    }
}
