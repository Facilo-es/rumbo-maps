<?php

namespace facilo\rumbo\Craft;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;

/**
 * `{{ value|rumboDistance }}` prints a distance the way Spanish readers expect: "2,4 km".
 * `rumbo` is the short name for `craft.rumbo`, as the spec writes it (F2).
 */
class TwigExtension extends AbstractExtension implements GlobalsInterface
{
    private ?TwigVariable $variable = null;

    public function getGlobals(): array
    {
        return ['rumbo' => $this->variable ??= new TwigVariable()];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('rumboDistance', [self::class, 'distance'])];
    }

    public static function distance(?float $value, string $unit = 'km'): string
    {
        if ($value === null) {
            return '';
        }
        $decimals = $value < 10 ? 1 : 0;
        return \Craft::$app->getFormatter()->asDecimal($value, $decimals) . ' ' . $unit;
    }
}
