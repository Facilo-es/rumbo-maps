<?php

namespace facilo\rumbo\Craft;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use facilo\rumbo\Core\Ports\HttpClient;
use facilo\rumbo\Core\Ports\HttpResponse;
use facilo\rumbo\Core\Providers\Geocoder;
use facilo\rumbo\Core\Providers\GeoOptions;
use facilo\rumbo\Core\Providers\GeoResult;
use facilo\rumbo\Core\Providers\Nominatim;
use facilo\rumbo\Core\Providers\Photon;
use facilo\rumbo\Core\Providers\ProviderError;
use facilo\rumbo\migrations\Install;
use facilo\rumbo\Plugin;

/**
 * Address lookups with a 30-day cache in {{%rumbo_geocache}} (F8). Type-ahead in the
 * control panel uses Photon, because Nominatim's policy forbids type-ahead.
 */
class Geocoding extends Component implements HttpClient
{
    private const CACHE_DAYS = 30;

    public ?string $lastError = null;

    /** The best match for a place typed by a visitor or a developer, cached. */
    public function place(string $text, ?string $country = null): ?GeoResult
    {
        $results = $this->lookup($text, $country ? [strtolower($country)] : [], 1);
        return $results[0] ?? null;
    }

    /** @return GeoResult[] */
    public function lookup(string $text, array $countries = [], int $limit = 5, ?array $bias = null, bool $typeAhead = false): array
    {
        $this->lastError = null;
        $geocoder = $typeAhead && $this->settings()->geocoder === 'nominatim' ? $this->photon() : $this->geocoder();
        $options = new GeoOptions($countries, Craft::$app->language, $bias, $limit);
        $hash = hash('sha256', Json::encode([$geocoder->name(), 'f', mb_strtolower(trim($text)), $countries, $bias, $limit, Craft::$app->language]));
        $cached = $this->cacheGet($hash);
        if ($cached !== null) {
            return array_map(fn(array $r) => GeoResult::fromArray($r), $cached);
        }
        try {
            $results = $geocoder->lookup($text, $options);
        } catch (ProviderError $e) {
            $this->lastError = $e->getMessage();
            Craft::warning($e->getMessage(), 'rumbo-maps');
            return [];
        }
        $this->cacheSet($hash, $geocoder->name(), array_map(fn(GeoResult $r) => $r->toArray(), $results));
        return $results;
    }

