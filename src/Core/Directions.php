<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

/**
 * "Get directions" links that open the visitor's own maps app.
 */
final class Directions
{
    public const SERVICES = ['google', 'apple', 'osm'];

    /** Directions link to the point for google, apple or osm; '' for anything else or a bad point. */
    public static function url(string $service, float $lat, float $lng): string
    {
        if (!Geo::isValid($lat, $lng)) {
            return '';
        }
        $to = self::num($lat) . ',' . self::num($lng);

        return match (strtolower($service)) {
            'google' => 'https://www.google.com/maps/dir/?api=1&destination=' . $to,
            'apple' => 'https://maps.apple.com/?daddr=' . $to,
            'osm' => 'https://www.openstreetmap.org/directions?to=' . $to,
            default => '',
        };
    }

    private static function num(float $value): string
    {
        $s = rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }
}
