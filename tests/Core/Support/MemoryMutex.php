<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core\Support;

use facilo\rumbo\Core\Ports\Mutex;

final class MemoryMutex implements Mutex
{
    /** @var array<string,bool> */
    public array $held = [];
    public int $acquired = 0;
    public bool $refuse = false;

    public function acquire(string $name, int $timeoutSec): bool
    {
        if ($this->refuse || isset($this->held[$name])) {
            return false;
        }
        $this->held[$name] = true;
        $this->acquired++;

        return true;
    }

    public function release(string $name): void
    {
        unset($this->held[$name]);
    }
}
