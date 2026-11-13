<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

/**
 * Static map image URLs for the providers that offer them. OpenFreeMap has no static
 * images, so it (and any unknown provider) gives '' and the caller shows the live map.
 *
 * Markers: list of ['lat' => float, 'lng' => float, 'label' => ?string, 'color' => ?'#rrggbb'].
 * Options: width (600), height (300), zoom (15 for one marker, fitted for several), scale (1|2),
 * color ('#e4572e'), style (Mapbox "user/style"), mapId (MapTiler).
 * Keys: google ['key', 'secret'?], mapbox ['token'], maptiler ['key'], here ['apiKey'].
 */
final class StaticMaps
{
    public const PROVIDERS = ['google', 'mapbox', 'maptiler', 'here'];
    public const DEFAULT_COLOR = '#e4572e';
    private const MAX_SIZE = ['google' => 640, 'mapbox' => 1280, 'maptiler' => 2048, 'here' => 2048];

    /** Static image URL, or '' when the provider has none or a needed key is missing. */
    public static function url(string $provider, array $markers, array $opts, array $keys): string
    {
        $provider = strtolower($provider);
        $markers = self::markers($markers, (string) ($opts['color'] ?? self::DEFAULT_COLOR));
        if ($markers === [] || !isset(self::MAX_SIZE[$provider])) {
            return '';
        }
        $max = self::MAX_SIZE[$provider];
        $w = max(1, min($max, (int) ($opts['width'] ?? 600)));
        $h = max(1, min($max, (int) ($opts['height'] ?? 300)));
        $scale = (int) ($opts['scale'] ?? 1) === 2 ? 2 : 1;
        $zoom = isset($opts['zoom']) ? max(0, min(22, (int) $opts['zoom'])) : (count($markers) === 1 ? 15 : null);

        return match ($provider) {
            'google' => self::google($markers, $w, $h, $scale, $zoom, $keys),
            'mapbox' => self::mapbox($markers, $w, $h, $scale, $zoom, $opts, $keys),
            'maptiler' => self::maptiler($markers, $w, $h, $scale, $zoom, $opts, $keys),
            'here' => self::here($markers, $w, $h, $scale, $zoom, $keys),
        };
    }

    /** "url 1x, url 2x" for an img srcset, or '' when there is no static image. */
    public static function srcSet(string $provider, array $markers, array $opts, array $keys): string
    {
        $one = self::url($provider, $markers, ['scale' => 1] + $opts, $keys);
        if ($one === '') {
            return '';
        }
        $opts['scale'] = 2;

        return $one . ' 1x, ' . self::url($provider, $markers, $opts, $keys) . ' 2x';
    }

    /** Signs a Google Maps URL with a URL-signing secret (HMAC-SHA1 over path and query). */
    public static function signUrl(string $url, string $secret): string
    {
        $parts = parse_url($url);
        $toSign = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $key = base64_decode(strtr($secret, '-_', '+/'), true);
        if ($key === false || $key === '') {
            throw new \InvalidArgumentException('The Google URL-signing secret is not valid base64');
        }
        $signature = strtr(base64_encode(hash_hmac('sha1', $toSign, $key, true)), '+/', '-_');

        return $url . (str_contains($url, '?') ? '&' : '?') . 'signature=' . $signature;
    }

    private static function google(array $markers, int $w, int $h, int $scale, ?int $zoom, array $keys): string
    {
        $key = (string) ($keys['key'] ?? '');
        if ($key === '') {
            return '';
        }
        $q = ['size=' . $w . 'x' . $h];
        if ($scale === 2) {
            $q[] = 'scale=2';
        }
        if ($zoom !== null) {
            $q[] = 'zoom=' . $zoom;
            $q[] = 'center=' . self::latLng($markers[0]);
        }
        foreach ($markers as $m) {
            $style = ['color:0x' . strtoupper(substr($m['color'], 1))];
            if ($m['label'] !== null && preg_match('/^[A-Z0-9]$/', strtoupper($m['label']))) {
                $style[] = 'label:' . strtoupper($m['label']);
            }
            $q[] = 'markers=' . rawurlencode(implode('|', $style) . '|' . self::latLng($m));
        }
        $q[] = 'key=' . rawurlencode($key);
        $url = 'https://maps.googleapis.com/maps/api/staticmap?' . implode('&', $q);
        $secret = (string) ($keys['secret'] ?? '');

        return $secret !== '' ? self::signUrl($url, $secret) : $url;
    }

