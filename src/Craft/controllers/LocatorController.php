<?php

namespace facilo\rumbo\Craft\controllers;

use Craft;
use craft\web\Controller;
use facilo\rumbo\Craft\Locator;
use facilo\rumbo\Plugin;
use yii\web\Response;

/** The public JSON search behind the store locator (F6). Anyone can call it, so every input is checked. */
class LocatorController extends Controller
{
    protected array|int|bool $allowAnonymous = ['search'];
    public $enableCsrfValidation = false;

    private const PER_MINUTE = 30;

    public function actionSearch(): Response
    {
        if (!Plugin::getInstance()->isPro()) {
            return $this->fail(404, 'notFound', 'The store locator needs Rumbo Maps Pro.');
        }
        $request = Craft::$app->getRequest();
        $key = 'rumbo-maps:rate:' . sha1((string)$request->getUserIP()) . ':' . intdiv(time(), 60);
        $cache = Craft::$app->getCache();
        $hits = (int)$cache->get($key) + 1;
        $cache->set($key, $hits, 70);
        if ($hits > self::PER_MINUTE) {
            return $this->fail(429, 'tooMany', Craft::t('rumbo-maps', 'Too many searches. Wait a minute and try again.'));
        }
        $options = Locator::fromToken((string)$request->getQueryParam('l', ''));
        if ($options === null) {
            return $this->fail(400, 'invalid', Craft::t('rumbo-maps', 'This search form is out of date. Reload the page.'));
        }
        $checked = Locator::input($request->getQueryParams());
        if ($checked['errors']) {
            return $this->fail(400, 'invalid', Craft::t('rumbo-maps', 'Check your search and try again.'), $checked['errors']);
        }
        $input = $checked['input'];
        if (empty($input['q']) && !isset($input['lat'])) {
            return $this->fail(400, 'invalid', Craft::t('rumbo-maps', 'Type a town or postcode.'));
        }
        $result = Locator::search(Locator::normalise($options), $input);
        if (!$result['ok']) {
            return $this->fail(200, 'notFound', Craft::t('rumbo-maps', 'We couldn’t find that place. Try a town or postcode.'));
        }
        $response = $this->asJson($result);
        $response->getHeaders()->set('Cache-Control', 'public, max-age=300');
        return $response;
    }

    private function fail(int $status, string $code, string $message, array $fields = []): Response
    {
        $response = $this->asJson(['ok' => false, 'code' => $code, 'errors' => [$message], 'fields' => $fields]);
        $response->setStatusCode($status);
        return $response;
    }
}
