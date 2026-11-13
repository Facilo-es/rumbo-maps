<?php

namespace facilo\rumbo\Craft\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use facilo\rumbo\Core\EtherMapping;
use facilo\rumbo\Craft\gql\LocationGql;
use facilo\rumbo\Craft\LocationValue;
use facilo\rumbo\Craft\web\FieldAsset;
use facilo\rumbo\Plugin;
use yii\db\Schema;

/** Field type "Location (Rumbo)" (F1). The value lives in the content JSON and in {{%rumbo_locations}} for queries. */
class Location extends Field implements PreviewableFieldInterface
{
    public ?float $defaultLat = 40.4168;
    public ?float $defaultLng = -3.7038;
    public int $defaultZoom = 12;
    public int $editorHeight = 400;
    /** @var string[] ISO alpha-2 codes, lower case */
    public array $countries = [];
    /** @var float[]|null [minLng, minLat, maxLng, maxLat] */
    public ?array $searchBias = null;
    public bool $showLatLng = false;
    public bool $serviceRadius = false;
    public ?string $mapStyle = null;

    public static function displayName(): string
    {
        return Craft::t('rumbo-maps', 'Location (Rumbo)');
    }

    public static function icon(): string
    {
        return 'location-dot';
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|null', LocationValue::class);
    }

    public static function dbType(): string
    {
        return Schema::TYPE_JSON;
    }

    public function __construct($config = [])
    {
        // Settings a moved field may still carry from the old plugin are converted, not rejected.
        if (isset($config['lat']) || isset($config['hideLatLng']) || isset($config['boundary'])) {
            $config = array_merge($config, EtherMapping::convertSettings($config)['settings']);
        }
        // The settings form posts these as text.
        if (isset($config['countries']) && is_string($config['countries'])) {
            $config['countries'] = self::parseCountries($config['countries']);
        }
        if (array_key_exists('searchBias', $config) && !is_array($config['searchBias'])) {
            $config['searchBias'] = self::parseBias((string)$config['searchBias']);
        }
        foreach (array_keys($config) as $key) {
            if (!property_exists($this, $key) && !in_array($key, ['handle', 'name', 'translationKeyFormat'], true) && !method_exists($this, 'set' . ucfirst($key))) {
                unset($config[$key]);
            }
        }
        parent::__construct($config);
    }

