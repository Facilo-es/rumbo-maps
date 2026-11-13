<?php

namespace facilo\rumbo\Craft;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\Categories;
use craft\fields\Dropdown;
use craft\fields\Lightswitch;
use craft\fields\Tags;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use facilo\rumbo\Core\Geo;
use facilo\rumbo\Plugin;
use Twig\Markup;

/** The store locator (F6): one search used by the Twig tag, the no-JS GET form and the JSON action. */
class Locator
{
    public const MAX_RADIUS = 500;

    /** Options the developer gave, signed so a visitor can't change the section or field. */
    public static function token(array $options): string
    {
        return Craft::$app->getSecurity()->hashData(Json::encode($options));
    }

    public static function fromToken(string $token): ?array
    {
        $data = Craft::$app->getSecurity()->validateData($token);
        return $data === false ? null : (Json::decodeIfJson($data) ?: null);
    }

    public static function normalise(array $options): array
    {
        $settings = Plugin::getInstance()->getSettings();
        return [
            'section' => $options['section'] ?? null,
            'field' => (string)($options['field'] ?? 'location'),
            'within' => (int)max(1, min(self::MAX_RADIUS, (int)($options['within'] ?? $settings->defaultRadius))),
            'unit' => ($options['unit'] ?? $settings->unit) === 'mi' ? 'mi' : 'km',
            'limit' => (int)max(1, min(100, (int)($options['limit'] ?? $settings->resultsPerPage))),
            'filters' => array_values(array_filter((array)($options['filters'] ?? []), 'is_string')),
            'cardFields' => array_values(array_filter((array)($options['cardFields'] ?? ['phone', 'website']), 'is_string')),
            'look' => in_array($options['look'] ?? null, Settings::LOOKS, true) ? $options['look'] : $settings->look,
            'site' => $options['site'] ?? Craft::$app->getSites()->getCurrentSite()->handle,
        ];
    }

    /**
     * Runs one search. $input holds q, lat, lng, radius, unit, limit, filters (already validated).
     *
     * @return array{ok:bool,results?:array,total?:int,unit?:string,within?:float,origin?:?array,nearest?:?array,code?:string,errors?:string[]}
     */
    public static function search(array $options, array $input, bool $record = true): array
    {
        $unit = $input['unit'] ?? $options['unit'];
        $within = (float)($input['radius'] ?? $options['within']);
        $limit = (int)($input['limit'] ?? $options['limit']);
        $usedMyLocation = isset($input['lat'], $input['lng']);
        if ($usedMyLocation) {
            $origin = ['label' => null, 'lat' => (float)$input['lat'], 'lng' => (float)$input['lng']];
        } elseif (!empty($input['q'])) {
            $place = Plugin::getInstance()->geocoding->place($input['q']);
            if ($place === null) {
                return ['ok' => false, 'code' => 'notFound', 'errors' => []];
            }
            $origin = ['label' => self::placeLabel($place->parts->toArray(), $place->formatted), 'lat' => $place->lat, 'lng' => $place->lng];
        } else {
            $origin = null;
        }
        $field = $options['field'];
        $query = self::baseQuery($options, $input['filters'] ?? []);
        if ($origin) {
            $query->$field(['near' => ['lat' => $origin['lat'], 'lng' => $origin['lng']], 'within' => $within, 'unit' => $unit])->orderBy('distance');
        } else {
            $query->$field(':notempty:')->orderBy(['title' => SORT_ASC]);
        }
        $total = (int)$query->count();
        $elements = $query->limit($limit)->all();
        $nearest = null;
        if ($origin && !$elements) {
            $near = self::baseQuery($options, $input['filters'] ?? [])
                ->$field(['near' => ['lat' => $origin['lat'], 'lng' => $origin['lng']], 'unit' => $unit])
                ->orderBy('distance')->one();
            $nearest = $near ? self::card($near, $options) : null;
        }
        if ($record && $origin) {
            $label = $origin['label'] ?? '';
            if ($usedMyLocation) {
                $back = Plugin::getInstance()->geocoding->reverse(Geo::roundForInsights($origin['lat']), Geo::roundForInsights($origin['lng']));
                $label = $back ? self::placeLabel($back->parts->toArray(), '') : '';
            }
            Plugin::getInstance()->insights->record(
                Craft::$app->getSites()->getCurrentSite()->id, $label, $origin['lat'], $origin['lng'], !$elements, $usedMyLocation,
            );
        }
        return [
            'ok' => true,
            'results' => array_map(fn($e) => self::card($e, $options), $elements),
            'total' => $total,
            'unit' => $unit,
            'within' => $within,
            'origin' => $origin,
            'nearest' => $nearest,
        ];
    }

