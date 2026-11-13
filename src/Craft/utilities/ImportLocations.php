<?php

namespace facilo\rumbo\Craft\utilities;

use Craft;
use craft\base\Utility;
use craft\db\Query;
use facilo\rumbo\Craft\CsvImport;
use facilo\rumbo\Craft\jobs\CsvImportJob;
use facilo\rumbo\Craft\jobs\FillMissing;
use facilo\rumbo\migrations\Install;
use facilo\rumbo\Plugin;

/** Utilities → Import locations (F13): CSV upload, column map, 10-row preview, and "Fill missing coordinates". */
class ImportLocations extends Utility
{
    use AdminOnly;

    public static function displayName(): string
    {
        return Craft::t('rumbo-maps', 'Import locations');
    }

    public static function id(): string
    {
        return 'rumbo-import';
    }

    public static function icon(): ?string
    {
        return 'file-import';
    }

    public static function contentHtml(): string
    {
        $session = Craft::$app->getSession();
        $upload = $session->get('rumbo-maps:csv');
        $preview = null;
        if ($upload && is_file($upload['file'])) {
            $csv = CsvImport::read($upload['file']);
            $preview = ['headers' => $csv['headers'], 'rows' => array_slice($csv['rows'], 0, CsvImport::PREVIEW), 'total' => count($csv['rows']), 'guess' => CsvImport::guess($csv['headers']), 'name' => $upload['name']];
        }
        $cache = Craft::$app->getCache();
        return Craft::$app->getView()->renderTemplate('rumbo-maps/utilities/import', [
            'isPro' => Plugin::getInstance()->isPro(),
            'targets' => CsvImport::targets(),
            'preview' => $preview,
            'imported' => $cache->get(CsvImportJob::STATUS),
            'fill' => $cache->get(FillMissing::STATUS),
            'pending' => (int)(new Query())->from(Install::LOCATIONS)->where(['source' => 'pending'])->count(),
            'missing' => (int)(new Query())->from(Install::LOCATIONS)->where(['and', ['lat' => null], ['not', ['address' => null]], ['not', ['address' => '']], ['or', ['source' => null], ['not in', 'source', ['cleared', 'pending']]]])->count(),
            'provider' => Plugin::getInstance()->geocoding->bulkProvider(),
        ]);
    }
}
