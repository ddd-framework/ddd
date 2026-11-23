<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses;

use DDD\Tests\Symfony\CompilerPasses\Fixtures\OptionalPartFixtureModule;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\OptionalPartSource\Missing\MissingPartService;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\OptionalPartSource\Present\PresentPartService;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\TagApplyingModuleCompilerPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** A module part that needs another package registers only when that package is installed */
class ModuleCompilerPassOptionalPartTest extends TestCase
{
    public function testAPartWhosePackageIsMissingIsNeitherRegisteredNorLoaded(): void
    {
        $container = new ContainerBuilder();

        (new TagApplyingModuleCompilerPass())->registerServicesOfModule($container, OptionalPartFixtureModule::class);

        self::assertTrue($container->hasDefinition(PresentPartService::class));
        self::assertFalse($container->hasDefinition(MissingPartService::class));
        self::assertFalse(class_exists(MissingPartService::class, false));
        self::assertTrue(OptionalPartFixtureModule::isClassOfAnInactivePart(MissingPartService::class));
        self::assertFalse(OptionalPartFixtureModule::isClassOfAnInactivePart(PresentPartService::class));
    }
}
