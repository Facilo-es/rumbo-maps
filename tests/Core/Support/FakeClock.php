<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core\Support;

use DateTimeImmutable;
use DateTimeZone;
use facilo\rumbo\Core\Ports\Clock;

final class FakeClock implements Clock
{
    private int $micros;
    public int $slept = 0;

    public function __construct(string $start = '2026-11-07 10:00:00')
    {
        $this->micros = (int) (new DateTimeImmutable($start, new DateTimeZone('UTC')))->format('U') * 1_000_000;
    }

    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($this->micros, 1_000_000), $this->micros % 1_000_000), new DateTimeZone('UTC'));
    }

    public function sleepMicros(int $micros): void
    {
        $this->micros += max(0, $micros);
        $this->slept += max(0, $micros);
    }

    public function advance(int $seconds): void
    {
        $this->micros += $seconds * 1_000_000;
    }

    public function seconds(): float
    {
        return $this->micros / 1_000_000;
    }
}
