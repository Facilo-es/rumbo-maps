<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use facilo\rumbo\Core\Countries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CountriesTest extends TestCase
{
    public static function names(): array
    {
        return [
            ['Spain', 'ES'], ['España', 'ES'], ['ESPAÑA', 'ES'], ['espana', 'ES'], ['Deutschland', 'DE'],
            ['Atlantis', null], ['es', 'ES'], ['EE. UU.', 'US'], ['The Netherlands', 'NL'], ['Portugal', 'PT'],
            ['日本', 'JP'], ['Ελλάδα', 'GR'], ['Türkiye', 'TR'], ['المغرب', 'MA'], ['México', 'MX'],
            ['and', null], ['', null],
        ];
    }

    #[DataProvider('names')]
    public function testCodeFor(string $name, ?string $code): void
    {
        self::assertSame($code, Countries::codeFor($name));
    }

    public function testAlpha3BothWays(): void
    {
        self::assertSame('ESP', Countries::alpha3('es'));
        self::assertSame('ES', Countries::fromAlpha3('esp'));
        self::assertNull(Countries::alpha3('XX'));
        self::assertNull(Countries::fromAlpha3('XXX'));
    }

    public function testNormalise(): void
    {
        self::assertSame('cote d ivoire', Countries::normalise("Côte d'Ivoire"));
        self::assertSame('netherlands', Countries::normalise('  The Netherlands '));
    }
}
