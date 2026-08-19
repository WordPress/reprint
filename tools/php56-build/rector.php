<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use WordPress\Reprint\Build\Rector\DowngradeArrayDestructuringRector;
use WordPress\Reprint\Build\Rector\DowngradeClassConstantVisibilityRector;
use WordPress\Reprint\Build\Rector\DowngradeFunctionTypeDeclarationsRector;
use WordPress\Reprint\Build\Rector\DowngradeNullCoalescingRector;

require_once __DIR__ . '/vendor/autoload.php';

return RectorConfig::configure()
    ->withoutParallel()
    ->withPhpVersion(PhpVersion::PHP_56)
    ->withRules([
        DowngradeFunctionTypeDeclarationsRector::class,
        DowngradeClassConstantVisibilityRector::class,
        DowngradeNullCoalescingRector::class,
        DowngradeArrayDestructuringRector::class,
    ]);
