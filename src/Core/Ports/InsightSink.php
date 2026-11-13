<?php

declare(strict_types=1);

namespace facilo\rumbo\Core\Ports;

/**
 * Adds one locator search to the daily totals in {{%rumbo_insights}}. No IP, user or typed text.
 */
interface InsightSink
{
    /** Counts one search for $date (Y-m-d), the resolved place and its coordinates rounded to 1 decimal. */
    public function record(
        string $date,
        int $siteId,
        string $placeLabel,
        float $latRounded,
        float $lngRounded,
        bool $noResults,
        bool $usedMyLocation,
    ): void;
}
