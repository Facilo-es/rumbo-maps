<?php

namespace facilo\rumbo\Craft;

use Craft;
use facilo\rumbo\Core\AppleJwt;
use facilo\rumbo\Core\Ports\HttpClient;
use facilo\rumbo\Plugin;

/** Apple Maps credentials from settings (team ID, key ID, private key; env vars allowed). */
final class AppleToken
{
    private const CACHE = 'rumbo-maps:apple-access';

    /** @return array{0:string,1:string,2:string} team ID, key ID, private key */
    private static function creds(): array
    {
        $s = Plugin::getInstance()->getSettings();
        return [$s->key('apple', 'teamId'), $s->key('apple', 'keyId'), $s->key('apple', 'privateKey')];
    }

    public static function configured(): bool
    {
        [$team, $kid, $key] = self::creds();
        return $team !== '' && $kid !== '' && $key !== '';
    }

    /** A 30-minute MapKit JS token, limited to this site's origin. */
    public static function mapkit(?string $origin): string
    {
        [$team, $kid, $key] = self::creds();
        return AppleJwt::mint($team, $kid, $key, time(), 1800, $origin);
    }

    /** A Maps Server API access token, cached until shortly before it expires. */
    public static function serverAccess(HttpClient $http): string
    {
        $cache = Craft::$app->getCache();
        $hit = $cache->get(self::CACHE);
        [$team, $kid, $key] = self::creds();
        $fp = hash('sha256', $team . $kid . $key);
        if (is_array($hit) && ($hit['fp'] ?? '') === $fp) {
            return $hit['token'];
        }
        [$token, $ttl] = AppleJwt::accessToken($http, AppleJwt::mint($team, $kid, $key, time(), 600));
        $cache->set(self::CACHE, ['fp' => $fp, 'token' => $token], max(60, $ttl - 120));
        return $token;
    }
}
