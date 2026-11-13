<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core\Support;

use facilo\rumbo\Core\Ports\KeyValue;

final class MemoryKeyValue implements KeyValue
{
    /** @var array<string,mixed> */
    public array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->data[$key] = $value;
    }

    public function increment(string $key, int $by, int $ttlSeconds): int
    {
        return $this->data[$key] = (int) ($this->data[$key] ?? 0) + $by;
    }
}
