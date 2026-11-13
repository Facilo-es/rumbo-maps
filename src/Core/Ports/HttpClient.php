<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Ports;

/**
 * Minimal HTTP client the provider drivers use, so Core never depends on Guzzle or Craft.
 */
interface HttpClient
{
    /** Sends a GET request and returns the response, whatever its status. Network failures throw. */
    public function get(string $url, array $headers = []): HttpResponse;
}
