<?php

namespace facilo\rumbo\Craft;

use Craft;
use DateTimeImmutable;
use facilo\rumbo\Core\Ports\Clock;
use facilo\rumbo\Core\Ports\KeyValue;
use facilo\rumbo\Core\Ports\Mutex;

/** Craft's cache, mutex and clock, for the Core classes that need them (Throttle). */
final class CraftPorts implements Clock, KeyValue, Mutex
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }

    public function sleepMicros(int $micros): void
    {
        usleep($micros);
    }

    public function get(string $key): mixed
    {
        $value = Craft::$app->getCache()->get($key);
        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        Craft::$app->getCache()->set($key, $value, $ttlSeconds);
    }

    public function increment(string $key, int $by, int $ttlSeconds): int
    {
        $lock = 'rumbo-kv-' . md5($key);
        $locked = $this->acquire($lock, 5);
        try {
            $value = (int)($this->get($key) ?? 0) + $by;
            $this->set($key, $value, $ttlSeconds);
            return $value;
        } finally {
            if ($locked) {
                $this->release($lock);
            }
        }
    }

    public function acquire(string $name, int $timeoutSec): bool
    {
        return Craft::$app->getMutex()->acquire($name, $timeoutSec);
    }

    public function release(string $name): void
    {
        Craft::$app->getMutex()->release($name);
    }
}