    private static function mapbox(array $markers, int $w, int $h, int $scale, ?int $zoom, array $opts, array $keys): string
    {
        $token = (string) ($keys['token'] ?? '');
        if ($token === '') {
            return '';
        }
        $style = (string) ($opts['style'] ?? 'mapbox/streets-v12');
        if (!preg_match('#^[\w.-]+/[\w.-]+$#', $style)) {
            $style = 'mapbox/streets-v12';
        }
        $pins = [];
        foreach ($markers as $m) {
            $label = $m['label'] !== null && preg_match('/^([a-z0-9]|[0-9]{1,2})$/', strtolower($m['label'])) ? '-' . strtolower($m['label']) : '';
            $pins[] = 'pin-s' . $label . '+' . strtolower(substr($m['color'], 1)) . '(' . self::lngLat($m) . ')';
        }
        $position = $zoom !== null ? self::lngLat($markers[0]) . ',' . $zoom : 'auto';
        $query = ['access_token' => $token];
        if ($zoom === null) {
            $query = ['padding' => 40] + $query;
        }

        return sprintf(
            'https://api.mapbox.com/styles/v1/%s/static/%s/%s/%dx%d%s?%s',
            $style,
            implode(',', $pins),
            $position,
            $w,
            $h,
            $scale === 2 ? '@2x' : '',
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        );
    }

    private static function maptiler(array $markers, int $w, int $h, int $scale, ?int $zoom, array $opts, array $keys): string
    {
        $key = (string) ($keys['key'] ?? '');
        if ($key === '') {
            return '';
        }
        $mapId = (string) ($opts['mapId'] ?? 'streets-v2');
        if (!preg_match('/^[\w.-]+$/', $mapId)) {
            $mapId = 'streets-v2';
        }
        $position = $zoom !== null ? self::lngLat($markers[0]) . ',' . $zoom : 'auto';
        $pins = array_map(static fn($m) => self::lngLat($m) . ',' . $m['color'], $markers);
        $query = ['markers' => implode('|', $pins)];
        if ($zoom === null) {
            $query['padding'] = 40;
        }
        $query['key'] = $key;

        return sprintf(
            'https://api.maptiler.com/maps/%s/static/%s/%dx%d%s.png?%s',
            $mapId,
            $position,
            $w,
            $h,
            $scale === 2 ? '@2x' : '',
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        );
    }

    private static function here(array $markers, int $w, int $h, int $scale, ?int $zoom, array $keys): string
    {
        $key = (string) ($keys['apiKey'] ?? '');
        if ($key === '') {
            return '';
        }
        $position = $zoom !== null ? 'center:' . self::latLng($markers[0]) . ';zoom=' . $zoom : 'overlay:padding=32';
        $points = [];
        foreach ($markers as $m) {
            $points[] = self::latLng($m) . ($m['label'] !== null ? ';label=' . str_replace([';', '|'], '', $m['label']) : '');
        }

        return sprintf(
            'https://image.maps.hereapi.com/mia/v3/base/mc/%s/%dx%d/png?%s',
            $position,
            $w * $scale,
            $h * $scale,
            http_build_query(['apiKey' => $key, 'overlay' => 'point:' . implode('|', $points)], '', '&', PHP_QUERY_RFC3986),
        );
    }

    /** @return list<array{lat:float,lng:float,label:?string,color:string}> */
    private static function markers(array $markers, string $defaultColor): array
    {
        $default = preg_match('/^#[0-9a-fA-F]{6}$/', $defaultColor) ? $defaultColor : self::DEFAULT_COLOR;
        $out = [];
        foreach ($markers as $m) {
            $point = is_array($m) ? Geo::parseLatLng($m) : null;
            if ($point === null) {
                continue;
            }
            $color = is_string($m['color'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $m['color']) ? $m['color'] : $default;
            $label = isset($m['label']) && is_scalar($m['label']) && trim((string) $m['label']) !== '' ? trim((string) $m['label']) : null;
            $out[] = ['lat' => $point['lat'], 'lng' => $point['lng'], 'label' => $label, 'color' => $color];
        }

        return $out;
    }

    private static function latLng(array $m): string
    {
        return self::num($m['lat']) . ',' . self::num($m['lng']);
    }

    private static function lngLat(array $m): string
    {
        return self::num($m['lng']) . ',' . self::num($m['lat']);
    }

    private static function num(float $value): string
    {
        $s = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }
}
