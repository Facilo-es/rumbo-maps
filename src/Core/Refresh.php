<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Decides what the Google 30-day job does with a stored location.
 * Only Google-sourced points are touched; manual, import and keyless ones are always kept.
 */
final class Refresh
{
    public const MAX_AGE_SECONDS = 30 * 86400;

    /** 'keep', or the mode ('refresh' | 'clear') when a Google point is over 30 days old or undated. */
    public static function decide(string $source, ?DateTimeImmutable $geocodedAt, DateTimeImmutable $now, string $mode = 'refresh'): string
    {
        if ($mode !== 'refresh' && $mode !== 'clear') {
            throw new InvalidArgumentException("Unknown mode \"$mode\"");
        }
        if ($source !== 'google') {
            return 'keep';
        }
        if ($geocodedAt !== null && $now->getTimestamp() - $geocodedAt->getTimestamp() <= self::MAX_AGE_SECONDS) {
            return 'keep';
        }

        return $mode;
    }
}
