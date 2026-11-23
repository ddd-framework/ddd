<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Services;

use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Infrastructure\Services\Fixtures\SamePath\FrameworkSamePathObject;
use DDD\Tests\Infrastructure\Services\Fixtures\SamePath\FrameworkSamePathParent;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * An application class at the same path under App\ overrides a framework or module class only when it extends it: a
 * newer release adding a class under a name the application already uses for something else must not be replaced.
 */
class SamePathOverrideResolutionTest extends TestCase
{
    protected mixed $containerServiceClassMapBefore;

    protected function setUp(): void
    {
        foreach (['FrameworkSamePathObject', 'UnrelatedAppSamePathObject', 'FrameworkSamePathParent', 'AppSamePathSubclass'] as $fixtureName) {
            require_once __DIR__ . '/Fixtures/SamePath/' . $fixtureName . '.php';
        }
        $this->containerServiceClassMapBefore = StaticRegistry::$containerServiceClassMap;
        StaticRegistry::$containerServiceClassMap = ['unrelated.service' => 'Unrelated\\Service'];
        unset(StaticRegistry::$frameworkToAppClassNames[FrameworkSamePathObject::class], StaticRegistry::$frameworkToAppClassNames[FrameworkSamePathParent::class]);
    }

    protected function tearDown(): void
    {
        StaticRegistry::$containerServiceClassMap = $this->containerServiceClassMapBefore;
        unset(StaticRegistry::$frameworkToAppClassNames[FrameworkSamePathObject::class], StaticRegistry::$frameworkToAppClassNames[FrameworkSamePathParent::class]);
    }

    public function testAnUnrelatedApplicationClassAtTheSamePathDoesNotOverride(): void
    {
        self::assertSame(FrameworkSamePathObject::class, $this->resolve(FrameworkSamePathObject::class));
    }

    public function testAGenuineSubclassAtTheSamePathOverrides(): void
    {
        self::assertSame('App\\Tests\\Infrastructure\\Services\\Fixtures\\SamePath\\FrameworkSamePathParent', $this->resolve(FrameworkSamePathParent::class));
    }

    protected function resolve(string $className): ?string
    {
        $reflectionMethod = new ReflectionMethod(DDDService::class, 'getContainerServiceClassNameForClassPrivate');
        $errorLogBefore = ini_set('error_log', '/dev/null');
        try {
            return $reflectionMethod->invoke(null, $className);
        } finally {
            ini_set('error_log', (string)$errorLogBefore);
        }
    }
}
