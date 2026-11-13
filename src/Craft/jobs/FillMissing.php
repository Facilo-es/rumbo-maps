<?php

namespace facilo\rumbo\Craft\jobs;

use Craft;
use craft\db\Query;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use facilo\rumbo\Core\Throttle;
use facilo\rumbo\Craft\CraftPorts;
use facilo\rumbo\migrations\Install;
use facilo\rumbo\Plugin;

/**
 * Looks up positions for locations that have an address but no coordinates yet (F13):
 * Feed Me and CSV imports, and the "Fill missing coordinates" button. Imports never wait for it.
 * The public OpenStreetMap server allows 2,000 a day per site; past that the job waits for midnight UTC.
 */
class FillMissing extends BaseJob
{
    public const QUEUED = 'rumbo-maps:fill-queued';
    public const STATUS = 'rumbo-maps:fill-status';
    private const BATCH = 100;

    public ?string $resumesAt = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $geocoding = $plugin->geocoding;
        $provider = $geocoding->bulkProvider();
        $throttle = new Throttle($ports = new CraftPorts(), $ports, $ports);
        $siteKey = parse_url((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST) ?: 'site';
        $rows = (new Query())->from(Install::LOCATIONS)->where(['source' => 'pending'])->orderBy(['id' => SORT_ASC])->limit(self::BATCH)->all();
        foreach ($rows as $i => $row) {
            $this->setProgress($queue, $i / max(1, count($rows)));
            $cap = $throttle->dailyBulkAllowance($provider, $siteKey);
            if (!$cap->allowed) {
                $this->again($cap->resumesAt ? $cap->resumesAt->getTimestamp() - time() + 60 : 3600, $cap->resumesAt?->format(DATE_ATOM));
                return;
            }
            if ($provider !== 'nominatim') {
                // Keyed providers: up to 10 a second. The public server is held to 1 a second by Geocoding::get().
                $throttle->waitTurn($provider, 10);
            }
            $result = $geocoding->place((string)$row['address'], $row['countryCode'] ?: null);
            if ($result === null && $geocoding->lastError !== null) {
                // The provider failed (key, quota, network): keep the row waiting and try again later.
                $this->again(900, null, $geocoding->lastError);
                return;
            }
            $throttle->consumeBulk($provider, $siteKey);
            $plugin->locations->applyLookup($row, $result, 'notfound');
        }
        if ((new Query())->from(Install::LOCATIONS)->where(['source' => 'pending'])->exists()) {
            $this->again(0);
            return;
        }
        Craft::$app->getCache()->delete(self::QUEUED);
        Craft::$app->getCache()->set(self::STATUS, ['pending' => 0, 'resumesAt' => null, 'error' => null, 'cap' => Throttle::capFor($provider)], 86400);
    }

    /** Pushes the next batch, after $delay seconds, and records what the progress page shows. */
    private function again(int $delay, ?string $resumesAt = null, ?string $error = null): void
    {
        $pending = (int)(new Query())->from(Install::LOCATIONS)->where(['source' => 'pending'])->count();
        Craft::$app->getCache()->set(self::STATUS, [
            'pending' => $pending,
            'resumesAt' => $resumesAt,
            'error' => $error,
            'cap' => Throttle::capFor(Plugin::getInstance()->geocoding->bulkProvider()),
        ], 2 * 86400);
        Craft::$app->getCache()->set(self::QUEUED, true, $delay + 3600);
        Queue::push(new self(['resumesAt' => $resumesAt]), null, max(0, $delay));
    }

    protected function defaultDescription(): ?string
    {
        if ($this->resumesAt) {
            return Craft::t('rumbo-maps', 'Looking up missing positions (paused until {time}: OpenStreetMap allows 2,000 a day)', [
                'time' => Craft::$app->getFormatter()->asDatetime($this->resumesAt, 'short'),
            ]);
        }
        return Craft::t('rumbo-maps', 'Looking up missing positions');
    }
}
