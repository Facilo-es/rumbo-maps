<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Ports;

/**
 * Site-wide lock (Craft's mutex component on the Craft side).
 */
interface Mutex
{
    /** Tries to take the lock, waiting up to $timeoutSec seconds. */
    public function acquire(string $name, int $timeoutSec): bool;

    /** Releases a lock taken with acquire(). */
    public function release(string $name): void;
}
