<?php

namespace facilo\rumbo\Craft\jobs;

use Craft;
use craft\queue\BaseJob;
use facilo\rumbo\Craft\CsvImport;
use facilo\rumbo\Plugin;

/** Utilities → Import locations (F13): one entry per row, then missing positions go to FillMissing. */
class CsvImportJob extends BaseJob
{
    public const STATUS = 'rumbo-maps:csv-status';

    public string $file = '';
    public array $map = [];
    public int $sectionId = 0;
    public int $typeId = 0;
    public string $field = '';
    public int $siteId = 0;

    public function execute($queue): void
    {
        $rows = CsvImport::read($this->file)['rows'];
        $done = 0;
        $errors = [];
        foreach ($rows as $i => $row) {
            $error = CsvImport::save($row, $this->map, $this->sectionId, $this->typeId, $this->field, $this->siteId);
            if ($error === null) {
                $done++;
            } elseif (count($errors) < 10) {
                $errors[] = Craft::t('rumbo-maps', 'Row {n}: {error}', ['n' => $i + 2, 'error' => $error]);
            }
            $this->setProgress($queue, ($i + 1) / max(1, count($rows)));
        }
        @unlink($this->file);
        Craft::$app->getCache()->set(self::STATUS, ['created' => $done, 'failed' => count($rows) - $done, 'errors' => $errors, 'at' => time()], 86400 * 7);
        Plugin::getInstance()->locations->queueFill();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('rumbo-maps', 'Importing locations');
    }
}
