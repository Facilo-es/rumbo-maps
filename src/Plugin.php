<?php

namespace facilo\rumbo;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\db\ElementQuery;
use craft\events\CancelableEvent;
use craft\events\PopulateElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use facilo\rumbo\Craft\fields\Location;
use facilo\rumbo\Craft\Geocoding;
use facilo\rumbo\Craft\Insights;
use facilo\rumbo\Craft\LegacyVariable;
use facilo\rumbo\Craft\Locations;
use facilo\rumbo\Craft\Mover;
use facilo\rumbo\Craft\Proximity;
use facilo\rumbo\Craft\Settings;
use facilo\rumbo\Craft\TwigVariable;
use facilo\rumbo\Craft\utilities\ImportLocations;
use facilo\rumbo\Craft\utilities\KeyTest;
use facilo\rumbo\Craft\utilities\MoveFromMaps;
use facilo\rumbo\Craft\widgets\InsightsWidget;
use yii\base\Event;

/**
 * @property-read Locations $locations
 * @property-read Geocoding $geocoding
 * @property-read Mover $mover
 * @property-read Insights $insights
 * @property-read Proximity $proximity
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return ['components' => [
            'locations' => Locations::class,
            'geocoding' => Geocoding::class,
            'mover' => Mover::class,
            'insights' => Insights::class,
            'proximity' => Proximity::class,
        ]];
    }

    /** Pro features are on. A missing licence never changes the front end: Craft only shows its notice in the CP (F16). */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    public function init(): void
    {
        parent::init();
        $this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
            ? 'facilo\rumbo\Craft\console'
            : 'facilo\rumbo\Craft\controllers';

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Location::class;
        });
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = MoveFromMaps::class;
            $event->types[] = KeyTest::class;
            $event->types[] = ImportLocations::class;
        });
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = InsightsWidget::class;
        });
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('rumbo', TwigVariable::class);
            // The old plugin's variable, only while Maps itself is gone (F4).
            if (!Craft::$app->getPlugins()->isPluginEnabled('simplemap')) {
                $event->sender->set('maps', LegacyVariable::class);
                $event->sender->set('simpleMap', LegacyVariable::class);
            }
        });
        Craft::$app->getView()->registerTwigExtension(new \facilo\rumbo\Craft\TwigExtension());
        if (class_exists(\craft\feedme\services\Fields::class)) {
            Event::on(\craft\feedme\services\Fields::class, \craft\feedme\services\Fields::EVENT_REGISTER_FEED_ME_FIELDS, function($event) {
                $event->fields[] = \facilo\rumbo\Craft\feedme\LocationFeedMe::class;
            });
        }
        // The locator renders in site mode, so a site's templates/_rumbo/locator/ copies win over ours.
        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) {
            $event->roots['rumbo-maps'] ??= __DIR__ . '/templates';
        });

        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_PREPARE, function(CancelableEvent $event) {
            $this->proximity->afterPrepare($event->sender);
        });
        Event::on(ElementQuery::class, ElementQuery::EVENT_BEFORE_POPULATE_ELEMENT, function(PopulateElementEvent $event) {
            $this->proximity->beforePopulate($event->sender, $event);
        });
        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, function(PopulateElementEvent $event) {
            $this->proximity->afterPopulate($event->element);
        });
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->geocoding->collectGarbage();
            $this->insights->collectGarbage();
            $this->locations->scheduleGoogleRefresh();
        });
        // F3: project config that switches a Maps field to Rumbo on another environment also moves its locations.
        Craft::$app->getProjectConfig()->onUpdate(\craft\services\ProjectConfig::PATH_FIELDS . '.{uid}', function(\craft\events\ConfigEvent $event) {
            $this->mover->onFieldConfigUpdate($event);
        });
        // F4, F9: what a template asked for and didn't get, explained in the control panel.
        Event::on(\craft\helpers\Cp::class, \craft\helpers\Cp::EVENT_REGISTER_ALERTS, function(\craft\events\RegisterCpAlertsEvent $event) {
            if (!Craft::$app->getUser()->getIsAdmin()) {
                return;
            }
            foreach ($this->locations->notices() as $notice) {
                $event->alerts[] = \craft\helpers\Html::encode('Rumbo Maps: ' . $notice);
            }
        });
        // F14: Craft's own addresses get a position on save, in a queue job.
        Event::on(\craft\elements\Address::class, Element::EVENT_AFTER_SAVE, function(\craft\events\ModelEvent $event) {
            \facilo\rumbo\Craft\CraftAddresses::afterSave($event->sender, (bool)$event->isNew);
        });
        // F11: location redirect rules, on front-end page views only.
        $request = Craft::$app->getRequest();
        if (!$request->getIsConsoleRequest() && $request->getIsSiteRequest()) {
            Craft::$app->on(\yii\base\Application::EVENT_BEFORE_REQUEST, function() {
                \facilo\rumbo\Craft\Redirects::handle();
            });
        }
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['rumbo-maps/insights'] = 'rumbo-maps/insights/index';
            $event->rules['rumbo-maps/templates'] = 'rumbo-maps/move/templates';
        });
    }

    /** The settings form posts an empty table as text. */
    public function setSettings(array $settings): void
    {
        if (array_key_exists('redirects', $settings)) {
            $settings['redirects'] = is_array($settings['redirects']) ? array_values(array_filter($settings['redirects'], 'is_array')) : [];
        }
        parent::setSettings($settings);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return $this->tabbedSettings(false);
    }

    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->tabbedSettings(true);
    }

    /** Craft's own settings page, with tabs for the panes in settings/index.twig. */
    private function tabbedSettings(bool $readOnly): \yii\web\Response
    {
        $view = Craft::$app->getView();
        $html = $view->namespaceInputs(fn() => $readOnly
            ? (string)\craft\helpers\Html::disableInputs(fn() => $this->settingsHtml())
            : (string)$this->settingsHtml(), 'settings');
        /** @var \craft\web\Controller $controller */
        $controller = Craft::$app->controller;
        return $controller->renderTemplate('rumbo-maps/settings/_page', [
            'plugin' => $this,
            'settingsHtml' => $html,
            'readOnly' => $readOnly,
        ]);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('rumbo-maps/settings/index', [
            'settings' => $this->getSettings(),
            'isPro' => $this->isPro(),
        ]);
    }
}
