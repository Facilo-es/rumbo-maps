<?php

declare(strict_types=1);

namespace facilo\rumbo\tests\Core;

use facilo\rumbo\Core\AddressParts;
use PHPUnit\Framework\TestCase;

final class AddressPartsTest extends TestCase
{
    public function testCleansValuesAndKeepsKeyOrder(): void
    {
        $p = new AddressParts(' 1 ', "Calle   Mayor", 'Madrid', 28013, '', null, 'España', 'es');
        self::assertSame(AddressParts::KEYS, array_keys($p->toArray()));
        self::assertSame('1', $p->number);
        self::assertSame('Calle Mayor', $p->street);
        self::assertSame('28013', $p->postcode);
        self::assertNull($p->county);
        self::assertSame('ES', $p->countryCode);
    }

    public function testRejectsBadCountryCode(): void
    {
        self::assertNull((new AddressParts(countryCode: 'ESP'))->countryCode);
        self::assertNull((new AddressParts(countryCode: '1A'))->countryCode);
    }

    public function testFromArrayIgnoresUnknownKeysAndRoundTrips(): void
    {
        $p = AddressParts::fromArray(['street' => 'Gran Vía', 'city' => 'Madrid', 'planet' => 'Earth']);
        self::assertSame($p->toArray(), AddressParts::fromArray($p->toArray())->toArray());
        self::assertArrayNotHasKey('planet', $p->toArray());
    }

    public function testOneLineAndIsEmpty(): void
    {
        $p = new AddressParts('1', 'Calle Mayor', 'Madrid', '28013', null, 'Comunidad de Madrid', 'España', 'ES');
        self::assertSame('Calle Mayor 1, 28013 Madrid, Comunidad de Madrid, España', $p->oneLine());
        self::assertSame('Madrid, España', (new AddressParts(city: 'Madrid', state: 'Madrid', country: 'España'))->oneLine());
        self::assertTrue((new AddressParts())->isEmpty());
        self::assertSame('', (new AddressParts())->oneLine());
        self::assertFalse($p->isEmpty());
    }
}
