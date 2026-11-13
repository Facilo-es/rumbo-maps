<?php

namespace facilo\rumbo\Craft\controllers;

use Craft;
use craft\web\Controller;
use facilo\rumbo\Core\Providers\GeoResult;
use facilo\rumbo\Plugin;
use yii\web\Response;

/** Address type-ahead and reverse lookups for the field editor in the control panel (F1, F8). */
class GeoController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        return parent::beforeAction($action);
    }

    public function actionSuggest(): Response
    {
        $request = Craft::$app->getRequest();
        $q = trim((string)$request->getParam('q', ''));
        if (mb_strlen($q) < 3) {
            return $this->asJson(['ok' => true, 'results' => []]);
        }
        $countries = array_filter(array_map('strval', (array)$request->getParam('countries', [])));
        $bias = $request->getParam('bias');
        $geo = Plugin::getInstance()->geocoding;
        $results = $geo->lookup(mb_substr($q, 0, 200), $countries, 5, is_array($bias) && count($bias) === 4 ? array_map('floatval', $bias) : null, true);
        return $this->asJson([
            'ok' => $geo->lastError === null,
            'error' => $geo->lastError,
            'results' => array_map(fn(GeoResult $r) => $r->toArray(), $results),
        ]);
    }

    public function actionReverse(): Response
    {
        $request = Craft::$app->getRequest();
        $lat = (float)$request->getRequiredParam('lat');
        $lng = (float)$request->getRequiredParam('lng');
        $geo = Plugin::getInstance()->geocoding;
        $result = $geo->reverse($lat, $lng);
        return $this->asJson(['ok' => $geo->lastError === null, 'error' => $geo->lastError, 'result' => $result?->toArray()]);
    }
}
