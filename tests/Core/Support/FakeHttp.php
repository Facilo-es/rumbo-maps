<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core\Support;

use facilo\rumbo\Core\Ports\HttpClient;
use facilo\rumbo\Core\Ports\HttpResponse;

final class FakeHttp implements HttpClient
{
    /** @var list<array{url:string,headers:array}> */
    public array $requests = [];

    /** @param list<HttpResponse|\Throwable> $responses */
    public function __construct(private array $responses = [], private readonly ?FakeClock $clock = null)
    {
    }

    public static function fixture(string $name, int $status = 200): self
    {
        return new self([new HttpResponse($status, (string) file_get_contents(__DIR__ . '/../fixtures/' . $name))]);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'at' => $this->clock?->seconds()];
        $next = count($this->responses) > 1 ? array_shift($this->responses) : ($this->responses[0] ?? new HttpResponse(200, '[]'));
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastUrl(): string
    {
        return $this->requests[array_key_last($this->requests)]['url'] ?? '';
    }

    /** @return array<string,string> */
    public function lastQuery(): array
    {
        parse_str((string) parse_url($this->lastUrl(), PHP_URL_QUERY), $q);

        return $q;
    }
}
