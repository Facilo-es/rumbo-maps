<?php

namespace facilo\rumbo\Craft;

use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use facilo\rumbo\Core\Geo;
use facilo\rumbo\migrations\Install;
use facilo\rumbo\Plugin;
use yii\db\Expression;

/** Daily locator search totals (F12). No IP, no user, no cookie; coordinates rounded to ~11 km. */
class Insights extends Component
{
    public function record(int $siteId, string $placeLabel, ?float $lat, ?float $lng, bool $noResults, bool $usedMyLocation): void
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->collectInsights || !Plugin::getInstance()->isPro()) {
            return;
        }
        $label = mb_substr(trim($placeLabel) !== '' ? trim($placeLabel) : '—', 0, 120);
        $date = (new \DateTime())->format('Y-m-d');
        $key = ['date' => $date, 'siteId' => $siteId, 'placeLabel' => $label];
        $exists = (new Query())->from(Install::INSIGHTS)->where($key)->exists();
        if (!$exists) {
            Db::upsert(Install::INSIGHTS, $key + [
                'latRounded' => $lat === null ? null : Geo::roundForInsights($lat),
                'lngRounded' => $lng === null ? null : Geo::roundForInsights($lng),
                'searches' => 0, 'noResults' => 0, 'usedMyLocation' => 0,
            ], false);
        }
        Db::update(Install::INSIGHTS, [
            'searches' => new Expression('[[searches]] + 1'),
            'noResults' => new Expression('[[noResults]] + ' . (int)$noResults),
            'usedMyLocation' => new Expression('[[usedMyLocation]] + ' . (int)$usedMyLocation),
        ], $key, [], false);
    }

    /** Totals, top places and missed demand for a date range. */
    public function summary(string $from, string $to, ?int $siteId = null): array
    {
        $base = (new Query())->from(Install::INSIGHTS)->where(['between', 'date', $from, $to]);
        if ($siteId) {
            $base->andWhere(['siteId' => $siteId]);
        }
        $totals = (clone $base)->select(['searches' => 'SUM([[searches]])', 'noResults' => 'SUM([[noResults]])', 'usedMyLocation' => 'SUM([[usedMyLocation]])'])->one() ?: [];
        $places = fn(string $col) => (clone $base)
            ->select(['placeLabel', 'lat' => 'MAX([[latRounded]])', 'lng' => 'MAX([[lngRounded]])', 'n' => "SUM([[$col]])"])
            ->groupBy(['placeLabel'])
            ->having(['>', "SUM([[$col]])", 0])
            ->orderBy(['n' => SORT_DESC, 'placeLabel' => SORT_ASC])
            ->limit(10)->all();
        return [
            'searches' => (int)($totals['searches'] ?? 0),
            'noResults' => (int)($totals['noResults'] ?? 0),
            'usedMyLocation' => (int)($totals['usedMyLocation'] ?? 0),
            'top' => $places('searches'),
            'missed' => $places('noResults'),
        ];
    }

    /** This month so far against the same days of last month, for the widget. */
    public function month(?int $siteId = null): array
    {
        $now = new \DateTime();
        $current = $this->summary($now->format('Y-m-01'), $now->format('Y-m-d'), $siteId);
        // On 7 November the change compares 1-7 November with 1-7 October, not with all of October.
        $prev = new \DateTime('first day of last month');
        $prevStart = $prev->format('Y-m-d');
        $prevEnd = $prev->format('Y-m-') . sprintf('%02d', min((int)$now->format('j'), (int)$prev->format('t')));
        $previous = $this->summary($prevStart, $prevEnd, $siteId);
        $current['change'] = $previous['searches'] > 0 ? round(($current['searches'] - $previous['searches']) / $previous['searches'] * 100) : null;
        return $current;
    }

    /** Dots for the searched places: blue where people found a location, red where they found nothing. */
    public function markers(array $summary): array
    {
        $dot = fn(string $c) => 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"><circle cx="7" cy="7" r="6" fill="' . $c . '" fill-opacity="0.8" stroke="#fff" stroke-width="1.5"/></svg>');
        $out = [];
        foreach (['top' => '#2563eb', 'missed' => '#dc2626'] as $key => $colour) {
            foreach ($summary[$key] ?? [] as $p) {
                if ($p['lat'] === null || $p['lng'] === null || ($p['lat'] == 0 && $p['lng'] == 0)) {
                    continue;
                }
                $out[] = [
                    'lat' => (float)$p['lat'],
                    'lng' => (float)$p['lng'],
                    'title' => \Craft::t('rumbo-maps', $key === 'top' ? '{place} · {n} searches' : '{place} · {n} with nothing nearby', ['place' => $p['placeLabel'] ?: '—', 'n' => (int)$p['n']]),
                    'icon' => $dot($colour),
                ];
            }
        }
        return $out;
    }

    /** The small map of searched places for the widget and the Insights page, or an empty string with no places. */
    public function dotMap(array $summary, int $height = 220): string
    {
        $markers = $this->markers($summary);
        if (!$markers) {
            return '';
        }
        return '<div class="rumbo-insights-map">' . Plugin::getInstance()->locations->renderMap([], [
            'height' => $height,
            'consent' => false,
            'fitBounds' => count($markers) > 1,
            'zoom' => 9,
        ], $markers) . '</div>';
    }

    public function rows(string $from, string $to): array
    {
        return (new Query())->from(Install::INSIGHTS)->where(['between', 'date', $from, $to])->orderBy(['date' => SORT_ASC, 'placeLabel' => SORT_ASC])->all();
    }

    public function collectGarbage(): void
    {
        Db::delete(Install::INSIGHTS, ['<', 'date', (new \DateTime('-13 months'))->format('Y-m-d')]);
    }
}
