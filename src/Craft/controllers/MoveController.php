<?php

namespace facilo\rumbo\Craft\controllers;

use Craft;
use craft\web\Controller;
use facilo\rumbo\Plugin;
use yii\web\Response;

/** Buttons on Utilities → Move from Maps (F3). */
class MoveController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireAdmin(false);
        return parent::beforeAction($action);
    }

    public function actionDryRun(): Response
    {
        $this->requirePostRequest();
        Craft::$app->getSession()->set('rumbo-maps:dry-run', Plugin::getInstance()->mover->dryRun($this->request->getBodyParam('field') ?: null));
        return $this->redirectToPostedUrl();
    }

    public function actionStart(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(true);
        $ids = Plugin::getInstance()->mover->queue($this->request->getBodyParam('field') ?: null);
        Craft::$app->getSession()->remove('rumbo-maps:dry-run');
        return $this->asSuccess(Craft::t('rumbo-maps', '{n, plural, =0{Nothing to move.} =1{The move has started. Keep this tab open or come back later.} other{# moves have started.}}', ['n' => count($ids)]));
    }
}
