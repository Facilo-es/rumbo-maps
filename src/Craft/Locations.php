<?php

namespace facilo\rumbo\Craft;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use facilo\rumbo\Core\Providers\GeoResult;
use facilo\rumbo\Core\StaticMaps;
use facilo\rumbo\Craft\fields\Location;
use facilo\rumbo\Craft\jobs\FillMissing;
use facilo\rumbo\Craft\jobs\GoogleRefresh;
use facilo\rumbo\Craft\web\FrontAsset;
use facilo\rumbo\migrations\Install;
use facilo\rumbo\Plugin;
use Twig\Markup;

/** Keeps {{%rumbo_locations}} in step with field values, and renders maps and static images. */
class Locations extends Component
{
    public const STYLE_URLS = [
        'liberty' => 'https://tiles.openfreemap.org/styles/liberty',
        'bright' => 'https://tiles.openfreemap.org/styles/bright',
        'positron' => 'https://tiles.openfreemap.org/styles/positron',
        'dark' => 'https://tiles.openfreemap.org/styles/dark',
    ];

    public const NOTICES_KEY = 'rumbo-maps:cp-notices';
    public const NOTICE_DAYS = 7;

    private array $warned = [];

    public function saveFor(ElementInterface $element, Location $field, mixed $value): void
    {
        $where = ['elementId' => $element->id, 'siteId' => $element->siteId, 'fieldId' => $field->id];
        if (!$value instanceof LocationValue || $value->isEmpty()) {
            Db::delete(Install::LOCATIONS, $where);
            return;
        }
        $row = $this->row($value);
        if (!$value->hasPosition() && !in_array($value->source, ['cleared', 'notfound'], true)) {
            // An address with no position (Feed Me, CSV, an API): saved now, looked up by the queue (F13).
            $row['source'] = 'pending';
            $row['geocodedAt'] = null;
        }
        Db::upsert(Install::LOCATIONS, $where + $row);
        if ($row['source'] === 'pending') {
            $this->queueFill();
        }
    }

    /** Starts the lookup job for pending addresses, once, however many entries an import saves. Pro only. */
    public function queueFill(): void
    {
        $cache = Craft::$app->getCache();
        if (!Plugin::getInstance()->isPro() || $cache->get(FillMissing::QUEUED)) {
            return;
        }
        $cache->set(FillMissing::QUEUED, true, 3600);
        Queue::push(new FillMissing(), null, 5);
    }

    /**
     * "Fill missing coordinates": every value with an address and no position goes back in line,
     * except ones Google's 30-day rule cleared. Returns how many are waiting.
     */
    public function markMissing(): int
    {
        Db::update(Install::LOCATIONS, ['source' => 'pending', 'geocodedAt' => null], [
            'and', ['lat' => null], ['not', ['address' => null]], ['not', ['address' => '']],
            ['or', ['source' => null], ['not in', 'source', ['cleared', 'pending']]],
        ], [], false);
        Craft::$app->getCache()->delete(FillMissing::QUEUED);
        $this->queueFill();
        return (int)(new Query())->from(Install::LOCATIONS)->where(['source' => 'pending'])->count();
    }

