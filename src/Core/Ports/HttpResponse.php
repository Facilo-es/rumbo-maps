<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Ports;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    /** True for any 2xx status. */
    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
