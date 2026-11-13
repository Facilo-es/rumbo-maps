<?php

namespace facilo\rumbo\Craft\widgets;

use Craft;
use craft\base\Widget;
use facilo\rumbo\Plugin;

/** Dashboard widget: this month's locator searches and where people searched with no store nearby (F10). */
class InsightsWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('rumbo-maps', 'Where people look for you');
    }

    public static function icon(): ?string
    {
        return 'magnifying-glass-location';
    }

    public function getBodyHtml(): ?string
    {
        if (!Plugin::getInstance()->isPro()) {
            return '<p class="light">' . Craft::t('rumbo-maps', 'Search insights come with Rumbo Maps Pro.') . '</p>';
        }
        $plugin = Plugin::getInstance();
        $month = $plugin->insights->month();
        return Craft::$app->getView()->renderTemplate('rumbo-maps/widgets/insights', [
            'month' => $month,
            'map' => $month['searches'] ? $plugin->insights->dotMap($month) : '',
            'within' => $plugin->getSettings()->defaultRadius,
            'unit' => $plugin->getSettings()->unit,
        ]);
    }
}
