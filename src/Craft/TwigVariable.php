<?php

namespace facilo\rumbo\Craft;

use Craft;
use facilo\rumbo\Core\Geo;
use facilo\rumbo\Plugin;
use Twig\Markup;

/** `craft.rumbo`: our documented Twig API (F2, F6, F7). */
class TwigVariable
{
    /** One map for any number of locations or elements: `craft.rumbo.map(entries, { field: 'location' })`. */
    public function map(mixed $items, array $options = []): Markup
    {
        $values = [];
        $markers = [];
        foreach (is_iterable($items) ? $items : [$items] as $item) {
            if ($item instanceof LocationValue) {
                $values[] = $item;
            } elseif ($item instanceof \craft\elements\Address) {
                $values[] = CraftAddresses::toLocation($item);
            } elseif ($item instanceof \craft\base\ElementInterface) {
                $value = self::valueOf($item, $options['field'] ?? null);
                if ($value instanceof LocationValue && $value->hasPosition()) {
                    $markers[] = [
                        'lat' => (float)$value->lat,
                        'lng' => (float)$value->lng,
                        'title' => (string)$item->title,
                        'address' => $value->address,
                        'url' => $item->getUrl(),
                        'directionsUrl' => $value->directionsUrl(),
                    ];
                    $i = array_key_last($markers);
                    if (!empty($options['card']) && ($html = self::card((string)$options['card'], $item, $value)) !== null) {
                        $markers[$i]['html'] = $html;
                    }
                    if (!empty($options['icon']) && ($icon = Locations::iconUrl(self::path($item, (string)$options['icon']))) !== null) {
                        $markers[$i]['icon'] = $icon;
                    }
                }
            }
        }
        unset($options['card'], $options['icon']);
        return Plugin::getInstance()->locations->renderMap($values, $options, $markers);
    }

    /**
     * An info card from the developer's own partial (F7), e.g. `card: '_partials/card'`, rendered with
     * `entry` and `location`. A missing or broken partial falls back to the title and address, with a log line.
     */
    private static function card(string $template, \craft\base\ElementInterface $entry, LocationValue $location): ?string
    {
        $view = Craft::$app->getView();
        try {
            $html = trim($view->renderTemplate($template, ['entry' => $entry, 'location' => $location], \craft\web\View::TEMPLATE_MODE_SITE));
            return $html === '' ? null : $html;
        } catch (\Throwable $e) {
            Craft::warning('Rumbo Maps card partial "' . $template . '" failed: ' . $e->getMessage(), 'rumbo-maps');
            return null;
        }
    }

    /**
     * Follows a dotted path from an entry to its marker icon (F7), e.g. `icon: 'category.icon'` reads the
     * entry's category field, takes the first category, then its `icon` asset field.
     */
    public static function path(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $part) {
            if ($value instanceof \craft\elements\db\ElementQueryInterface) {
                $value = $value->one();
            }
            if ($value === null || $part === '') {
                return null;
            }
            try {
                $value = is_array($value) ? ($value[$part] ?? null) : ($value->$part ?? null);
            } catch (\Throwable) {
                return null;
            }
        }
        return $value;
    }

    /** The store locator: `craft.rumbo.locator({ section: 'stores' })`. Pro. */
    public function locator(array $options = []): Markup
    {
        return Locator::render($options);
    }

    /**
     * A static map image URL (F9, Pro). One location, or several as markers:
     * `rumbo.img(entries, { width: 600, height: 300, field: 'location' })`.
     */
    public function img(mixed $items, array $options = []): string
    {
        $values = self::values($items, $options['field'] ?? null);
        unset($options['field']);
        return Plugin::getInstance()->locations->legacyCall('img', fn() => Plugin::getInstance()->locations->staticUrl($values, $options), '');
    }

    /** The same as img(), as "url 1x, url 2x" for srcset. */
    public function imgSrcSet(mixed $items, array $options = []): string
    {
        $values = self::values($items, $options['field'] ?? null);
        unset($options['field']);
        return Plugin::getInstance()->locations->legacyCall('imgSrcSet', fn() => Plugin::getInstance()->locations->staticSrcSet($values, $options), '');
    }

    /**
     * Craft's own addresses that have a position (F14, Pro), ready for `rumbo.map()`, nearest first with `near`:
     * `rumbo.addresses({ near: 'Valencia', within: 50 })`.
     *
     * @return LocationValue[]
     */
    public function addresses(array $options = []): array
    {
        return Plugin::getInstance()->locations->legacyCall('addresses', fn() => CraftAddresses::find($options), []);
    }

    /** @return LocationValue[] with a position, from a value, an element, an address or a list of them. */
    public static function values(mixed $items, ?string $field = null): array
    {
        $out = [];
        foreach ($items instanceof LocationValue || $items instanceof \craft\base\ElementInterface || !is_iterable($items) ? [$items] : $items as $item) {
            $value = match (true) {
                $item instanceof LocationValue => $item,
                $item instanceof \craft\elements\Address => CraftAddresses::toLocation($item),
                $item instanceof \craft\base\ElementInterface => self::valueOf($item, $field),
                default => null,
            };
            if ($value instanceof LocationValue && $value->hasPosition()) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /** The element's location: the named field, else its first Rumbo location field. */
    public static function valueOf(\craft\base\ElementInterface $element, ?string $field = null): ?LocationValue
    {
        if ($field !== null) {
            $value = $element->getFieldValue($field);
            return $value instanceof LocationValue ? $value : null;
        }
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $candidate) {
            if ($candidate instanceof fields\Location) {
                $value = $element->getFieldValue($candidate->handle);
                if ($value instanceof LocationValue) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** A place typed by a developer, looked up and cached: `craft.rumbo.lookup('Valencia')`. */
    public function lookup(string $text, ?string $country = null): ?array
    {
        return Plugin::getInstance()->geocoding->place($text, $country)?->toArray();
    }

    /** The visitor's rough position from the GeoLite2 file set in the settings, or null. Pro. */
    public function visitorLocation(): ?array
    {
        return Plugin::getInstance()->locations->legacyCall('visitorLocation', fn() => VisitorLocation::lookup(), null);
    }

    public function distance(LocationValue $a, LocationValue $b, string $unit = 'km'): ?float
    {
        if (!$a->hasPosition() || !$b->hasPosition()) {
            return null;
        }
        $km = Geo::distanceKm((float)$a->lat, (float)$a->lng, (float)$b->lat, (float)$b->lng);
        return $unit === 'mi' ? Geo::kmToMiles($km) : $km;
    }

    /** Why the last location query matched nothing, e.g. "not found", or null when it found its place. */
    public function queryError(): ?string
    {
        return Plugin::getInstance()->proximity->lastError;
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }

    public function t(string $message): string
    {
        return Craft::t('rumbo-maps', $message);
    }
}
