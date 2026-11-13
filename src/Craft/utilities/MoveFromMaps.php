<?php

namespace facilo\rumbo\Craft\utilities;

use Craft;
use craft\base\Utility;
use facilo\rumbo\Core\TemplateScanner;
use facilo\rumbo\Plugin;

/** Utilities → Move from Maps (F3): a dry run, the move itself, and the template check. */
class MoveFromMaps extends Utility
{
    use AdminOnly;

    public static function displayName(): string
    {
        return Craft::t('rumbo-maps', 'Move from Maps');
    }

    public static function id(): string
    {
        return 'rumbo-move';
    }

    public static function icon(): ?string
    {
        return 'truck-moving';
    }

    public static function contentHtml(): string
    {
        $mover = Plugin::getInstance()->mover;
        $fields = $mover->fields();
        foreach ($fields as &$f) {
            $f['last'] = $mover->lastMove((int)$f['id']);
        }
        unset($f);
        $settings = Plugin::getInstance()->getSettings();
        $scan = (new TemplateScanner())->scanTree(Craft::$app->getPath()->getSiteTemplatesPath(), $settings->extraTemplateRoots);
        return Craft::$app->getView()->renderTemplate('rumbo-maps/utilities/move', [
            'oldTable' => $mover->oldTableExists(),
            'fields' => $fields,
            'dryRun' => Craft::$app->getSession()->get('rumbo-maps:dry-run'),
            'scan' => $scan,
            'scanSummary' => TemplateScanner::summary($scan['findings']),
            'isPro' => Plugin::getInstance()->isPro(),
        ]);
    }
}
