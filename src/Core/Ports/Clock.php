<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Ports;

use DateTimeImmutable;

interface Clock
{
    /** Current time, with microseconds. */
    public function now(): DateTimeImmutable;

    /** Pauses the current process. */
    public function sleepMicros(int $micros): void;
}
