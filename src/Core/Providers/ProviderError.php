<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Providers;

use RuntimeException;

/**
 * A failed provider call. Secrets passed in are scrubbed from every text, so the message is
 * safe to log and show.
 */
final class ProviderError extends RuntimeException
{
    public readonly string $providerMessage;

    public function __construct(
        public readonly string $provider,
        public readonly int $httpStatus,
        string $providerMessage,
        public readonly string $apiHint = '',
        array $secrets = [],
    ) {
        $this->providerMessage = self::scrub($providerMessage, $secrets);
        $message = sprintf('%s said: %s', $provider, $this->providerMessage !== '' ? $this->providerMessage : 'no message');
        if ($httpStatus > 0) {
            $message .= " (HTTP $httpStatus)";
        }
        if ($apiHint !== '') {
            $message .= ". Check that $apiHint is enabled for this key";
        }
        parent::__construct($message, $httpStatus);
    }

    /** Replaces each secret, raw and URL-encoded, with "***". */
    public static function scrub(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (!is_string($secret) || strlen($secret) < 4) {
                continue;
            }
            $text = str_replace([$secret, rawurlencode($secret), urlencode($secret)], '***', $text);
        }

        return mb_substr($text, 0, 500);
    }
}
