<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use DateTimeImmutable;
use facilo\rumbo\Core\CacheKey;
use facilo\rumbo\Core\Directions;
use facilo\rumbo\Core\Refresh;
use PHPUnit\Framework\TestCase;

final class SmallPartsTest extends TestCase
{
    public function testT12DirectionsUrls(): void
    {
        self::assertSame('https://www.google.com/maps/dir/?api=1&destination=40.4168,-3.7038', Directions::url('google', 40.4168, -3.7038));
        self::assertSame('https://maps.apple.com/?daddr=40.4168,-3.7038', Directions::url('apple', 40.4168, -3.7038));
        self::assertSame('https://www.openstreetmap.org/directions?to=40.4168,-3.7038', Directions::url('osm', 40.4168, -3.7038));
        self::assertSame('https://www.google.com/maps/dir/?api=1&destination=41,2.5', Directions::url('Google', 41.0, 2.50));
        self::assertSame('', Directions::url('waze', 40.4168, -3.7038));
        self::assertSame('', Directions::url('google', 91.0, 0.0));
    }

    public function testT63GoogleOlderThan30DaysIsRefreshedAndManualKept(): void
    {
        $now = new DateTimeImmutable('2026-11-07 03:00:00');
        $old = $now->modify('-31 days');
        self::assertSame('refresh', Refresh::decide('google', $old, $now));
        self::assertSame('keep', Refresh::decide('manual', $old, $now));
        self::assertSame('keep', Refresh::decide('import', $old, $now));
        self::assertSame('keep', Refresh::decide('nominatim', null, $now));
        self::assertSame('keep', Refresh::decide('google', $now->modify('-30 days'), $now));
        self::assertSame('refresh', Refresh::decide('google', $now->modify('-30 days -1 second'), $now));
        self::assertSame('refresh', Refresh::decide('google', null, $now));
    }

    public function testT64ClearInsteadMode(): void
    {
        $now = new DateTimeImmutable('2026-11-07 03:00:00');
        self::assertSame('clear', Refresh::decide('google', $now->modify('-31 days'), $now, 'clear'));
        self::assertSame('keep', Refresh::decide('manual', $now->modify('-31 days'), $now, 'clear'));
        $this->expectException(\InvalidArgumentException::class);
        Refresh::decide('google', null, $now, 'delete');
    }

    public function testCacheKey(): void
    {
        $a = CacheKey::hashFor('nominatim', '  Calle   MAYOR 1 ', ['pt', 'ES'], 'es');
        self::assertSame(64, strlen($a));
        self::assertSame($a, CacheKey::hashFor('Nominatim', 'calle mayor 1', ['ES', 'PT', 'es'], 'ES'));
        self::assertNotSame($a, CacheKey::hashFor('google', 'calle mayor 1', ['ES', 'PT'], 'es'));
        self::assertNotSame($a, CacheKey::hashFor('nominatim', 'calle mayor 1', ['ES'], 'es'));
        self::assertNotSame($a, CacheKey::hashFor('nominatim', 'calle mayor 1', ['ES', 'PT'], 'en'));
        self::assertSame(30, CacheKey::ttlDays('google'));
        self::assertSame(365, CacheKey::ttlDays('nominatim'));
        self::assertSame(365, CacheKey::ttlDays('mapbox'));
    }
}
