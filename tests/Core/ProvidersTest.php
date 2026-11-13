<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use facilo\rumbo\Core\Ports\HttpClient;
use facilo\rumbo\Core\Ports\HttpResponse;
use facilo\rumbo\Core\Providers\Apple;
use facilo\rumbo\Core\Providers\Geocoder;
use facilo\rumbo\Core\Providers\GeoOptions;
use facilo\rumbo\Core\Providers\GeoResult;
use facilo\rumbo\Core\Providers\Google;
use facilo\rumbo\Core\Providers\Here;
use facilo\rumbo\Core\Providers\Mapbox;
use facilo\rumbo\Core\Providers\MapTiler;
use facilo\rumbo\Core\Providers\Nominatim;
use facilo\rumbo\Core\Providers\Photon;
use facilo\rumbo\Core\Providers\ProviderError;
use facilo\rumbo\tests\Core\Support\FakeHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProvidersTest extends TestCase
{
    private const KEY = 'sk.SECRET-key_123';

    private static function make(string $name, HttpClient $http): Geocoder
    {
        return match ($name) {
            'nominatim' => new Nominatim($http, '1.0.0', 'example.es'),
            'photon' => new Photon($http, '1.0.0', 'example.es'),
            'google' => new Google($http, self::KEY),
            'mapbox' => new Mapbox($http, self::KEY),
            'maptiler' => new MapTiler($http, self::KEY),
            'here' => new Here($http, self::KEY),
            'apple' => new Apple($http, self::KEY),
        };
    }

    private static function options(): GeoOptions
    {
        return new GeoOptions(['es'], 'es', ['minLat' => 40.0, 'minLng' => -4.0, 'maxLat' => 41.0, 'maxLng' => -3.0], 5);
    }

    public static function providers(): array
    {
        return array_map(static fn($p) => [$p], array_combine(
            ['nominatim', 'photon', 'google', 'mapbox', 'maptiler', 'here', 'apple'],
            ['nominatim', 'photon', 'google', 'mapbox', 'maptiler', 'here', 'apple'],
        ));
    }

    public static function keyedProviders(): array
    {
        return array_intersect_key(self::providers(), array_flip(['google', 'mapbox', 'maptiler', 'here', 'apple']));
    }

    #[DataProvider('providers')]
    public function testT45MadridFixtureNormalisesToTheSameParts(string $name): void
    {
        $http = FakeHttp::fixture("$name-madrid.json");
        $results = self::make($name, $http)->lookup('Calle Mayor 1, Madrid', self::options());

        self::assertCount(1, $results);
        $r = $results[0];
        self::assertInstanceOf(GeoResult::class, $r);
        self::assertSame($name, $r->source);
        self::assertEqualsWithDelta(40.41669, $r->lat, 0.0001);
        self::assertEqualsWithDelta(-3.70642, $r->lng, 0.0001);
        self::assertNotSame('', $r->formatted);
        $p = $r->parts->toArray();
        self::assertSame(
            ['number' => '1', 'street' => 'Calle Mayor', 'city' => 'Madrid', 'postcode' => '28013', 'state' => 'Comunidad de Madrid', 'country' => 'España', 'countryCode' => 'ES'],
            array_diff_key($p, ['county' => 0]),
        );
        self::assertCount(1, $http->requests);
        self::assertStringStartsWith('https://', $http->lastUrl());
    }

    #[DataProvider('keyedProviders')]
    public function testKeyedProviderErrorShowsMessageAndHidesKey(string $name): void
    {
        $body = (string) file_get_contents(__DIR__ . "/fixtures/$name-403.json");
        $http = new FakeHttp([new HttpResponse(403, str_replace('}', ', "echo": "' . self::KEY . '"}', $body))]);

        try {
            self::make($name, $http)->lookup('Madrid', self::options());
            self::fail('No ProviderError');
        } catch (ProviderError $e) {
            self::assertSame(403, $e->httpStatus);
            self::assertNotSame('', $e->providerMessage);
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertStringNotContainsString(rawurlencode(self::KEY), $e->getMessage());
            self::assertStringContainsString('(HTTP 403)', $e->getMessage());
            self::assertNotSame('', $e->apiHint);
        }
    }

    public function testGoogleDeniedOn200AndZeroResults(): void
    {
        $denied = FakeHttp::fixture('google-403.json');
        try {
            self::make('google', $denied)->lookup('Madrid', new GeoOptions());
            self::fail('No ProviderError');
        } catch (ProviderError $e) {
            self::assertSame('Geocoding API', $e->apiHint);
            self::assertStringContainsString('REQUEST_DENIED: The provided API key is invalid.', $e->getMessage());
            self::assertStringContainsString('Check that Geocoding API is enabled for this key', $e->getMessage());
        }

        $zero = new FakeHttp([new HttpResponse(200, '{"results":[],"status":"ZERO_RESULTS"}')]);
        self::assertSame([], self::make('google', $zero)->lookup('nowhere', new GeoOptions()));
    }

    public function testGoogleRequestShape(): void
    {
        $http = FakeHttp::fixture('google-madrid.json');
        self::make('google', $http)->lookup('Calle Mayor 1', new GeoOptions(['ES', 'PT'], 'es', ['minLat' => 40, 'minLng' => -4, 'maxLat' => 41, 'maxLng' => -3]));
        $q = $http->lastQuery();
        self::assertStringStartsWith(Google::GEOCODE_URL . '?', $http->lastUrl());
        self::assertSame('country:ES|country:PT', $q['components']);
        self::assertSame('40,-4|41,-3', $q['bounds']);
        self::assertSame('es', $q['language']);
        self::assertSame(self::KEY, $q['key']);

        $r = self::make('google', FakeHttp::fixture('google-madrid.json'))->reverse(40.4167, -3.7064, new GeoOptions());
        self::assertSame('Madrid', $r?->parts->county);
    }

    public function testGoogleAutocompleteRequest(): void
    {
        $req = (new Google(new FakeHttp(), self::KEY))->autocompleteRequest('Calle May', self::options(), 'tok-1');
        self::assertSame('POST', $req['method']);
        self::assertSame(Google::AUTOCOMPLETE_URL, $req['url']);
        self::assertSame(self::KEY, $req['headers']['X-Goog-Api-Key']);
        $body = json_decode($req['body'], true);
        self::assertSame('Calle May', $body['input']);
        self::assertSame(['es'], $body['includedRegionCodes']);
        self::assertSame('es', $body['languageCode']);
        self::assertEquals(['latitude' => 40.0, 'longitude' => -4.0], $body['locationBias']['rectangle']['low']);
        self::assertSame('tok-1', $body['sessionToken']);
        self::assertStringNotContainsString(self::KEY, $req['url']);
    }

    public function testMapboxUsesPermanentAndCountry(): void
    {
        $http = FakeHttp::fixture('mapbox-madrid.json');
        self::make('mapbox', $http)->lookup('Calle Mayor', self::options());
        $q = $http->lastQuery();
        self::assertStringStartsWith(Mapbox::BASE_URL . '/forward?', $http->lastUrl());
        self::assertSame('true', $q['permanent']);
        self::assertSame('es', $q['country']);
        self::assertSame('-3.5,40.5', $q['proximity']);

        $temp = FakeHttp::fixture('mapbox-madrid.json');
        (new Mapbox($temp, self::KEY, false))->reverse(40.4, -3.7, new GeoOptions());
        self::assertArrayNotHasKey('permanent', $temp->lastQuery());
        self::assertStringStartsWith(Mapbox::BASE_URL . '/reverse?', $temp->lastUrl());
    }

    public function testT51HereUsesApiKeyOnly(): void
    {
        $http = FakeHttp::fixture('here-madrid.json');
        $here = self::make('here', $http);
        $here->lookup('Calle Mayor', new GeoOptions(['ES', 'PT']));
        $here->reverse(40.4167, -3.7064, new GeoOptions());

        foreach ($http->requests as $req) {
            parse_str((string) parse_url($req['url'], PHP_URL_QUERY), $q);
            self::assertSame(self::KEY, $q['apiKey']);
            self::assertArrayNotHasKey('app_id', $q);
            self::assertArrayNotHasKey('app_code', $q);
            self::assertArrayNotHasKey('Authorization', $req['headers']);
        }
        parse_str((string) parse_url($http->requests[0]['url'], PHP_URL_QUERY), $first);
        self::assertSame('countryCode:ESP,PRT', $first['in']);
        self::assertStringStartsWith(Here::REVGEOCODE_URL, $http->requests[1]['url']);
    }

    public function testAppleSendsBearerToken(): void
    {
        $http = FakeHttp::fixture('apple-madrid.json');
        self::make('apple', $http)->lookup('Calle Mayor', self::options());
        self::assertSame('Bearer ' . self::KEY, $http->requests[0]['headers']['Authorization']);
        self::assertSame('ES', $http->lastQuery()['limitToCountries']);
        self::assertSame('40.5,-3.5', $http->lastQuery()['searchLocation']);
        self::assertStringNotContainsString(self::KEY, $http->lastUrl());
    }

    public function testMapTilerPathAndReverse(): void
    {
        $http = FakeHttp::fixture('maptiler-madrid.json');
        $mt = self::make('maptiler', $http);
        $mt->lookup('Calle Mayor 1/2', new GeoOptions(['ES']));
        self::assertStringStartsWith(MapTiler::BASE_URL . '/Calle%20Mayor%201%2F2.json?', $http->lastUrl());
        $mt->reverse(40.4167, -3.7064, new GeoOptions());
        self::assertStringStartsWith(MapTiler::BASE_URL . '/-3.7064,40.4167.json?', $http->lastUrl());
    }

    public function testNominatimRequestAndUserAgent(): void
    {
        $http = FakeHttp::fixture('nominatim-madrid.json');
        $n = new Nominatim($http, '1.2.0', 'https://example.es');
        $n->lookup('Calle Mayor', new GeoOptions(['ES', 'PT'], 'es', ['minLat' => 40, 'minLng' => -4, 'maxLat' => 41, 'maxLng' => -3]));
        $q = $http->lastQuery();
        self::assertSame('RumboMaps/1.2.0 (+https://example.es)', $http->requests[0]['headers']['User-Agent']);
        self::assertSame('es,pt', $q['countrycodes']);
        self::assertSame('-4,41,-3,40', $q['viewbox']);
        self::assertSame('jsonv2', $q['format']);
        self::assertTrue($n->isPublic());
        self::assertFalse((new Nominatim($http, '1', 'x', 'https://geo.example.es/'))->isPublic());

        $none = new FakeHttp([new HttpResponse(200, '{"error":"Unable to geocode"}')]);
        self::assertNull((new Nominatim($none, '1', 'x'))->reverse(0.0, 0.0, new GeoOptions()));
    }

    public function testPhotonFiltersCountriesClientSide(): void
    {
        $http = FakeHttp::fixture('photon-madrid.json');
        $results = self::make('photon', $http)->lookup('Mayor', new GeoOptions(['ES'], 'es-ES'));
        self::assertCount(1, $results);
        self::assertArrayNotHasKey('lang', $http->lastQuery());

        $all = self::make('photon', FakeHttp::fixture('photon-madrid.json'))->lookup('Mayor', new GeoOptions([], 'de'));
        self::assertCount(2, $all);
        self::assertSame('Rua Mayor', $all[1]->parts->street);
        self::assertSame('PT', $all[1]->parts->countryCode);
    }

    public function testNetworkAndJsonFailuresBecomeProviderErrors(): void
    {
        $down = new FakeHttp([new \RuntimeException('cURL error 6 for https://x/?key=' . self::KEY)]);
        try {
            self::make('maptiler', $down)->lookup('Madrid', new GeoOptions());
            self::fail('No ProviderError');
        } catch (ProviderError $e) {
            self::assertSame(0, $e->httpStatus);
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('not valid JSON');
        self::make('here', new FakeHttp([new HttpResponse(200, '<html>')]))->lookup('Madrid', new GeoOptions());
    }

    public function testProviderErrorScrub(): void
    {
        self::assertSame('a *** b *** c', ProviderError::scrub('a k+y/z b k%2By%2Fz c', ['k+y/z']));
        self::assertSame('*** *** ***', ProviderError::scrub('my key my+key my%20key', ['my key']));
        self::assertSame('abc short', ProviderError::scrub('abc short', ['abc']));
        self::assertSame(500, mb_strlen(ProviderError::scrub(str_repeat('x', 900), [])));
    }

    public function testGeoOptionsAndResultRoundTrip(): void
    {
        $o = new GeoOptions(['es', ' pt ', 'ESP', 5, 'es'], 'es-ES', ['minLat' => 1], 500);
        self::assertSame(['ES', 'PT'], $o->countries);
        self::assertSame('es', $o->baseLanguage());
        self::assertNull($o->biasBox);
        self::assertSame(50, $o->limit);
        self::assertSame('', (new GeoOptions([], 'es"; DROP'))->language);

        $r = self::make('here', FakeHttp::fixture('here-madrid.json'))->lookup('x', new GeoOptions())[0];
        self::assertEquals($r, GeoResult::fromArray($r->toArray()));
    }
}
