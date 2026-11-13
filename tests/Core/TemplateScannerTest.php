<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use facilo\rumbo\Core\TemplateScanner;
use PHPUnit\Framework\TestCase;

final class TemplateScannerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/templates';

    public function testT25SeededTemplatesMatchExpectedFindings(): void
    {
        $expected = json_decode((string) file_get_contents(__DIR__ . '/fixtures/templates-expected.json'), true);
        self::assertSame($expected, (new TemplateScanner())->scanTree(self::FIXTURES));
    }

    public function testEveryF4CallIsFound(): void
    {
        $calls = array_column((new TemplateScanner())->scanTree(self::FIXTURES)['findings'], 'call');
        foreach ([
            'map.lat', 'map.lng', 'map.zoom', 'entry.myMap.address', 'map.parts.city', 'store.myMap.distance', 'map.streetAddress',
            'map.city', 'map.postcode', 'entry.myMap.embed()', 'entry.myMap.img()', 'entry.myMap.imgSrcSet()',
            'craft.maps.embed()', 'craft.maps.img()', 'craft.maps.imgSrcSet()', 'craft.maps.getLatLngFromAddress()',
            'craft.maps.getUserLocation()', "orderBy('distance')", 'craft.entries.myMap({ coordinate, radius })',
        ] as $call) {
            self::assertContains($call, $calls);
        }
    }

    public function testBadgesAndLines(): void
    {
        $src = "<p>{{ entry.place.parts.city }}</p>\n{# {{ entry.place.img() }} #}\n{{ entry.place.lat }} {{ thing.lat }}\n{{ 'x.place.embed()' }}\n{{ craft.maps.mapToken }}";
        $f = (new TemplateScanner())->scan($src, 'a.twig');
        self::assertSame([
            ['file' => 'a.twig', 'line' => 1, 'call' => 'entry.place.parts.city', 'badge' => 'lite', 'replacement' => null],
            ['file' => 'a.twig', 'line' => 3, 'call' => 'entry.place.lat', 'badge' => 'lite', 'replacement' => null],
            ['file' => 'a.twig', 'line' => 5, 'call' => 'craft.maps.mapToken', 'badge' => 'change', 'replacement' => 'Not needed: Rumbo maps need no token'],
        ], $f);
    }

    public function testGenericNamesNeedAKnownValue(): void
    {
        self::assertSame([], (new TemplateScanner())->scan('{{ user.address }} {{ order.city }} {{ craft.entries.orderBy("title") }}', 'b.twig'));
        self::assertSame([], (new TemplateScanner())->scan('{{ x.img() }}', 'b.twig'));
    }

    public function testQueryHashWithoutLocationKeysIsIgnored(): void
    {
        $f = (new TemplateScanner())->scan("{% set e = craft.entries({ section: 'news', limit: 3 }) %}{% set n = craft.entries.myMap({ 'radius': 5, \"location\": 'Madrid' }) %}", 'c.twig');
        self::assertCount(1, $f);
        self::assertSame('craft.entries.myMap({ radius, location })', $f[0]['call']);
    }

    public function testSummaryAndExtraRoots(): void
    {
        $dir = sys_get_temp_dir() . '/rumbo-scan-' . bin2hex(random_bytes(4));
        mkdir($dir . '/site/sub', 0777, true);
        mkdir($dir . '/extra');
        file_put_contents($dir . '/site/sub/a.twig', '{{ entry.map.embed() }}');
        file_put_contents($dir . '/site/notes.txt', '{{ entry.map.embed() }}');
        file_put_contents($dir . '/extra/b.html', '{{ entry.map.parts.city }}');
        @symlink($dir . '/extra', $dir . '/site/link');

        try {
            $r = (new TemplateScanner())->scanTree($dir . '/site', [$dir . '/extra']);
            self::assertSame(['sub/a.twig', 'extra/b.html'], array_column($r['findings'], 'file'));
            self::assertSame(['total' => 2, 'lite' => 1, 'pro' => 1, 'change' => 0], $r['summary']);
            self::assertSame(2, $r['files']);
            self::assertSame($r['summary'], TemplateScanner::summary($r['findings']));
        } finally {
            @unlink($dir . '/site/link');
            array_map('unlink', [$dir . '/site/sub/a.twig', $dir . '/site/notes.txt', $dir . '/extra/b.html']);
            array_map('rmdir', [$dir . '/site/sub', $dir . '/site', $dir . '/extra', $dir]);
        }
    }
}
