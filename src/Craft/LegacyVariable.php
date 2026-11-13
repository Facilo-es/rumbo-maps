<?php

namespace facilo\rumbo\Craft;

use facilo\rumbo\Plugin;
use Twig\Markup;

/**
 * `craft.maps` and `craft.simpleMap`: the old plugin's Twig names, answered so moved templates
 * keep working (F4). Names only; the behaviour is our own. Pro calls return an empty result in Lite.
 */
class LegacyVariable
{
    public function embed(array $options = []): Markup
    {
        return Plugin::getInstance()->locations->legacyCall('craft.maps.embed', function() use ($options) {
            $center = $options['center'] ?? null;
            $value = new LocationValue(is_array($center) ? $center + ['zoom' => $options['zoom'] ?? null] : []);
            return Plugin::getInstance()->locations->renderMap([$value], $options);
        }, new Markup('', 'UTF-8'));
    }

    public function img(array $options = []): string
    {
        return Plugin::getInstance()->locations->legacyCall('craft.maps.img', fn() => $this->staticFor($options, false), '');
    }

    public function imgSrcSet(array $options = []): string
    {
        return Plugin::getInstance()->locations->legacyCall('craft.maps.imgSrcSet', fn() => $this->staticFor($options, true), '');
    }

    private function staticFor(array $options, bool $srcset): string
    {
        $center = $options['center'] ?? null;
        $values = [new LocationValue(is_array($center) ? $center : [])];
        foreach ((array)($options['markers'] ?? []) as $m) {
            if (is_array($m) && isset($m['location'])) {
                $values[] = new LocationValue((array)$m['location']);
            }
        }
        $locations = Plugin::getInstance()->locations;
        return $srcset ? $locations->staticSrcSet($values, $options) : $locations->staticUrl($values, $options);
    }

    /** `craft.maps.getLatLngFromAddress('...')` returns `{ lat, lng }` or null. */
    public function getLatLngFromAddress(string $address, ?string $country = null): ?array
    {
        return Plugin::getInstance()->locations->legacyCall('getLatLngFromAddress', function() use ($address, $country) {
            $r = Plugin::getInstance()->geocoding->place($address, $country);
            return $r ? ['lat' => $r->lat, 'lng' => $r->lng] : null;
        }, null);
    }

    public function getUserLocation(): ?array
    {
        return Plugin::getInstance()->locations->legacyCall('getUserLocation', fn() => VisitorLocation::lookup(), null);
    }
}
