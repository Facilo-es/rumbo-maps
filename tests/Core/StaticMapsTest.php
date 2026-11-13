<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use facilo\rumbo\Core\StaticMaps;
use PHPUnit\Framework\TestCase;

final class StaticMapsTest extends TestCase
{
    private const MADRID = ['lat' => 40.4168, 'lng' => -3.7038, 'label' => 'A'];
    private const BCN = ['lat' => 41.3874, 'lng' => 2.1686, 'color' => '#336699'];

    public function testSignUrlMatchesGooglesDocumentedExample(): void
    {
        $signed = StaticMaps::signUrl('https://maps.googleapis.com/maps/api/geocode/json?address=New+York&client=clientID', 'vNIXE0xscrmjlyV-12Nj_BvUPaw=');
        self::assertSame('https://maps.googleapis.com/maps/api/geocode/json?address=New+York&client=clientID&signature=chaRF2hTJKOScPr-RQCEhZbSzIE=', $signed);
    }

    public function testT52GoogleSignedAndOpenFreeMapEmpty(): void
    {
        $secret = 'vNIXE0xscrmjlyV-12Nj_BvUPaw=';
        $url = StaticMaps::url('google', [self::MADRID], ['width' => 600, 'height' => 300], ['key' => 'AIzaTEST', 'secret' => $secret]);
        self::assertSame(
            'https://maps.googleapis.com/maps/api/staticmap?size=600x300&zoom=15&center=40.4168,-3.7038&markers=color%3A0xE4572E%7Clabel%3AA%7C40.4168%2C-3.7038&key=AIzaTEST&signature=',
            substr($url, 0, strpos($url, 'signature=') + 10),
        );
        $unsigned = substr($url, 0, strpos($url, '&signature='));
        self::assertSame($url, StaticMaps::signUrl($unsigned, $secret));
        self::assertMatchesRegularExpression('/&signature=[A-Za-z0-9_-]{27}=$/', $url);

        self::assertSame('', StaticMaps::url('openfreemap', [self::MADRID], [], []));
        self::assertSame('', StaticMaps::srcSet('openfreemap', [self::MADRID], [], []));
        self::assertSame('', StaticMaps::url('unknown', [self::MADRID], [], ['key' => 'x']));
    }

    public function testGoogleWithoutSecretOrKey(): void
    {
        $url = StaticMaps::url('google', [self::MADRID, self::BCN], ['width' => 2000, 'scale' => 2], ['key' => 'K']);
        self::assertStringContainsString('size=640x300&scale=2&markers=', $url);
        self::assertStringNotContainsString('zoom=', $url);
        self::assertStringContainsString('color%3A0x336699%7C41.3874%2C2.1686', $url);
        self::assertStringNotContainsString('signature', $url);
        self::assertSame('', StaticMaps::url('google', [self::MADRID], [], []));
        self::assertSame('', StaticMaps::url('google', [['lat' => 'x']], [], ['key' => 'K']));
    }

    public function testMapbox(): void
    {
        self::assertSame(
            'https://api.mapbox.com/styles/v1/mapbox/streets-v12/static/pin-s-a+e4572e(-3.7038,40.4168)/-3.7038,40.4168,15/600x300?access_token=pk.T',
            StaticMaps::url('mapbox', [self::MADRID], [], ['token' => 'pk.T']),
        );
        self::assertSame(
            'https://api.mapbox.com/styles/v1/me/my-style/static/pin-s-a+e4572e(-3.7038,40.4168),pin-s+336699(2.1686,41.3874)/auto/400x200@2x?padding=40&access_token=pk.T',
            StaticMaps::url('mapbox', [self::MADRID, self::BCN], ['width' => 400, 'height' => 200, 'scale' => 2, 'style' => 'me/my-style'], ['token' => 'pk.T']),
        );
    }

    public function testMapTiler(): void
    {
        self::assertSame(
            'https://api.maptiler.com/maps/streets-v2/static/-3.7038,40.4168,12/600x300.png?markers=-3.7038%2C40.4168%2C%23e4572e&key=MT',
            StaticMaps::url('maptiler', [self::MADRID], ['zoom' => 12], ['key' => 'MT']),
        );
        self::assertSame(
            'https://api.maptiler.com/maps/streets-v2/static/auto/600x300@2x.png?markers=-3.7038%2C40.4168%2C%23e4572e%7C2.1686%2C41.3874%2C%23336699&padding=40&key=MT',
            StaticMaps::url('maptiler', [self::MADRID, self::BCN], ['scale' => 2, 'mapId' => '../evil'], ['key' => 'MT']),
        );
    }

    public function testHere(): void
    {
        self::assertSame(
            'https://image.maps.hereapi.com/mia/v3/base/mc/center:40.4168,-3.7038;zoom=15/600x300/png?apiKey=HK&overlay=point%3A40.4168%2C-3.7038%3Blabel%3DA',
            StaticMaps::url('here', [self::MADRID], [], ['apiKey' => 'HK']),
        );
        $two = StaticMaps::url('here', [self::MADRID, self::BCN], ['scale' => 2], ['apiKey' => 'HK']);
        self::assertStringStartsWith('https://image.maps.hereapi.com/mia/v3/base/mc/overlay:padding=32/1200x600/png?', $two);
        self::assertStringContainsString('%7C41.3874%2C2.1686', $two);
    }

    public function testSrcSet(): void
    {
        $set = StaticMaps::srcSet('mapbox', [self::MADRID], ['scale' => 2], ['token' => 'pk.T']);
        [$one, $two] = explode(', ', $set);
        self::assertStringEndsWith('/600x300?access_token=pk.T 1x', $one);
        self::assertStringEndsWith('/600x300@2x?access_token=pk.T 2x', $two);
    }
}