    /**
     * Writes a looked-up position (or its absence) into the element's own field value, so the
     * content and the locations table agree. Revisions and missing elements only get the table.
     */
    public function applyLookup(array $row, ?GeoResult $result, string $failSource): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $elements = Craft::$app->getElements();
        $element = $elements->getElementById((int)$row['elementId'], null, (int)$row['siteId']);
        $field = Craft::$app->getFields()->getFieldById((int)$row['fieldId']);
        if ($element && $field && !$element->getIsRevision()) {
            $value = $element->getFieldValue($field->handle);
            if ($value instanceof LocationValue) {
                $value->lat = $result ? LocationValue::decimal($result->lat) : null;
                $value->lng = $result ? LocationValue::decimal($result->lng) : null;
                $value->source = $result->source ?? $failSource;
                $value->geocodedAt = $result ? $now : null;
                if ($result && !array_filter(array_diff_key($value->parts, ['countryCode' => 1, 'address' => 1]))) {
                    $value = new LocationValue(['parts' => $result->parts->toArray()] + $value->toArray());
                }
                $element->setFieldValue($field->handle, $value);
                $element->resaving = true;
                if ($elements->saveElement($element, false, false, false)) {
                    return;
                }
            }
        }
        Db::update(Install::LOCATIONS, [
            'lat' => $result?->lat,
            'lng' => $result?->lng,
            'source' => substr($result->source ?? $failSource, 0, 16),
            'geocodedAt' => $result ? $now : null,
        ], ['id' => $row['id']]);
    }

    /** Columns for one value, besides the key. */
    public function row(LocationValue $value): array
    {
        $parts = $value->toArray()['parts'];
        return [
            'lat' => $value->lat,
            'lng' => $value->lng,
            'zoom' => $value->zoom,
            'address' => mb_substr($value->address, 0, 255),
            'parts' => $parts,
            'countryCode' => $value->countryCode ? strtoupper(substr($value->countryCode, 0, 2)) : null,
            'serviceRadius' => $value->serviceRadius,
            'source' => substr($value->source ?: 'manual', 0, 16),
            'geocodedAt' => $value->geocodedAt ? Db::prepareDateForDb($value->geocodedAt) : null,
        ];
    }

    public function styleUrl(string $style): string
    {
        return self::STYLE_URLS[$style] ?? self::STYLE_URLS['positron'];
    }

    public const PROVIDER_LABELS = ['openfreemap' => 'OpenFreeMap', 'google' => 'Google Maps', 'mapbox' => 'Mapbox', 'maptiler' => 'MapTiler', 'here' => 'HERE', 'apple' => 'Apple Maps'];

    /**
     * What front-end maps need to draw: the provider, its browser key only (never a server key or secret),
     * Google's map ID, or Apple's token URL. Lite, or a keyed provider without its key, uses OpenFreeMap.
     * @return array{name:string, key?:string, mapId?:string, tokenUrl?:string}
     */
    public function providerConfig(?string $name = null): array
    {
        $name ??= Plugin::getInstance()->getSettings()->frontEndProvider();
        if ($name === 'openfreemap' || !Plugin::getInstance()->isPro()) {
            return ['name' => 'openfreemap'];
        }
        return $this->providerConfigFor($name) ?? ['name' => 'openfreemap'];
    }

    /** The browser config for one provider, or null when its browser key (or Apple's credentials) is missing. */
    public function providerConfigFor(string $name): ?array
    {
        $settings = Plugin::getInstance()->getSettings();
        if ($name === 'openfreemap' || $name === 'nominatim') {
            return ['name' => 'openfreemap'];
        }
        if ($name === 'apple') {
            return AppleToken::configured() ? ['name' => 'apple', 'tokenUrl' => UrlHelper::actionUrl('rumbo-maps/keys/mapkit-token')] : null;
        }
        $key = $settings->key($name, 'browser');
        if ($key === '') {
            return null;
        }
        $out = ['name' => $name, 'key' => $key];
        if ($name === 'google') {
            $out['mapId'] = $settings->key('google', 'mapId');
        }
        return $out;
    }

    /**
     * A marker image for the front end: an Asset, an image URL, or inline SVG markup (sent as a data: URI,
     * so it never runs as HTML). Anything else gives null and the default pin.
     */
    public static function iconUrl(mixed $icon): ?string
    {
        if ($icon instanceof \craft\elements\db\AssetQuery) {
            $icon = $icon->one();
        }
        if ($icon instanceof \craft\elements\Asset) {
            return $icon->getUrl() ?: null;
        }
        if (!is_string($icon) || ($icon = trim($icon)) === '') {
            return null;
        }
        if (str_starts_with($icon, '<svg') || str_starts_with($icon, '<?xml')) {
            return 'data:image/svg+xml;base64,' . base64_encode($icon);
        }
        return preg_match('~^(https?://|/)~i', $icon) ? $icon : null;
    }

    /**
     * Renders one map for one or more locations (F2, F7). Several maps on one page are independent.
     *
     * @param LocationValue[] $values
     */
    public function renderMap(array $values, array $options = [], array $markers = []): Markup
    {
        $settings = Plugin::getInstance()->getSettings();
        foreach ($values as $value) {
            if ($value instanceof LocationValue && $value->hasPosition()) {
                $markers[] = [
                    'lat' => (float)$value->lat,
                    'lng' => (float)$value->lng,
                    'title' => (string)($options['markerTitle'] ?? $value->address),
                    'address' => $value->address,
                    'directionsUrl' => $value->directionsUrl(),
                ];
            }
        }
        $first = $values[0] ?? null;
        $style = $options['style'] ?? $settings->mapStyle;
        $config = [
            'style' => isset(self::STYLE_URLS[$style]) || !str_starts_with((string)$style, 'https://') ? $this->styleUrl((string)$style) : $style,
            'center' => $markers ? [$markers[0]['lng'], $markers[0]['lat']] : [-3.7038, 40.4168],
            'zoom' => (int)($options['zoom'] ?? ($first?->zoom ?: 15)),
            'markers' => $markers,
            'cluster' => (bool)($options['cluster'] ?? count($markers) > 50),
            'fitBounds' => (bool)($options['fitBounds'] ?? count($markers) > 1),
            'scrollZoom' => (bool)($options['scrollZoom'] ?? false),
            'markerColor' => $options['markerColor'] ?? $settings->markerColor,
            'markerIcon' => self::iconUrl($options['markerIcon'] ?? null),
            'numbered' => (bool)($options['numbered'] ?? false),
            'provider' => $this->providerConfig(),
        ];
        Craft::$app->getView()->registerAssetBundle(FrontAsset::class);
        $html = Craft::$app->getView()->renderTemplate('rumbo-maps/_map/map', [
            'id' => $options['id'] ?? 'rumbo-map-' . StringHelper::randomString(6),
            'class' => $options['class'] ?? '',
            'config' => $config,
            'height' => $options['height'] ?? 400,
            'consentRequired' => Plugin::getInstance()->isPro() && $settings->consentRequired && ($options['consent'] ?? true),
            // Only a fallback image: never warn or show a notice for it.
            'staticUrl' => Plugin::getInstance()->isPro() ? $this->staticUrl($values, ['width' => 800, 'height' => 400, 'quiet' => true]) : '',
            'provider' => self::PROVIDER_LABELS[$config['provider']['name']] ?? 'OpenFreeMap',
            'fallbackAddress' => $first?->address ?? '',
            'osmUrl' => $first?->hasPosition() ? sprintf('https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=%d/%s/%s', $first->lat, $first->lng, $config['zoom'], $first->lat, $first->lng) : '',
        ], Craft::$app->getView()::TEMPLATE_MODE_CP);
        return new Markup($html, 'UTF-8');
    }

    /**
     * @param LocationValue[] $values Each one becomes a marker.
     * @param array $options width, height, zoom, style, scale, color; quiet skips the notice.
     */
    public function staticUrl(array $values, array $options = []): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $quiet = (bool)($options['quiet'] ?? false);
        unset($options['quiet']);
        if (!Plugin::getInstance()->isPro()) {
            $this->warnOnce('img');
            return '';
        }
        $markers = [];
        foreach ($values as $value) {
            if ($value instanceof LocationValue && $value->hasPosition()) {
                $markers[] = ['lat' => (float)$value->lat, 'lng' => (float)$value->lng];
            }
        }
        if (!$markers) {
            return '';
        }
        $keys = [];
        foreach (Settings::PROVIDERS as $provider) {
            $key = $settings->key($provider, 'server') ?: $settings->key($provider, 'browser');
            // StaticMaps names each provider's key as the provider does: Google "key", Mapbox "token", HERE "apiKey".
            $keys[$provider] = ['key' => $key, 'token' => $key, 'apiKey' => $key, 'secret' => $settings->key($provider, 'secret')];
        }
        $provider = $settings->frontEndProvider();
        $url = StaticMaps::url($provider, $markers, $options, $keys[$provider] ?? []);
        if ($url === '' && !$quiet) {
            $this->notice('static', 'A template asks for a static map image, but static images need Google, Mapbox, MapTiler or HERE with a key, and this site uses {provider}. The image address is empty until you choose one of them in Rumbo Maps settings.', ['provider' => $provider === 'openfreemap' ? 'OpenFreeMap' : ucfirst($provider)]);
        }
        return $url;
    }

    /**
     * Remembers a problem to show in the control panel for the next {@see NOTICE_DAYS} days.
     * The message is stored in English and translated when shown.
     */
    public function notice(string $key, string $message, array $params = []): void
    {
        $cache = Craft::$app->getCache();
        $notices = $cache->get(self::NOTICES_KEY) ?: [];
        if (isset($notices[$key]) && $notices[$key]['at'] > time() - 3600 && $notices[$key]['params'] === $params) {
            return;
        }
        $notices[$key] = ['message' => $message, 'params' => $params, 'at' => time()];
        $cache->set(self::NOTICES_KEY, $notices, self::NOTICE_DAYS * 86400);
    }

    /** @return string[] Translated notices still current. */
    public function notices(): array
    {
        $out = [];
        foreach (Craft::$app->getCache()->get(self::NOTICES_KEY) ?: [] as $notice) {
            if (($notice['at'] ?? 0) > time() - self::NOTICE_DAYS * 86400) {
                $out[] = Craft::t('rumbo-maps', $notice['message'], $notice['params'] ?? []);
            }
        }
        return $out;
    }

    public function clearNotices(): void
    {
        Craft::$app->getCache()->delete(self::NOTICES_KEY);
    }

    /** @param LocationValue[] $values */
    public function staticSrcSet(array $values, array $options = []): string
    {
        $one = $this->staticUrl($values, ['scale' => 1] + $options);
        if ($one === '') {
            return '';
        }
        return $one . ' 1x, ' . $this->staticUrl($values, ['scale' => 2] + $options) . ' 2x';
    }

    /**
     * Runs a Pro-only call from the old plugin's API. In Lite it returns $fallback and writes
     * one warning a day to the log, so a page never breaks (F4).
     */
    public function legacyCall(string $name, callable $pro, mixed $fallback): mixed
    {
        if (Plugin::getInstance()->isPro()) {
            return $pro();
        }
        $this->warnOnce($name);
        return $fallback;
    }

    public function warnOnce(string $name): void
    {
        if (isset($this->warned[$name])) {
            return;
        }
        $this->warned[$name] = true;
        // The CP notice has its own hourly throttle, so it is set even when today's log line is already written.
        $this->notice('pro:' . $name, 'A template uses “{name}”, which needs Rumbo Maps Pro. The page still works, but this part comes back empty.', ['name' => $name]);
        $key = 'rumbo-maps:lite-warning:' . $name . ':' . date('Y-m-d');
        if (Craft::$app->getCache()->get($key)) {
            return;
        }
        Craft::$app->getCache()->set($key, true, 86400);
        Craft::warning("A template uses “{$name}”, which needs Rumbo Maps Pro. It returned an empty result.", 'rumbo-maps');
    }

    /** Daily: positions from Google's Geocoding API older than 30 days are looked up again or cleared (F13). */
    public function scheduleGoogleRefresh(): void
    {
        $due = (new Query())->from(Install::LOCATIONS)
            ->where(['source' => 'google'])
            ->andWhere(['<', 'geocodedAt', Db::prepareDateForDb(new \DateTime('-30 days'))])
            ->exists();
        if ($due) {
            Queue::push(new GoogleRefresh());
        }
    }
}