    public function reverse(float $lat, float $lng): ?GeoResult
    {
        $this->lastError = null;
        $geocoder = $this->settings()->geocoder === 'nominatim' ? $this->photon() : $this->geocoder();
        $hash = hash('sha256', Json::encode([$geocoder->name(), 'r', round($lat, 6), round($lng, 6), Craft::$app->language]));
        $cached = $this->cacheGet($hash);
        if ($cached !== null) {
            return $cached ? GeoResult::fromArray($cached[0]) : null;
        }
        try {
            $result = $geocoder->reverse($lat, $lng, new GeoOptions([], Craft::$app->language));
        } catch (ProviderError $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
        $this->cacheSet($hash, $geocoder->name(), $result ? [$result->toArray()] : []);
        return $result;
    }

    /** The configured geocoder. Keyed providers fall back to Nominatim in Lite. */
    public function geocoder(?string $name = null): Geocoder
    {
        $settings = $this->settings();
        $name ??= $settings->geocoder;
        if ($name === 'nominatim' || !Plugin::getInstance()->isPro()) {
            return new Nominatim($this, Plugin::getInstance()->getVersion(), $this->siteHost(), (string)\craft\helpers\App::parseEnv($settings->nominatimUrl));
        }
        if ($name === 'apple') {
            // Apple's server API takes an access token exchanged from a JWT signed with the customer's key.
            try {
                $token = AppleToken::configured() ? AppleToken::serverAccess($this) : '';
            } catch (ProviderError $e) {
                // Report the token problem on the lookup itself, where callers already catch it.
                return new class($e) implements Geocoder {
                    public function __construct(private ProviderError $e)
                    {
                    }
                    public function lookup(string $query, GeoOptions $o): array
                    {
                        throw $this->e;
                    }
                    public function reverse(float $lat, float $lng, GeoOptions $o): ?GeoResult
                    {
                        throw $this->e;
                    }
                    public function name(): string
                    {
                        return 'apple';
                    }
                };
            }
            return new \facilo\rumbo\Core\Providers\Apple($this, $token);
        }
        $class = 'facilo\\rumbo\\Core\\Providers\\' . ['mapbox' => 'Mapbox', 'maptiler' => 'MapTiler', 'here' => 'Here', 'google' => 'Google', 'apple' => 'Apple'][$name];
        return new $class($this, $settings->key($name, 'server') ?: $settings->key($name, 'browser'));
    }

    /**
     * The name bulk lookups are counted under: "nominatim" only for the public OpenStreetMap server,
     * which allows 2,000 a day. A self-hosted Nominatim and keyed providers have no daily cap here.
     */
    public function bulkProvider(): string
    {
        $geocoder = $this->geocoder();
        if ($geocoder->name() !== 'nominatim') {
            return $geocoder->name();
        }
        $url = (string)\craft\helpers\App::parseEnv($this->settings()->nominatimUrl);
        return $url === '' || str_contains($url, 'nominatim.openstreetmap.org') ? 'nominatim' : 'nominatim-own';
    }

    public function photon(): Photon
    {
        return new Photon($this, Plugin::getInstance()->getVersion(), $this->siteHost(), (string)\craft\helpers\App::parseEnv($this->settings()->photonUrl));
    }

    /** HttpClient port for the providers. */
    public function get(string $url, array $headers = []): HttpResponse
    {
        $headers += ['User-Agent' => sprintf('RumboMaps/%s (+%s)', Plugin::getInstance()->getVersion(), $this->siteHost())];
        // The public Nominatim service allows one request a second for the whole site.
        if (str_contains($url, 'nominatim.openstreetmap.org')) {
            $mutex = Craft::$app->getMutex();
            if ($mutex->acquire('rumbo-maps:nominatim', 5)) {
                try {
                    $last = (float)Craft::$app->getCache()->get('rumbo-maps:nominatim-last');
                    $wait = 1.0 - (microtime(true) - $last);
                    if ($wait > 0) {
                        usleep((int)($wait * 1e6));
                    }
                    Craft::$app->getCache()->set('rumbo-maps:nominatim-last', microtime(true), 60);
                } finally {
                    $mutex->release('rumbo-maps:nominatim');
                }
            }
        }
        try {
            $response = Craft::createGuzzleClient(['timeout' => 8, 'http_errors' => false])->get($url, ['headers' => $headers]);
            return new HttpResponse($response->getStatusCode(), (string)$response->getBody());
        } catch (\Throwable $e) {
            return new HttpResponse(0, $e->getMessage());
        }
    }

    private function cacheGet(string $hash): ?array
    {
        $row = (new Query())->select(['result'])->from(Install::GEOCACHE)
            ->where(['hash' => $hash])
            ->andWhere(['>', 'expires', Db::prepareDateForDb(new \DateTime())])
            ->scalar();
        if ($row === false || $row === null) {
            return null;
        }
        // A JSON column: older rows hold the result encoded twice.
        for ($i = 0; $i < 2 && is_string($row); $i++) {
            $row = json_decode($row, true);
        }
        return is_array($row) ? $row : [];
    }

    private function cacheSet(string $hash, string $provider, array $result): void
    {
        Db::upsert(Install::GEOCACHE, [
            'hash' => $hash,
            'provider' => $provider,
            'result' => $result,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'expires' => Db::prepareDateForDb(new \DateTime('+' . self::CACHE_DAYS . ' days')),
        ]);
    }

    public function collectGarbage(): void
    {
        Db::delete(Install::GEOCACHE, ['<', 'expires', Db::prepareDateForDb(new \DateTime())]);
    }

    private function siteHost(): string
    {
        return (string)(parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '', PHP_URL_HOST) ?: 'localhost');
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
