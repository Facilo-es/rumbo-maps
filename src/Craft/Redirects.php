<?php

namespace facilo\rumbo\Craft;

use Craft;
use facilo\rumbo\Core\Geo;
use facilo\rumbo\Plugin;

/**
 * Redirect rules (F11, Pro): visitors from a country, or within a distance of a point, go to a
 * site or a URL. Checked once a session, on front-end page views only, never for bots.
 */
class Redirects
{
    public const SESSION_KEY = 'rumbo-maps:redirect-checked';
    public const BOTS = '/bot|crawl|spider|slurp|archiver|facebookexternalhit|embedly|preview|lighthouse|headless|pingdom|uptime|monitor|curl|wget|python-requests|httpclient|go-http/i';

    /** Tests set this to stand in for the GeoLite2 file: fn(): ?array. */
    public static $lookup = null;

    /** Called on each site request. Sends the redirect when a rule matches. */
    public static function handle(): void
    {
        $request = Craft::$app->getRequest();
        if (!$request->getIsSiteRequest() || $request->getIsActionRequest() || $request->getIsPreview() || !$request->getIsGet() || $request->getIsAjax()) {
            return;
        }
        $plugin = Plugin::getInstance();
        if (!$plugin->isPro() || !self::rules($plugin->getSettings()->redirects)) {
            return;
        }
        $session = Craft::$app->getSession();
        $url = self::decide(
            $plugin->getSettings()->redirects,
            (string)$request->getUserAgent(),
            (bool)$session->get(self::SESSION_KEY),
            $request->getAbsoluteUrl(),
            fn() => self::$lookup ? (self::$lookup)() : VisitorLocation::lookup(),
        );
        if (!self::isBot((string)$request->getUserAgent())) {
            $session->set(self::SESSION_KEY, true);
        }
        if ($url !== null) {
            Craft::info("Visitor redirected to $url by a location rule.", 'rumbo-maps');
            Craft::$app->getResponse()->redirect($url, 302)->send();
            Craft::$app->end();
        }
    }

    public static function isBot(string $userAgent): bool
    {
        return $userAgent === '' || preg_match(self::BOTS, $userAgent) === 1;
    }

    /** Clean, complete rules from the settings table. */
    public static function rules(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || trim((string)($row['target'] ?? '')) === '') {
                continue;
            }
            $type = ($row['type'] ?? 'country') === 'distance' ? 'distance' : 'country';
            if ($type === 'country' && !preg_match('/^[A-Za-z]{2}$/', trim((string)($row['country'] ?? '')))) {
                continue;
            }
            if ($type === 'distance' && (Geo::parseLatLng(['lat' => $row['lat'] ?? null, 'lng' => $row['lng'] ?? null]) === null || !is_numeric($row['within'] ?? null))) {
                continue;
            }
            $out[] = ['type' => $type, 'country' => strtoupper(trim((string)($row['country'] ?? ''))), 'lat' => (float)($row['lat'] ?? 0), 'lng' => (float)($row['lng'] ?? 0), 'within' => (float)($row['within'] ?? 0), 'target' => trim((string)$row['target'])];
        }
        return $out;
    }

    /**
     * The URL to send this visitor to, or null. Pure apart from $lookup, for tests.
     *
     * @param callable $lookup fn(): ?array{lat:float,lng:float,countryCode:?string}
     */
    public static function decide(mixed $rows, string $userAgent, bool $alreadyChecked, string $currentUrl, callable $lookup): ?string
    {
        $rules = self::rules($rows);
        if (!$rules || $alreadyChecked || self::isBot($userAgent)) {
            return null;
        }
        $visitor = $lookup();
        if (!is_array($visitor)) {
            return null;
        }
        foreach ($rules as $rule) {
            $match = $rule['type'] === 'country'
                ? strtoupper((string)($visitor['countryCode'] ?? '')) === $rule['country']
                : isset($visitor['lat'], $visitor['lng']) && Geo::distanceKm((float)$visitor['lat'], (float)$visitor['lng'], $rule['lat'], $rule['lng']) <= $rule['within'];
            if (!$match) {
                continue;
            }
            $url = self::targetUrl($rule['target']);
            if ($url === null || rtrim($url, '/') === rtrim(strtok($currentUrl, '?'), '/') || str_starts_with($currentUrl, rtrim($url, '/') . '/')) {
                return null;
            }
            return $url;
        }
        return null;
    }

    /** A site handle becomes that site's home page; anything else must be an http(s) URL or a path. */
    public static function targetUrl(string $target): ?string
    {
        $site = Craft::$app->getSites()->getSiteByHandle($target);
        if ($site !== null) {
            if ($site->id === Craft::$app->getSites()->getCurrentSite()->id) {
                return null;
            }
            return $site->getBaseUrl() ?: null;
        }
        if (preg_match('#^https?://#i', $target) || str_starts_with($target, '/')) {
            return $target;
        }
        return null;
    }
}
