<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

use DateTimeImmutable;
use DateTimeZone;
use facilo\rumbo\Core\Ports\Clock;
use facilo\rumbo\Core\Ports\KeyValue;
use facilo\rumbo\Core\Ports\Mutex;
use RuntimeException;

/**
 * Spaces out requests to a provider across processes, and counts bulk lookups per day.
 * The 2,000-a-day bulk cap applies to provider "nominatim", meaning the public server;
 * a self-hosted Nominatim should be counted under another name, such as "nominatim-self".
 */
final class Throttle
{
    public const PUBLIC_NOMINATIM_DAILY_CAP = 2000;
    private const LOCK_TIMEOUT_SEC = 15;

    public function __construct(
        private readonly Mutex $mutex,
        private readonly Clock $clock,
        private readonly KeyValue $kv,
    ) {
    }

    /** Blocks until this process may send its next request to the provider. */
    public function waitTurn(string $provider, float $perSecond): void
    {
        if ($perSecond <= 0) {
            throw new \InvalidArgumentException('perSecond must be positive');
        }
        $lock = 'rumbo-throttle-' . $provider;
        if (!$this->mutex->acquire($lock, self::LOCK_TIMEOUT_SEC)) {
            throw new RuntimeException("Could not get the {$provider} request lock");
        }

        try {
            $key = 'rumbo:throttle:' . $provider;
            $interval = 1 / $perSecond;
            $now = $this->seconds();
            $last = $this->kv->get($key);
            if (is_numeric($last)) {
                $wait = (float) $last + $interval - $now;
                if ($wait > 0) {
                    $this->clock->sleepMicros((int) ceil($wait * 1_000_000));
                    $now = $this->seconds();
                }
            }
            $this->kv->set($key, $now, (int) ceil($interval) + 60);
        } finally {
            $this->mutex->release($lock);
        }
    }

    /** Today's bulk allowance for a site; the public Nominatim server is capped at 2,000 a day. */
    public function dailyBulkAllowance(string $provider, string $siteKey): DailyCap
    {
        $used = (int) ($this->kv->get($this->dayKey($provider, $siteKey)) ?? 0);
        $cap = self::capFor($provider);
        $allowed = $cap === null || $used < $cap;

        return new DailyCap($allowed, $used, $cap, $allowed ? null : $this->nextMidnight());
    }

    /** Counts bulk lookups against today's allowance and returns the new total. */
    public function consumeBulk(string $provider, string $siteKey, int $count = 1): int
    {
        return $this->kv->increment($this->dayKey($provider, $siteKey), $count, 2 * 86400);
    }

    /** The daily bulk cap for a provider, or null when there is none. */
    public static function capFor(string $provider): ?int
    {
        return $provider === 'nominatim' ? self::PUBLIC_NOMINATIM_DAILY_CAP : null;
    }

    private function dayKey(string $provider, string $siteKey): string
    {
        return sprintf('rumbo:bulk:%s:%s:%s', $provider, $siteKey, $this->utcNow()->format('Y-m-d'));
    }

    private function nextMidnight(): DateTimeImmutable
    {
        return $this->utcNow()->setTime(0, 0)->modify('+1 day');
    }

    private function utcNow(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function seconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
