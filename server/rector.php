<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/app/src', __DIR__ . '/app/bin', __DIR__ . '/htdocs', __DIR__ . '/tests'])
    ->withSkip([__DIR__ . '/app/vendor'])
    ->withSets([
        LevelSetList::UP_TO_PHP_84,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
    ])
    ->withPhpSets(php84: true);
