<?php

namespace facilo\rumbo\Craft\controllers;

use Craft;
use craft\web\Controller;
use facilo\rumbo\Core\Providers\GeoOptions;
use facilo\rumbo\Craft\AppleToken;
use facilo\rumbo\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** Utilities → Test my map keys (F8), and the MapKit JS token Apple maps ask for. */
class KeysController extends Controller
{
    protected array|bool|int $allowAnonymous = ['mapkit-token'];

    /** Fixed test points: an address lookup, and a reverse lookup of Puerta del Sol. */
    public const TEST_QUERY = 'Plaza Mayor, Madrid';
    public const TEST_POINT = [40.4169, -3.7035];

    /** One provider: a lookup and a reverse lookup on the server, plus what the browser needs to load its map. */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(false);
        $name = (string)$this->request->getRequiredBodyParam('provider');
        $plugin = Plugin::getInstance();
        $geo = $plugin->geocoding;
        $o = new GeoOptions([], Craft::$app->language);
        $out = ['provider' => $name];

        $geocoderName = $name === 'openfreemap' ? 'nominatim' : $name;
        $out['lookup'] = $this->timed(function() use ($geo, $geocoderName, $o) {
            $found = $geo->geocoder($geocoderName)->lookup(self::TEST_QUERY, $o);
            return $found ? [true, $found[0]->formatted] : [false, Craft::t('rumbo-maps', 'The service answered but found nothing for a test address.')];
        });
        $out['reverse'] = $this->timed(function() use ($geo, $geocoderName, $o) {
            $found = $geo->geocoder($geocoderName)->reverse(self::TEST_POINT[0], self::TEST_POINT[1], $o);
            return $found ? [true, $found->formatted] : [false, Craft::t('rumbo-maps', 'The service answered but found no address at a test point.')];
        });

        $map = $plugin->locations->providerConfigFor($name);
        $out['map'] = $map;
        if ($map === null) {
            $out['mapMissing'] = $name === 'apple'
                ? Craft::t('rumbo-maps', 'Add the team ID, key ID and private key to show Apple maps.')
                : Craft::t('rumbo-maps', 'Add a browser key to show this provider’s maps.');
        }
        return $this->asJson($out);
    }

    /** A 30-minute MapKit JS token for this site, signed with the site's Apple key. Never the key itself. */
    public function actionMapkitToken(): Response
    {
        if (!Plugin::getInstance()->isPro() || !AppleToken::configured()) {
            throw new NotFoundHttpException();
        }
        $origin = $this->request->getHostInfo();
        try {
            $jwt = AppleToken::mapkit($origin);
        } catch (\Throwable $e) {
            Craft::warning('Apple MapKit token: ' . $e->getMessage(), 'rumbo-maps');
            $this->response->setStatusCode(500);
            return $this->asRaw('');
        }
        $this->response->getHeaders()->set('Cache-Control', 'no-store');
        return $this->asRaw($jwt);
    }

    /** @return array{ok:bool, message:string, ms:int} */
    private function timed(callable $fn): array
    {
        $start = microtime(true);
        try {
            [$ok, $message] = $fn();
        } catch (\Throwable $e) {
            [$ok, $message] = [false, $e->getMessage()];
        }
        return ['ok' => $ok, 'message' => $message, 'ms' => (int)((microtime(true) - $start) * 1000)];
    }
}
