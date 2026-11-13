<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use facilo\rumbo\Core\LocatorInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocatorInputTest extends TestCase
{
    public function testDefaultsAndCleanValues(): void
    {
        $r = LocatorInput::validate([]);
        self::assertTrue($r['valid']);
        self::assertSame(LocatorInput::DEFAULTS, $r['values']);

        $r = LocatorInput::validate(['query' => "  Calle\x00 Mayor\t 1\u{200B} ", 'lat' => '40.4168', 'lng' => -3.7038, 'radius' => '25.5', 'unit' => 'MI', 'limit' => '10',
            'filters' => ['storeType' => 'outlet', 'services' => ['wifi', 2, true]]]);
        self::assertSame([], $r['errors']);
        self::assertSame('Calle Mayor 1', $r['values']['query']);
        self::assertSame(40.4168, $r['values']['lat']);
        self::assertSame(-3.7038, $r['values']['lng']);
        self::assertSame(25.5, $r['values']['radius']);
        self::assertSame('mi', $r['values']['unit']);
        self::assertSame(10, $r['values']['limit']);
        self::assertSame(['storeType' => 'outlet', 'services' => ['wifi', '2', '1']], $r['values']['filters']);
    }

    public static function bad(): array
    {
        return [
            [['radius' => 501], 'radius', 'out-of-range'],
            [['radius' => -1], 'radius', 'out-of-range'],
            [['radius' => 'far'], 'radius', 'invalid'],
            [['limit' => 0], 'limit', 'out-of-range'],
            [['limit' => 101], 'limit', 'out-of-range'],
            [['limit' => '5.5'], 'limit', 'invalid'],
            [['query' => 'a'], 'query', 'too-short'],
            [['query' => str_repeat('a', 121)], 'query', 'too-long'],
            [['query' => ['x']], 'query', 'invalid'],
            [['query' => "\xff\xfe"], 'query', 'invalid'],
            [['unit' => 'm'], 'unit', 'invalid'],
            [['lat' => 91, 'lng' => 0], 'lat', 'out-of-range'],
            [['lat' => 0, 'lng' => -180.5], 'lng', 'out-of-range'],
            [['lat' => '1e999', 'lng' => 0], 'lat', 'invalid'],
            [['lat' => 40], 'lng', 'required'],
            [['lng' => 3], 'lat', 'required'],
            [['filters' => 'x'], 'filters', 'invalid'],
            [['filters' => ['1bad' => 'x']], 'filters', 'invalid-handle'],
            [['filters' => ['bad-handle' => 'x']], 'filters', 'invalid-handle'],
            [['filters' => ['ok' => ['a' => 'b']]], 'filters', 'invalid-value'],
            [['filters' => ['ok' => [['nested']]]], 'filters', 'invalid-value'],
            [['filters' => array_fill_keys(range('a', 'k'), '1')], 'filters', 'too-many'],
        ];
    }

    #[DataProvider('bad')]
    public function testRejects(array $q, string $field, string $code): void
    {
        $r = LocatorInput::validate($q);
        self::assertFalse($r['valid']);
        self::assertSame($code, $r['errors'][$field] ?? null);
    }

    public function testBoundariesAccepted(): void
    {
        $r = LocatorInput::validate(['radius' => 0, 'limit' => 100, 'query' => 'ab', 'lat' => -90, 'lng' => 180]);
        self::assertTrue($r['valid'], json_encode($r['errors']));
        self::assertSame(500.0, LocatorInput::validate(['radius' => '500'])['values']['radius']);
        self::assertSame(120, mb_strlen(LocatorInput::validate(['query' => str_repeat('ñ', 120)])['values']['query']));
        self::assertNull(LocatorInput::validate(['query' => "   \x01 "])['values']['query']);
    }
}
