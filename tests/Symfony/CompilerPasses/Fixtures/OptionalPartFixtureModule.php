<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures;

use DDD\Infrastructure\Modules\DDDModule;

/** A module with one part beside an installed package and one beside a package the application lacks */
class OptionalPartFixtureModule extends DDDModule
{
    public static function getSourcePath(): string
    {
        return __DIR__ . '/OptionalPartSource';
    }

    public static function getNamespacesRequiringClasses(): array
    {
        return [
            'DDD\\Tests\\Symfony\\CompilerPasses\\Fixtures\\OptionalPartSource\\Present\\' => DDDModule::class,
            'DDD\\Tests\\Symfony\\CompilerPasses\\Fixtures\\OptionalPartSource\\Missing\\' => 'DDD\\Tests\\NotInstalledPackage\\PackageBase',
        ];
    }
}
