<?php

declare(strict_types=1);

namespace facilo\rumbo\Core;

use facilo\rumbo\Core\Ports\HttpClient;
use facilo\rumbo\Core\Providers\ProviderError;

/**
 * Apple Maps tokens, signed on the customer's own server with their MapKit key (ES256).
 * The browser gets a short-lived MapKit JS token; the Maps Server API gets an access token
 * exchanged from the same kind of JWT. The private key never leaves the server.
 */
final class AppleJwt
{
    public const TOKEN_URL = 'https://maps-api.apple.com/v1/token';

    /** A signed JWT. $origin, when given, limits a MapKit JS token to that website. */
    public static function mint(string $teamId, string $keyId, string $privateKeyPem, int $now, int $ttl = 1800, ?string $origin = null): string
    {
        $header = ['alg' => 'ES256', 'kid' => $keyId, 'typ' => 'JWT'];
        $claims = ['iss' => $teamId, 'iat' => $now, 'exp' => $now + $ttl];
        if ($origin !== null && $origin !== '') {
            $claims['origin'] = $origin;
        }
        $input = self::b64(json_encode($header, JSON_UNESCAPED_SLASHES)) . '.' . self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private(self::pem($privateKeyPem));
        if ($key === false) {
            throw new ProviderError('Apple Maps', 0, 'the private key could not be read. Paste the whole .p8 file, including the BEGIN and END lines', '');
        }
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new ProviderError('Apple Maps', 0, 'the private key could not sign the token. It must be a MapKit key (.p8)', '');
        }
        return $input . '.' . self::b64(self::derToRaw($der));
    }

    /** Exchanges a JWT for a Maps Server API access token: [token, expires in seconds]. */
    public static function accessToken(HttpClient $http, string $jwt): array
    {
        $res = $http->get(self::TOKEN_URL, ['Authorization' => 'Bearer ' . $jwt]);
        $data = json_decode($res->body, true);
        if (!$res->isOk() || !is_array($data) || empty($data['accessToken'])) {
            $msg = is_array($data) ? (string)($data['error']['message'] ?? $data['message'] ?? '') : '';
            throw new ProviderError('Apple Maps', $res->status, $msg !== '' ? $msg : 'the token was refused', 'the Maps Server API (Maps IDs and keys in your Apple Developer account)', [$jwt]);
        }
        return [(string)$data['accessToken'], (int)($data['expiresInSeconds'] ?? 1800)];
    }

    /** Accepts a .p8 with or without its PEM lines, and with escaped newlines from a .env file. */
    public static function pem(string $key): string
    {
        $key = trim(str_replace(['\\n', "\r"], ["\n", ''], $key));
        if (!str_contains($key, '-----BEGIN')) {
            $key = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(preg_replace('~\s+~', '', $key), 64, "\n") . '-----END PRIVATE KEY-----';
        }
        return $key;
    }

    /** OpenSSL's DER ECDSA signature -> the 64-byte R||S that JWS wants. */
    public static function derToRaw(string $der): string
    {
        $pos = 2; // SEQUENCE, length (< 128 for P-256)
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $pos += 2 + $len;
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
