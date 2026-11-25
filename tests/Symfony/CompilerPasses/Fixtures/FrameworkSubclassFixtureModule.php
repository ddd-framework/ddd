<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures;

use DDD\Infrastructure\Modules\DDDModule;

/** A module whose command subclasses a framework command */
class FrameworkSubclassFixtureModule extends DDDModule
{
    public static function getSourcePath(): string
    {
        return __DIR__ . '/FrameworkSubclassModuleSource';
    }
}
