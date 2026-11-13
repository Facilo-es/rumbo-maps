<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([__DIR__ . '/src']);
    $ecsConfig->skip([__DIR__ . '/src/web/node_modules', __DIR__ . '/src/web/dist']);
    $ecsConfig->parallel();
    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
