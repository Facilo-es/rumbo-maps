<?php

namespace facilo\rumbo\Craft\jobs;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\queue\BaseJob;
use facilo\rumbo\migrations\Install;
use facilo\rumbo\Plugin;

/**
 * Google lets Geocoding coordinates be kept for 30 days. Positions older than that are
 * looked up again, or cleared if the setting says so (F13).
 */
class GoogleRefresh extends BaseJob
{
    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $rows = (new Query())->from(Install::LOCATIONS)
            ->where(['source' => 'google'])
            ->andWhere(['<', 'geocodedAt', Db::prepareDateForDb(new \DateTime('-30 days'))])
            ->limit(500)
            ->all();
        $clear = $plugin->getSettings()->googleRefresh === 'clear' || !$plugin->isPro();
        foreach ($rows as $i => $row) {
            $this->setProgress($queue, $i / max(1, count($rows)));
            $result = $clear || trim((string)$row['address']) === '' ? null : $plugin->geocoding->place((string)$row['address']);
            // Through the element, so its content follows the table (the editor and GraphQL read the content).
            $plugin->locations->applyLookup($row, $result?->source === 'google' ? $result : null, 'cleared');
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('rumbo-maps', 'Refreshing Google positions');
    }
}
