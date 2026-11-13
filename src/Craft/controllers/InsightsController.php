<?php

namespace facilo\rumbo\Craft\controllers;

use craft\web\Controller;
use facilo\rumbo\Plugin;
use yii\web\Response;

/** Rumbo Maps → Search insights (F10): where people searched, and where they found nothing. */
class InsightsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-rumbo-maps');
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        [$from, $to] = $this->range();
        $summary = Plugin::getInstance()->insights->summary($from, $to);
        return $this->renderTemplate('rumbo-maps/insights/index', [
            'isPro' => Plugin::getInstance()->isPro(),
            'from' => $from,
            'to' => $to,
            'summary' => $summary,
            'map' => Plugin::getInstance()->isPro() ? Plugin::getInstance()->insights->dotMap($summary, 320) : '',
            'month' => Plugin::getInstance()->insights->month(),
        ]);
    }

    public function actionCsv(): Response
    {
        [$from, $to] = $this->range();
        $rows = Plugin::getInstance()->insights->rows($from, $to);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['date', 'place', 'searches', 'noResults', 'usedMyLocation']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['date'], $r['placeLabel'], $r['searches'], $r['noResults'], $r['usedMyLocation']]);
        }
        rewind($out);
        return $this->response->sendContentAsFile(stream_get_contents($out), "rumbo-insights-$from-$to.csv", ['mimeType' => 'text/csv']);
    }

    private function range(): array
    {
        $from = (string)$this->request->getQueryParam('from', (new \DateTime('-30 days'))->format('Y-m-d'));
        $to = (string)$this->request->getQueryParam('to', (new \DateTime())->format('Y-m-d'));
        $ok = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        return [$ok($from) ? $from : date('Y-m-d', strtotime('-30 days')), $ok($to) ? $to : date('Y-m-d')];
    }
}
