<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

/**
 * Distance and bounding box maths (spec section 6).
 */
final class Geo
{
    /** IUGG mean Earth radius. */
    public const EARTH_RADIUS_KM = 6371.0088;
    public const KM_PER_MILE = 1.609344;
    /** Kilometres per degree of latitude used for the bounding box. */
    public const KM_PER_DEGREE = 111.045;

    /** Great-circle distance in km (haversine with the mean Earth radius). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = $phi2 - $phi1;
        $dLambda = deg2rad($lng2 - $lng1);

        $h = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($h)));
    }

    /** Kilometres to miles. */
    public static function kmToMiles(float $km): float
    {
        return $km / self::KM_PER_MILE;
    }

    /** Miles to kilometres. */
    public static function milesToKm(float $miles): float
    {
        return $miles * self::KM_PER_MILE;
    }

    /**
     * Box around a point for the indexed pre-filter. Longitude bounds are null near the poles
     * (|lat| > 85) or when the box would cross the ±180 meridian: filter on latitude only then.
     *
     * @return array{minLat:float,maxLat:float,minLng:?float,maxLng:?float}
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $radiusKm = max(0.0, $radiusKm);
        $dLat = $radiusKm / self::KM_PER_DEGREE;
        $box = [
            'minLat' => max(-90.0, $lat - $dLat),
            'maxLat' => min(90.0, $lat + $dLat),
            'minLng' => null,
            'maxLng' => null,
        ];

        if (abs($lat) > 85) {
            return $box;
        }

        $dLng = $radiusKm / (self::KM_PER_DEGREE * cos(deg2rad($lat)));
        if ($lng - $dLng < -180 || $lng + $dLng > 180) {
            return $box;
        }

        $box['minLng'] = $lng - $dLng;
        $box['maxLng'] = $lng + $dLng;

        return $box;
    }

    /** Rounds a coordinate to 1 decimal (about 11 km) for insights. */
    public static function roundForInsights(float $value): float
    {
        return round($value, 1);
    }

    /**
     * Reads a point from {lat, lng} (or lon/lng), [lat, lng], {coordinate: {...}} or "lat,lng".
     * Returns null for anything else or out-of-range values.
     *
     * @return array{lat:float,lng:float}|null
     */
    public static function parseLatLng(mixed $value): ?array
    {
        if (is_string($value)) {
            if (!preg_match('/^\s*([+-]?\d+(?:\.\d+)?)\s*,\s*([+-]?\d+(?:\.\d+)?)\s*$/', $value, $m)) {
                return null;
            }

            return self::checked($m[1], $m[2]);
        }

        if (!is_array($value)) {
            return null;
        }

        if (array_key_exists('coordinate', $value)) {
            return is_array($value['coordinate']) || is_string($value['coordinate']) ? self::parseLatLng($value['coordinate']) : null;
        }

        if (array_key_exists('lat', $value)) {
            return self::checked($value['lat'], $value['lng'] ?? $value['lon'] ?? null);
        }

        if (array_is_list($value) && count($value) === 2) {
            return self::checked($value[0], $value[1]);
        }

        return null;
    }

    /** True when both values are within the valid latitude and longitude ranges. */
    public static function isValid(float $lat, float $lng): bool
    {
        return is_finite($lat) && is_finite($lng) && abs($lat) <= 90 && abs($lng) <= 180;
    }

    private static function checked(mixed $lat, mixed $lng): ?array
    {
        if (!self::isNumber($lat) || !self::isNumber($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        return self::isValid($lat, $lng) ? ['lat' => $lat, 'lng' => $lng] : null;
    }

    private static function isNumber(mixed $v): bool
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v)));
    }
}