    /**
     * Validates the public query through Core\LocatorInput. The form sends `q`; the validator calls it `query`.
     *
     * @return array{input:array,errors:array<string,string>}
     */
    public static function input(array $params): array
    {
        $raw = array_intersect_key($params, array_flip(['lat', 'lng', 'radius', 'unit', 'limit', 'filters']));
        if (isset($params['q']) && trim((string)$params['q']) !== '') {
            $raw['query'] = $params['q'];
        }
        if (isset($raw['filters']) && is_array($raw['filters'])) {
            $raw['filters'] = array_filter($raw['filters'], fn($v) => $v !== '' && $v !== []);
        }
        $checked = \facilo\rumbo\Core\LocatorInput::validate($raw);
        $v = $checked['values'];
        $input = ['q' => $v['query'], 'radius' => $v['radius'], 'unit' => $v['unit'], 'limit' => $v['limit'], 'filters' => $v['filters']];
        if ($v['lat'] !== null && $v['lng'] !== null) {
            $input['lat'] = $v['lat'];
            $input['lng'] = $v['lng'];
        }
        if (!isset($raw['radius'])) {
            unset($input['radius']);
        }
        if (!isset($raw['limit'])) {
            unset($input['limit']);
        }
        if (!isset($raw['unit'])) {
            unset($input['unit']);
        }
        return ['input' => $input, 'errors' => $checked['errors']];
    }

    /** The resolved town or postcode, never the raw text typed. */
    private static function placeLabel(array $parts, string $fallback): string
    {
        $label = trim(implode(' ', array_filter([$parts['postcode'] ?? null, $parts['city'] ?? null])));
        return $label !== '' ? $label : trim(explode(',', $fallback)[0]);
    }

    private static function baseQuery(array $options, array $filters): \craft\elements\db\ElementQuery
    {
        $query = Entry::find()->site($options['site'] ?? null)->status('live');
        if ($options['section']) {
            $query->section($options['section']);
        }
        foreach ($filters as $handle => $value) {
            if (!in_array($handle, $options['filters'], true) || $value === '' || $value === null) {
                continue;
            }
            $field = Craft::$app->getFields()->getFieldByHandle($handle);
            if ($field instanceof Categories || $field instanceof Tags) {
                $query->relatedTo(['targetElement' => array_map('intval', (array)$value), 'field' => $handle]);
            } elseif ($field instanceof Lightswitch) {
                $query->$handle((bool)$value);
            } elseif ($field instanceof Dropdown) {
                $query->$handle((string)$value);
            }
        }
        return $query;
    }

    public static function card(ElementInterface $element, array $options): array
    {
        $value = $element->getFieldValue($options['field']);
        $fields = [];
        foreach ($options['cardFields'] as $handle) {
            if ($element->getFieldLayout()?->getFieldByHandle($handle)) {
                $v = $element->getFieldValue($handle);
                $fields[$handle] = is_scalar($v) || $v instanceof \Stringable ? (string)$v : null;
            }
        }
        return [
            'id' => $element->id,
            'title' => (string)$element->title,
            'url' => $element->getUrl(),
            'lat' => $value instanceof LocationValue ? (float)$value->lat : null,
            'lng' => $value instanceof LocationValue ? (float)$value->lng : null,
            'address' => $value instanceof LocationValue ? $value->address : '',
            'distance' => $value instanceof LocationValue && $value->distance !== null ? round($value->distance, 2) : null,
            'directionsUrl' => $value instanceof LocationValue ? $value->directionsUrl() : '',
            'fields' => $fields,
        ];
    }

