<?php

namespace facilo\rumbo\Craft\controllers;

use Craft;
use craft\web\Controller;
use facilo\rumbo\Craft\Locator;
use facilo\rumbo\Craft\Settings;
use facilo\rumbo\Plugin;
use yii\web\Response;

/** Settings → Locator: the live preview, drawn with example locations rather than the site's own (F6). */
class SettingsController extends Controller
{
    /** Example points around central Madrid, so the preview never shows a real business. */
    public const EXAMPLES = [
        [40.4169, -3.7035], [40.4240, -3.6920], [40.4093, -3.7130], [40.4310, -3.7040], [40.4020, -3.6950],
        [40.4380, -3.6800], [40.3960, -3.7200], [40.4450, -3.7150], [40.3890, -3.6880], [40.4520, -3.6950],
        [40.3810, -3.7300], [40.4600, -3.7000],
    ];
    public const ORIGIN = [40.4200, -3.7070];

    public function actionPreview(): Response
    {
        $this->requireAdmin(false);
        $q = $this->request;
        $look = in_array($q->getQueryParam('look'), Settings::LOOKS, true) ? $q->getQueryParam('look') : 'side';
        $unit = $q->getQueryParam('unit') === 'mi' ? 'mi' : 'km';
        $within = max(1, min(500, (int)$q->getQueryParam('within', 25)));
        $limit = max(1, min(100, (int)$q->getQueryParam('limit', 20)));
        $settings = Plugin::getInstance()->getSettings();

        $results = [];
        foreach (self::EXAMPLES as $i => [$lat, $lng]) {
            $d = self::distance(self::ORIGIN, [$lat, $lng], $unit);
            $results[] = [
                'id' => $i + 1,
                'title' => Craft::t('rumbo-maps', 'Example location {n}', ['n' => $i + 1]),
                'url' => null,
                'lat' => $lat,
                'lng' => $lng,
                'address' => Craft::t('rumbo-maps', 'Example street {n}, Madrid', ['n' => ($i + 1) * 7]),
                'distance' => round($d, 2),
                'directionsUrl' => '',
                'fields' => [],
            ];
        }
        usort($results, fn($a, $b) => $a['distance'] <=> $b['distance']);
        $results = array_slice(array_values(array_filter($results, fn($r) => $r['distance'] <= $within)), 0, $limit);
        $locations = Plugin::getInstance()->locations;
        $config = [
            'endpoint' => null,
            'look' => $look,
            'unit' => $unit,
            'limit' => $limit,
            'lang' => Craft::$app->language,
            'style' => $locations->styleUrl($settings->mapStyle),
            'markerColor' => $settings->markerColor,
            'provider' => $locations->providerConfig(),
            'center' => [self::ORIGIN[1], self::ORIGIN[0]],
            'zoom' => 12,
            'results' => $results,
            'origin' => ['label' => 'Madrid', 'lat' => self::ORIGIN[0], 'lng' => self::ORIGIN[1]],
            'consentRequired' => false,
            'strings' => Locator::strings(),
        ];
        $this->response->headers->set('X-Robots-Tag', 'noindex');
        return $this->renderTemplate('rumbo-maps/settings/_preview', [
            'id' => 'rumbo-preview',
            'look' => $look,
            'config' => $config,
            'results' => $results,
            'origin' => $config['origin'],
            'total' => count($results),
            'within' => $within,
            'unit' => $unit,
            'q' => 'Madrid',
            'accentColor' => $settings->accentColor,
            'corners' => $settings->corners,
            'font' => $settings->font,
            'consentRequired' => false,
        ]);
    }

    private static function distance(array $a, array $b, string $unit): float
    {
        $r = $unit === 'mi' ? 3958.8 : 6371.0;
        [$la1, $lo1, $la2, $lo2] = array_map('deg2rad', [$a[0], $a[1], $b[0], $b[1]]);
        $h = sin(($la2 - $la1) / 2) ** 2 + cos($la1) * cos($la2) * sin(($lo2 - $lo1) / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($h)));
    }
}
