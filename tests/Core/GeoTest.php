<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use facilo\rumbo\Core\Geo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeoTest extends TestCase
{
    public function testMadridToBarcelonaInKmAndMiles(): void
    {
        $km = Geo::distanceKm(40.4168, -3.7038, 41.3874, 2.1686);
        self::assertEqualsWithDelta(505.1, $km, 505.1 * 0.001);
        self::assertEqualsWithDelta(313.9, Geo::kmToMiles($km), 313.9 * 0.001);
        self::assertEqualsWithDelta($km, Geo::milesToKm(Geo::kmToMiles($km)), 1e-9);
    }

    public function testSolToNuevosMinisterios(): void
    {
        self::assertEqualsWithDelta(4.23, Geo::distanceKm(40.4168, -3.7038, 40.4530, -3.6883), 4.23 * 0.001);
    }

    public function testDistanceEdgeCases(): void
    {
        self::assertSame(0.0, Geo::distanceKm(40.4168, -3.7038, 40.4168, -3.7038));
        self::assertEqualsWithDelta(Geo::EARTH_RADIUS_KM * M_PI, Geo::distanceKm(0, 0, 0, 180), 1e-6);
        self::assertEqualsWithDelta(Geo::distanceKm(1, 179.9, 1, -179.9), Geo::distanceKm(1, -0.1, 1, 0.1), 1e-9);
    }

    public function testBoundingBoxFor25KmAroundMadrid(): void
    {
        $box = Geo::boundingBox(40.4168, -3.7038, 25);
        self::assertEqualsWithDelta(0.2251, (40.4168 - $box['minLat']), 0.00005);
        self::assertEqualsWithDelta(0.2251, ($box['maxLat'] - 40.4168), 0.00005);
        self::assertEqualsWithDelta(0.2957, ($box['maxLng'] + 3.7038), 0.00005);
        self::assertEqualsWithDelta(0.2957, (-3.7038 - $box['minLng']), 0.00005);
    }

    public function testBoundingBoxSkipsLongitudeNearPolesAndDateLine(): void
    {
        $polar = Geo::boundingBox(86.0, 10.0, 25);
        self::assertNull($polar['minLng']);
        self::assertNull($polar['maxLng']);
        self::assertSame(90.0, Geo::boundingBox(89.9, 0, 50)['maxLat']);

        $dateLine = Geo::boundingBox(-17.7, 179.9, 50);
        self::assertNull($dateLine['minLng']);
        self::assertNotNull($dateLine['minLat']);
    }

    public function testRoundForInsights(): void
    {
        self::assertSame(40.4, Geo::roundForInsights(40.4168));
        self::assertSame(-3.7, Geo::roundForInsights(-3.7038));
    }

    public static function latLngInputs(): array
    {
        return [
            'string' => ['40.4168, -3.7038', [40.4168, -3.7038]],
            'lat/lng hash' => [['lat' => '40.4168', 'lng' => -3.7038], [40.4168, -3.7038]],
            'lat/lon hash' => [['lat' => 40.4168, 'lon' => -3.7038], [40.4168, -3.7038]],
            'coordinate key' => [['coordinate' => [40.4168, -3.7038]], [40.4168, -3.7038]],
            'nested coordinate string' => [['coordinate' => '40.4168,-3.7038'], [40.4168, -3.7038]],
            'list' => [[40.4168, -3.7038], [40.4168, -3.7038]],
            'out of range' => ['91,0', null],
            'text' => ['Madrid', null],
            'missing lng' => [['lat' => 1], null],
            'nan' => [[NAN, 1.0], null],
            'not an array' => [42, null],
        ];
    }

    #[DataProvider('latLngInputs')]
    public function testParseLatLng(mixed $input, ?array $expected): void
    {
        $got = Geo::parseLatLng($input);
        self::assertSame($expected, $got === null ? null : [$got['lat'], $got['lng']]);
    }

    public function testIsValid(): void
    {
        self::assertTrue(Geo::isValid(-90, 180));
        self::assertFalse(Geo::isValid(90.0001, 0));
        self::assertFalse(Geo::isValid(0, INF));
    }
}
