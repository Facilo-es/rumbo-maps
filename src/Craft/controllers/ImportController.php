<?php

namespace facilo\rumbo\Craft\controllers;

use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use craft\web\UploadedFile;
use facilo\rumbo\Craft\CsvImport;
use facilo\rumbo\Craft\jobs\CsvImportJob;
use facilo\rumbo\Plugin;
use yii\web\Response;

/** Utilities → Import locations (F13). Admins only, Pro only. */
class ImportController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);
        if (!Plugin::getInstance()->isPro()) {
            throw new \yii\web\ForbiddenHttpException('Rumbo Maps Pro');
        }
        return parent::beforeAction($action);
    }

    public function actionUpload(): Response
    {
        $file = UploadedFile::getInstanceByName('csv');
        $error = match (true) {
            !$file || $file->getHasError() => Craft::t('rumbo-maps', 'Choose a CSV file.'),
            $file->size > CsvImport::MAX_BYTES => Craft::t('rumbo-maps', 'The file is over 10 MB. Split it and import each part.'),
            !in_array(strtolower($file->getExtension()), ['csv', 'txt'], true) => Craft::t('rumbo-maps', 'Only CSV files can be imported.'),
            default => null,
        };
        if ($error) {
            $this->setFailFlash($error);
            return $this->redirectToPostedUrl();
        }
        $dir = Craft::$app->getPath()->getRuntimePath() . '/rumbo-import';
        @mkdir($dir, 0775, true);
        $path = $dir . '/' . bin2hex(random_bytes(12)) . '.csv';
        $file->saveAs($path);
        try {
            CsvImport::read($path);
        } catch (\Throwable $e) {
            @unlink($path);
            $this->setFailFlash($e->getMessage());
            return $this->redirectToPostedUrl();
        }
        Craft::$app->getSession()->set('rumbo-maps:csv', ['file' => $path, 'name' => $file->name]);
        return $this->redirectToPostedUrl();
    }

    public function actionRun(): Response
    {
        $session = Craft::$app->getSession();
        $upload = $session->get('rumbo-maps:csv');
        [$sectionId, $typeId, $field] = array_pad(explode(':', (string)$this->request->getBodyParam('target')), 3, '');
        $allowed = array_column(CsvImport::targets(), 'value');
        if (!$upload || !is_file($upload['file']) || !in_array("$sectionId:$typeId:$field", $allowed, true)) {
            $this->setFailFlash(Craft::t('rumbo-maps', 'Upload the file again and choose where it goes.'));
            return $this->redirectToPostedUrl();
        }
        $map = array_map(fn($t) => preg_match('/^(title|address|lat|lng|field:[A-Za-z0-9_]+)?$/', (string)$t) ? (string)$t : '', (array)$this->request->getBodyParam('map', []));
        if (!in_array('address', $map, true) && !(in_array('lat', $map, true) && in_array('lng', $map, true))) {
            $this->setFailFlash(Craft::t('rumbo-maps', 'Pick the address column, or both latitude and longitude.'));
            return $this->redirectToPostedUrl();
        }
        Craft::$app->getCache()->delete(CsvImportJob::STATUS);
        Queue::push(new CsvImportJob([
            'file' => $upload['file'], 'map' => $map, 'sectionId' => (int)$sectionId, 'typeId' => (int)$typeId,
            'field' => $field, 'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
        ]));
        $session->remove('rumbo-maps:csv');
        $this->setSuccessFlash(Craft::t('rumbo-maps', 'Import started. Your entries appear as it runs.'));
        return $this->redirectToPostedUrl();
    }

    public function actionCancel(): Response
    {
        $upload = Craft::$app->getSession()->get('rumbo-maps:csv');
        if ($upload) {
            @unlink($upload['file']);
        }
        Craft::$app->getSession()->remove('rumbo-maps:csv');
        return $this->redirectToPostedUrl();
    }

    public function actionFill(): Response
    {
        $n = Plugin::getInstance()->locations->markMissing();
        $this->setSuccessFlash(Craft::t('rumbo-maps', '{n} addresses are waiting for a position.', ['n' => $n]));
        return $this->redirectToPostedUrl();
    }
}
