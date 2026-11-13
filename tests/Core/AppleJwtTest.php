<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use facilo\rumbo\Core\AppleJwt;
use facilo\rumbo\Core\Ports\HttpClient;
use facilo\rumbo\Core\Ports\HttpResponse;
use facilo\rumbo\Core\Providers\ProviderError;
use PHPUnit\Framework\TestCase;

final class AppleJwtTest extends TestCase
{
    private static function key(): array
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($k, $pem);
        return [$pem, openssl_pkey_get_details($k)['key']];
    }

    public function testMintsAVerifiableEs256Token(): void
    {
        [$pem, $pub] = self::key();
        $jwt = AppleJwt::mint('TEAM123456', 'KEY1234567', $pem, 1_700_000_000, 600, 'https://example.es');
        [$h, $p, $s] = explode('.', $jwt);
        $dec = fn ($x) => base64_decode(strtr($x, '-_', '+/'));
        self::assertSame(['alg' => 'ES256', 'kid' => 'KEY1234567', 'typ' => 'JWT'], json_decode($dec($h), true));
        self::assertSame(['iss' => 'TEAM123456', 'iat' => 1_700_000_000, 'exp' => 1_700_000_600, 'origin' => 'https://example.es'], json_decode($dec($p), true));
        $raw = $dec($s);
        self::assertSame(64, strlen($raw));
        // back to DER to verify with OpenSSL
        $int = function (string $b): string {
            $b = ltrim($b, "\0");
            if (ord($b[0]) & 0x80) {
                $b = "\0" . $b;
            }
            return "\x02" . chr(strlen($b)) . $b;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        self::assertSame(1, openssl_verify("$h.$p", "\x30" . chr(strlen($seq)) . $seq, $pub, OPENSSL_ALGO_SHA256));
    }

    public function testAcceptsEscapedNewlinesAndBareKeys(): void
    {
        [$pem] = self::key();
        self::assertNotFalse(openssl_pkey_get_private(AppleJwt::pem(str_replace("\n", '\\n', $pem))));
        $body = preg_replace('~-----[^-]+-----|\s~', '', $pem);
        self::assertNotFalse(openssl_pkey_get_private(AppleJwt::pem($body)));
    }

    public function testBadKeyIsAClearError(): void
    {
        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('private key could not be read');
        AppleJwt::mint('T', 'K', 'not a key', 1, 60);
    }

    public function testExchangesForAnAccessTokenAndScrubsTheJwtFromErrors(): void
    {
        $http = new class () implements HttpClient {
            public array $seen = [];
            public int $status = 200;
            public string $body = '{"accessToken":"abc","expiresInSeconds":1800}';
            public function get(string $url, array $headers = []): HttpResponse
            {
                $this->seen[] = [$url, $headers];
                return new HttpResponse($this->status, $this->body);
            }
        };
        self::assertSame(['abc', 1800], AppleJwt::accessToken($http, 'jwt.value.sig'));
        self::assertSame(['Authorization' => 'Bearer jwt.value.sig'], $http->seen[0][1]);
        $http->status = 401;
        $http->body = '{"error":{"message":"Not Authorized jwt.value.sig"}}';
        try {
            AppleJwt::accessToken($http, 'jwt.value.sig');
            self::fail('expected an error');
        } catch (ProviderError $e) {
            self::assertStringContainsString('(HTTP 401)', $e->getMessage());
            self::assertStringNotContainsString('jwt.value.sig', $e->getMessage());
        }
    }
}