    /** "es, PT" to ['es', 'pt']; anything that isn't a two-letter code is dropped. */
    public static function parseCountries(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtolower($text)) ?: [] as $code) {
            if (preg_match('/^[a-z]{2}$/', $code)) {
                $out[$code] = $code;
            }
        }
        return array_values($out);
    }

    /** "minLng, minLat, maxLng, maxLat" to four floats, or null when empty or not a valid box. */
    public static function parseBias(string $text): ?array
    {
        $nums = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', trim($text)) ?: []), fn($v) => $v !== ''));
        if (count($nums) !== 4 || array_filter($nums, fn($v) => !is_numeric($v))) {
            return null;
        }
        [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', $nums);
        if ($minLng >= $maxLng || $minLat >= $maxLat || abs($minLat) > 90 || abs($maxLat) > 90 || abs($minLng) > 180 || abs($maxLng) > 180) {
            return null;
        }
        return [$minLng, $minLat, $maxLng, $maxLat];
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['defaultLat'], 'number', 'min' => -90, 'max' => 90];
        $rules[] = [['defaultLng'], 'number', 'min' => -180, 'max' => 180];
        $rules[] = [['defaultZoom'], 'integer', 'min' => 0, 'max' => 22];
        $rules[] = [['editorHeight'], 'integer', 'min' => 150, 'max' => 1200];
        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('rumbo-maps/_field/settings', ['field' => $this]);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof LocationValue) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $value = Json::decodeIfJson($value);
            // A plain address (Feed Me, CSV, an API): kept as is, and the queue finds its position (F13).
            if (is_string($value)) {
                $value = ['address' => trim($value)];
            }
        }
        if (!is_array($value)) {
            return new LocationValue($this->context($element));
        }
        // Content written by the old plugin (before or without a move): convert the same way the move does.
        $parts = $value['parts'] ?? [];
        if (is_string($parts)) {
            $parts = Json::decodeIfJson($parts) ?: [];
        }
        if (is_array($parts) && $parts && !array_key_exists('countryCode', $parts) && !isset($value['source'])) {
            $converted = EtherMapping::convert($value);
            if ($converted['ok']) {
                $value = array_merge($value, $converted['row']);
            }
        }
        if (isset($value['parts']) && is_array($value['parts'])) {
            // Posted from the editor, where the street input is "street".
            $value['parts'] = array_filter($value['parts'], fn($v) => $v !== '' && $v !== null);
        }
        if (isset($value['original'])) {
            // An editor changed the address lines by hand and left the address itself alone: rebuild it from the lines.
            $original = is_string($value['original']) ? (Json::decodeIfJson($value['original']) ?: []) : (array)$value['original'];
            unset($value['original']);
            $before = \facilo\rumbo\Core\AddressParts::fromArray((array)($original['parts'] ?? []))->toArray();
            $now = \facilo\rumbo\Core\AddressParts::fromArray((array)($value['parts'] ?? []));
            if ($now->toArray() != $before && (string)($value['address'] ?? '') === (string)($original['address'] ?? '')) {
                $value['address'] = $now->oneLine();
            }
        }
        return new LocationValue(array_merge($value, $this->context($element)));
    }

    private function context(?ElementInterface $element): array
    {
        return ['elementId' => $element?->id, 'siteId' => $element?->siteId, 'fieldId' => $this->id];
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        return $value instanceof LocationValue && !$value->isEmpty() ? $value->toArray() : null;
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof LocationValue || $value->isEmpty();
    }

    public function getElementValidationRules(): array
    {
        return [[function(ElementInterface $element) {
            $value = $element->getFieldValue($this->handle);
            if (!$value instanceof LocationValue || !$value->hasPosition()) {
                return;
            }
            if (abs((float)$value->lat) > 90 || abs((float)$value->lng) > 180) {
                $element->addError("field:$this->handle", Craft::t('rumbo-maps', 'Latitude must be between -90 and 90, and longitude between -180 and 180.'));
            }
        }]];
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(FieldAsset::class);
        $settings = Plugin::getInstance()->getSettings();
        $name = $this->handle;
        $current = $value instanceof LocationValue ? $value->toArray() : null;
        if ($current !== null) {
            // The editor shows one "Street and number" line.
            $current['parts']['street'] = trim(($current['parts']['street'] ?? '') . ' ' . ($current['parts']['number'] ?? '')) ?: null;
            $current['parts']['number'] = null;
            unset($current['parts']['address']);
        }
        $config = [
            'name' => $view->namespaceInputName($name),
            'value' => $current,
            'defaultCenter' => [$this->defaultLng, $this->defaultLat],
            'defaultZoom' => $this->defaultZoom,
            'height' => $this->editorHeight,
            'showLatLng' => $this->showLatLng,
            'countries' => $this->countries,
            'bias' => $this->searchBias,
            'serviceRadius' => $this->serviceRadius,
            'style' => Plugin::getInstance()->locations->styleUrl($this->mapStyle ?: $settings->mapStyle),
            'suggestUrl' => UrlHelper::actionUrl('rumbo-maps/geo/suggest'),
            'reverseUrl' => UrlHelper::actionUrl('rumbo-maps/geo/reverse'),
            'strings' => self::editorStrings(),
            // F1: a provider error links to the key test, for people who can open it.
            'keyTestUrl' => Craft::$app->getUser()->checkPermission('utility:rumbo-keys') ? UrlHelper::cpUrl('utilities/rumbo-keys') : null,
            'keyTestLabel' => Craft::t('rumbo-maps', 'Test my keys'),
        ];
        return $view->renderTemplate('rumbo-maps/_field/input', [
            'field' => $this,
            'name' => $name,
            'value' => $value,
            'config' => $config,
        ]);
    }

    public static function editorStrings(): array
    {
        $t = fn(string $s) => Craft::t('rumbo-maps', $s);
        return [
            'searchPlaceholder' => $t('Search for an address or place'),
            'updateFromPin' => $t('Update address from pin'),
            'clear' => $t('Clear'),
            'street' => $t('Street and number'),
            'city' => $t('City'),
            'postcode' => $t('Postcode'),
            'county' => $t('County'),
            'state' => $t('State or region'),
            'country' => $t('Country'),
            'latitude' => $t('Latitude'),
            'longitude' => $t('Longitude'),
            'notFound' => $t('We couldn’t find that address. Try adding a town or postcode, or place the pin by hand.'),
            'providerError' => $t('{provider} said: {message}. Check your key on the Test my keys page.'),
        ];
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        return $value instanceof LocationValue ? htmlspecialchars($value->address) : '';
    }

    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        Plugin::getInstance()->locations->saveFor($element, $this, $element->getFieldValue($this->handle));
        parent::afterElementSave($element, $isNew);
    }

    public function getContentGqlType(): \GraphQL\Type\Definition\Type|array
    {
        return LocationGql::type($this);
    }

    public function getContentGqlQueryArgumentType(): \GraphQL\Type\Definition\Type|array
    {
        return LocationGql::argumentType();
    }

    /** Proximity arrays are handled by the Proximity service; other values match on "has a location". */
    public static function queryCondition(array $instances, mixed $value, array &$params): array|string|\yii\db\ExpressionInterface|false|null
    {
        if (is_array($value) && !array_is_list($value)) {
            return null;
        }
        return parent::queryCondition($instances, $value, $params);
    }
}
