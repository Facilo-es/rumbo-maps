<?php

namespace facilo\rumbo\Craft\utilities;

use Craft;
use craft\base\Utility;
use facilo\rumbo\Craft\AppleToken;
use facilo\rumbo\Craft\Locations;
use facilo\rumbo\Craft\web\FrontAsset;
use facilo\rumbo\Plugin;

/** Utilities → Test my keys (F8): per provider, load a map, run a lookup and a reverse lookup, and show the provider's own message. */
class KeyTest extends Utility
{
    use AdminOnly;

    public static function displayName(): string
    {
        return Craft::t('rumbo-maps', 'Test my map keys');
    }

    public static function id(): string
    {
        return 'rumbo-keys';
    }

    public static function icon(): ?string
    {
        return 'key';
    }

    /** Rows in the order of the settings page; OpenStreetMap stands for OpenFreeMap maps plus Nominatim lookups. */
    public const ROWS = ['openfreemap', 'google', 'mapbox', 'maptiler', 'here', 'apple'];

    public static function contentHtml(): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $rows = [];
        foreach (self::ROWS as $name) {
            $configured = match ($name) {
                'openfreemap' => true,
                'apple' => AppleToken::configured(),
                default => $settings->key($name, 'server') !== '' || $settings->key($name, 'browser') !== '',
            };
            $rows[] = [
                'name' => $name,
                'label' => Locations::PROVIDER_LABELS[$name],
                'configured' => $configured,
                'maps' => $settings->frontEndProvider() === $name,
                'lookups' => $settings->geocoder === ($name === 'openfreemap' ? 'nominatim' : $name),
            ];
        }
        $view = Craft::$app->getView();
        $bundle = $view->registerAssetBundle(FrontAsset::class);
        return $view->renderTemplate('rumbo-maps/utilities/keys', [
            'rows' => $rows,
            'pairingWarning' => $settings->pairingWarning(),
            'isPro' => $plugin->isPro(),
            'mapModuleUrl' => $bundle->baseUrl . '/rumbo-map.js',
            'mapCssUrl' => $bundle->baseUrl . '/rumbo-map.css',
        ]);
    }
}