    /** Filter dropdowns for the form. */
    public static function filterOptions(array $options, array $selected): array
    {
        $out = [];
        foreach ($options['filters'] as $handle) {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);
            if (!$field) {
                continue;
            }
            $choices = [];
            if ($field instanceof Dropdown) {
                foreach ($field->options as $o) {
                    if (!isset($o['optgroup'])) {
                        $choices[] = ['value' => (string)$o['value'], 'label' => (string)$o['label']];
                    }
                }
            } elseif ($field instanceof Lightswitch) {
                $choices[] = ['value' => '1', 'label' => (string)($field->onLabel ?: Craft::t('site', 'Yes'))];
            } elseif ($field instanceof Categories || $field instanceof Tags) {
                $class = $field::elementType();
                $query = $class::find();
                $source = is_string($field->source ?? null) ? $field->source : null;
                if ($query instanceof \craft\elements\db\CategoryQuery && $source && str_starts_with($source, 'group:')) {
                    $query->groupId((new \craft\db\Query())->select('id')->from(\craft\db\Table::CATEGORYGROUPS)->where(['uid' => substr($source, 6)]));
                }
                foreach ($query->limit(200)->all() as $el) {
                    $choices[] = ['value' => (string)$el->id, 'label' => (string)$el->title];
                }
            }
            foreach ($choices as &$c) {
                $c['selected'] = in_array($c['value'], array_map('strval', (array)($selected[$handle] ?? [])), true);
            }
            unset($c);
            $out[] = ['handle' => $handle, 'label' => Craft::t('site', $field->name), 'options' => $choices];
        }
        return $out;
    }

    /** Renders the locator. With a GET search in the URL it renders the results server-side, for no-JS visitors. */
    public static function render(array $options): Markup
    {
        $plugin = Plugin::getInstance();
        if (!$plugin->isPro()) {
            $plugin->locations->warnOnce('locator');
            return new Markup('', 'UTF-8');
        }
        // Display-only text stays out of the signed search options.
        $text = array_map(fn($v) => is_string($v) && $v !== '' ? $v : null, array_intersect_key($options, array_flip(['heading', 'intro', 'cardHeading'])));
        if (isset($text['cardHeading']) && !in_array($text['cardHeading'], ['h2', 'h3', 'h4', 'p'], true)) {
            unset($text['cardHeading']);
        }
        $options = self::normalise($options);
        $settings = $plugin->getSettings();
        $request = Craft::$app->getRequest();
        $input = $request->getIsConsoleRequest() ? [] : self::input($request->getQueryParams())['input'];
        $result = ['ok' => true, 'results' => [], 'origin' => null, 'nearest' => null, 'total' => 0];
        $error = null;
        if (!empty($input['q']) || isset($input['lat'])) {
            $result = self::search($options, $input);
            if (!$result['ok']) {
                $error = $result['errors'][0] ?? Craft::t('rumbo-maps', 'We couldn’t find that place. Try a town or postcode.');
            }
        }
        $id = 'rumbo-locator-' . StringHelper::randomString(6);
        $token = self::token($options);
        $locations = $plugin->locations;
        $config = [
            'endpoint' => UrlHelper::actionUrl('rumbo-maps/locator/search'),
            'look' => $options['look'],
            'unit' => $options['unit'],
            'limit' => $options['limit'],
            'site' => $options['site'],
            'lang' => Craft::$app->language,
            'style' => $locations->styleUrl($settings->mapStyle),
            'markerColor' => $settings->markerColor,
            'provider' => $locations->providerConfig(),
            'center' => [-3.7038, 40.4168],
            'zoom' => 5,
            'results' => $result['results'] ?? [],
            'origin' => $result['origin'] ?? null,
            'consentRequired' => $settings->consentRequired,
            'strings' => self::strings(),
        ];
        Craft::$app->getView()->registerAssetBundle(web\FrontAsset::class);
        $html = Craft::$app->getView()->renderTemplate('rumbo-maps/_locator/locator', [
            'id' => $id,
            'look' => $options['look'],
            'config' => $config,
            'results' => $result['results'] ?? [],
            'origin' => $result['origin'] ?? null,
            'nearest' => $result['nearest'] ?? null,
            'total' => $result['total'] ?? 0,
            'within' => $input['radius'] ?? $options['within'],
            'unit' => $options['unit'],
            'q' => $input['q'] ?? '',
            'filters' => self::filterOptions($options, $input['filters'] ?? []),
            'token' => $token,
            'accentColor' => $settings->accentColor,
            'corners' => $settings->corners,
            'font' => $settings->font,
            'consentRequired' => $settings->consentRequired,
            'error' => $error,
        ] + $text, Craft::$app->getView()::TEMPLATE_MODE_SITE);
        return new Markup($html, 'UTF-8');
    }

    /** Front-end text, translatable through the site's own translations first. */
    public static function strings(): array
    {
        $file = dirname(__DIR__) . '/web/strings-es.json';
        $keys = is_file($file) ? array_keys(Json::decode(file_get_contents($file)) ?: []) : [];
        $out = [];
        foreach ($keys as $key) {
            $t = Craft::t('rumbo-maps', 'locator.' . $key);
            if ($t !== 'locator.' . $key) {
                $out[$key] = $t;
            }
        }
        return $out;
    }
}
