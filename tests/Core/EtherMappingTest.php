<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use facilo\rumbo\Core\EtherMapping;
use PHPUnit\Framework\TestCase;

final class EtherMappingTest extends TestCase
{
    public function testConvertsLegacyRow(): void
    {
        $out = EtherMapping::convert([
            'lat' => 40.4168,
            'lng' => '-3.7038',
            'zoom' => null,
            'address' => '',
            'parts' => json_encode(['number' => '1', 'address' => 'Calle Mayor', 'city' => 'Madrid', 'postcode' => '28013', 'country' => 'Spain', 'planet' => 'Earth']),
        ]);
        self::assertTrue($out['ok']);
        $row = $out['row'];
        self::assertSame('40.416800000', $row['lat']);
        self::assertSame('-3.703800000', $row['lng']);
        self::assertSame(15, $row['zoom']);
        self::assertSame('Calle Mayor 1, 28013 Madrid, Spain', $row['address']);
        self::assertSame('Calle Mayor', $row['parts']['street']);
        self::assertSame('ES', $row['countryCode']);
        self::assertSame('import', $row['source']);
        self::assertArrayNotHasKey('planet', $row['parts']);
    }

    public function testConvertsNewStylePartsAndKeepsAddress(): void
    {
        $out = EtherMapping::convert([
            'lat' => '41.387400000000001', 'lng' => '2.1686', 'zoom' => 40, 'address' => 'Plaça de Catalunya, Barcelona',
            'parts' => ['route' => 'Plaça de Catalunya', 'street_number' => '1', 'locality' => 'Barcelona', 'postal_code' => '08002',
                'administrative_area_level_1' => 'Cataluña', 'country' => 'España', 'country_short' => 'ES'],
        ]);
        self::assertSame('41.387400000', $out['row']['lat']);
        self::assertSame(22, $out['row']['zoom']);
        self::assertSame('Plaça de Catalunya, Barcelona', $out['row']['address']);
        self::assertSame('Cataluña', $out['row']['parts']['state']);
        self::assertSame('ES', $out['row']['countryCode']);
    }

    public function testT15CountryCodeFromName(): void
    {
        foreach (['Spain' => 'ES', 'España' => 'ES', 'Deutschland' => 'DE', 'Atlantis' => null] as $country => $code) {
            $parts = EtherMapping::parts(['country' => $country]);
            self::assertSame($code, $parts->countryCode, $country);
            self::assertSame($country, $parts->country);
        }
    }

    public function testShortCountryFillsCode(): void
    {
        $p = EtherMapping::parts(['country' => 'ES', 'country_short' => 'ES']);
        self::assertSame('ES', $p->countryCode);
        self::assertNull($p->country);
        self::assertSame('Madrid', EtherMapping::parts(['city_short' => 'Madrid'])->city);
        self::assertTrue(EtherMapping::parts('not json')->isEmpty());
    }

    public function testEmptyAndBrokenRows(): void
    {
        self::assertSame(['ok' => false, 'reason' => 'empty'], EtherMapping::convert(['lat' => null, 'lng' => null, 'parts' => []]));
        self::assertSame('missing-lat-lng', EtherMapping::convert(['lat' => null, 'lng' => '', 'address' => 'Somewhere'])['reason']);
        self::assertSame('missing-lat-lng', EtherMapping::convert(['lat' => '95', 'lng' => '0'])['reason']);
    }

    public function testConvertSettings(): void
    {
        $out = EtherMapping::convertSettings([
            'lat' => '41.3874', 'lng' => 2.1686, 'zoom' => 10, 'hideLatLng' => true, 'height' => null,
            'countryRestriction' => '["es","pt"]', 'country' => 'fr',
            'boundaryRestrictionNELat' => 44, 'boundaryRestrictionNELng' => 4.5, 'boundaryRestrictionSWLat' => 36, 'boundaryRestrictionSWLng' => -9.5,
            'boundary' => '""', 'showW3WGrid' => true, 'showW3WField' => false, 'typeRestriction' => 'address', 'showCurrentLocation' => true,
        ]);
        $s = $out['settings'];
        self::assertSame(41.3874, $s['defaultLat']);
        self::assertSame(2.1686, $s['defaultLng']);
        self::assertSame(10, $s['defaultZoom']);
        self::assertFalse($s['showLatLng']);
        self::assertSame(400, $s['editorHeight']);
        self::assertSame(['ES', 'PT'], $s['countries']);
        self::assertSame(['minLat' => 36.0, 'minLng' => -9.5, 'maxLat' => 44.0, 'maxLng' => 4.5], $s['searchBias']);
        self::assertCount(3, $out['notCarried']);
        self::assertStringContainsString('what3words grid', $out['notCarried'][0]);
    }

    public function testConvertSettingsDefaultsAndFallbacks(): void
    {
        $s = EtherMapping::convertSettings(['country' => 'Spain', 'showLatLng' => true, 'hideLatLng' => true, 'height' => 320,
            'boundaryRestrictionNELat' => 44, 'boundaryRestrictionNELng' => '', 'boundaryRestrictionSWLat' => 36, 'boundaryRestrictionSWLng' => -9])['settings'];
        self::assertSame(['ES'], $s['countries']);
        self::assertTrue($s['showLatLng']);
        self::assertSame(320, $s['editorHeight']);
        self::assertNull($s['searchBias']);
        self::assertSame(40.4168, $s['defaultLat']);

        $list = EtherMapping::convertSettings(['countryRestriction' => ['de', 'Francia']])['settings'];
        self::assertSame(['DE', 'FR'], $list['countries']);
        self::assertSame([], EtherMapping::convertSettings([])['notCarried']);
    }

    public function testConvertPluginSettings(): void
    {
        $out = EtherMapping::convertPluginSettings(['mapTiles' => 'carto.light', 'geoService' => 'google', 'mapToken' => '$MAP_TOKEN', 'geoToken' => 'abc', 'other' => 1]);
        self::assertSame(['mapStyle' => 'positron', 'provider' => 'google', 'mapToken' => '$MAP_TOKEN', 'geoToken' => 'abc'], $out['settings']);
        self::assertCount(1, $out['notCarried']);

        $unknown = EtherMapping::convertPluginSettings(['geoService' => 'what3words']);
        self::assertSame('nominatim', $unknown['settings']['provider']);
        self::assertStringContainsString('what3words', $unknown['notCarried'][0]);
    }

    public function testStyleForTiles(): void
    {
        self::assertSame('dark', EtherMapping::styleForTiles('carto.dark'));
        self::assertSame('positron', EtherMapping::styleForTiles('wikimedia-grey'));
        self::assertSame('bright', EtherMapping::styleForTiles('mapbox.outdoors'));
        self::assertSame('liberty', EtherMapping::styleForTiles('openstreetmap'));
    }
}
