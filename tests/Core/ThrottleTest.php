<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use DateTimeImmutable;
use facilo\rumbo\Core\Providers\GeoOptions;
use facilo\rumbo\Core\Providers\Nominatim;
use facilo\rumbo\Core\Throttle;
use facilo\rumbo\tests\Core\Support\FakeClock;
use facilo\rumbo\tests\Core\Support\FakeHttp;
use facilo\rumbo\tests\Core\Support\MemoryKeyValue;
use facilo\rumbo\tests\Core\Support\MemoryMutex;
use PHPUnit\Framework\TestCase;

final class ThrottleTest extends TestCase
{
    public function testT48FivePublicNominatimLookupsTakeAtLeastFourSeconds(): void
    {
        $clock = new FakeClock();
        $mutex = new MemoryMutex();
        $throttle = new Throttle($mutex, $clock, new MemoryKeyValue());
        $http = new FakeHttp([new \facilo\rumbo\Core\Ports\HttpResponse(200, (string) file_get_contents(__DIR__ . '/fixtures/nominatim-madrid.json'))], $clock);
        $nominatim = new Nominatim($http, '1.0.0', 'example.es', Nominatim::PUBLIC_URL, $throttle);

        $start = $clock->seconds();
        for ($i = 0; $i < 5; $i++) {
            $nominatim->lookup('Madrid ' . $i, new GeoOptions());
        }

        self::assertGreaterThanOrEqual(4.0, $clock->seconds() - $start);
        $times = array_column($http->requests, 'at');
        self::assertCount(5, $times);
        for ($i = 1; $i < 5; $i++) {
            self::assertGreaterThanOrEqual(1.0, $times[$i] - $times[$i - 1]);
        }
        self::assertSame(5, $mutex->acquired);
        self::assertSame([], $mutex->held);
    }

    public function testSelfHostedNominatimIsNotThrottled(): void
    {
        $clock = new FakeClock();
        $throttle = new Throttle(new MemoryMutex(), $clock, new MemoryKeyValue());
        $n = new Nominatim(FakeHttp::fixture('nominatim-madrid.json'), '1', 'x', 'https://geo.example.es', $throttle);
        for ($i = 0; $i < 3; $i++) {
            $n->lookup('Madrid', new GeoOptions());
        }
        self::assertSame(0, $clock->slept);
    }

    public function testNoWaitOnceTheIntervalHasPassed(): void
    {
        $clock = new FakeClock();
        $throttle = new Throttle(new MemoryMutex(), $clock, new MemoryKeyValue());
        $throttle->waitTurn('mapbox', 2.0);
        $clock->advance(1);
        $throttle->waitTurn('mapbox', 2.0);
        self::assertSame(0, $clock->slept);
        $throttle->waitTurn('mapbox', 2.0);
        self::assertSame(500000, $clock->slept);
    }

    public function testLockFailureAndBadRate(): void
    {
        $mutex = new MemoryMutex();
        $mutex->refuse = true;
        $throttle = new Throttle($mutex, new FakeClock(), new MemoryKeyValue());
        try {
            $throttle->waitTurn('nominatim', 1.0);
            self::fail('No exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('lock', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $throttle->waitTurn('nominatim', 0.0);
    }

    public function testT62PublicNominatimBulkStopsAt2000AndSaysWhenItResumes(): void
    {
        $clock = new FakeClock('2026-11-07 22:15:00');
        $throttle = new Throttle(new MemoryMutex(), $clock, new MemoryKeyValue());

        $cap = $throttle->dailyBulkAllowance('nominatim', 'site-1');
        self::assertTrue($cap->allowed);
        self::assertSame(0, $cap->used);
        self::assertSame(2000, $cap->cap);
        self::assertNull($cap->resumesAt);

        self::assertSame(1999, $throttle->consumeBulk('nominatim', 'site-1', 1999));
        self::assertTrue($throttle->dailyBulkAllowance('nominatim', 'site-1')->allowed);
        self::assertSame(1, $throttle->dailyBulkAllowance('nominatim', 'site-1')->remaining());
        $throttle->consumeBulk('nominatim', 'site-1');

        $stopped = $throttle->dailyBulkAllowance('nominatim', 'site-1');
        self::assertFalse($stopped->allowed);
        self::assertSame(2000, $stopped->used);
        self::assertSame(0, $stopped->remaining());
        self::assertEquals(new DateTimeImmutable('2026-11-08 00:00:00', new \DateTimeZone('UTC')), $stopped->resumesAt);

        self::assertTrue($throttle->dailyBulkAllowance('nominatim', 'site-2')->allowed);
        $clock->advance(2 * 3600);
        self::assertTrue($throttle->dailyBulkAllowance('nominatim', 'site-1')->allowed);
    }

    public function testOtherProvidersHaveNoCap(): void
    {
        $throttle = new Throttle(new MemoryMutex(), new FakeClock(), new MemoryKeyValue());
        $throttle->consumeBulk('google', 's', 50000);
        $cap = $throttle->dailyBulkAllowance('google', 's');
        self::assertTrue($cap->allowed);
        self::assertNull($cap->cap);
        self::assertNull($cap->remaining());
        self::assertSame(50000, $cap->used);
        self::assertNull(Throttle::capFor('nominatim-self'));
    }
}
