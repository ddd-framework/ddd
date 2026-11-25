<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures;

use DDD\Infrastructure\Modules\DDDModule;

/** A module whose commands an application subclasses */
class CommandSubclassFixtureModule extends DDDModule
{
    public static function getSourcePath(): string
    {
        return __DIR__ . '/CommandSubclassModuleSource';
    }
}
