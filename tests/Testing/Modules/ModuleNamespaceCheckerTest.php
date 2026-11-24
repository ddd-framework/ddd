<?php

declare(strict_types=1);

namespace DDD\Tests\Testing\Modules;

use DDD\Testing\Modules\ModuleNamespaceChecker;
use DDD\Testing\Modules\ModuleNamespacePackage;
use DDD\Testing\Modules\ModuleNamespaceViolation;
use PHPUnit\Framework\TestCase;

/** The owned-namespace check over two fixture packages sharing the domain `Fixture\Domain\Shared` */
class ModuleNamespaceCheckerTest extends TestCase
{
    protected const string FIXTURES = __DIR__ . '/Fixtures';

    public function testTheClassesAreReadFromTheFilesWithTheirModifiers(): void
    {
        $classNames = (new ModuleNamespaceChecker())->findDeclaredClassNames(self::FIXTURES . '/PackageOne/src');
        sort($classNames);
        self::assertSame(['Fixture\Domain\Shared\Feature\OwnedThing', 'Fixture\Domain\Shared\SharedThing'], $classNames);
    }

    public function testAClassAndAConfigFileShippedByTwoPackagesAreReported(): void
    {
        $violations = (new ModuleNamespaceChecker())->checkPackages([$this->packageOne(), $this->packageTwo()]);

        $duplicateClasses = $violations->filterByKind(ModuleNamespaceViolation::KIND_DUPLICATE_CLASS)->getElements();
        self::assertCount(1, $duplicateClasses);
        self::assertSame('Fixture\Domain\Shared\SharedThing', $duplicateClasses[0]->subject);
        self::assertSame(['package-one', 'package-two'], $duplicateClasses[0]->packageNames);

        $duplicateConfigFiles = $violations->filterByKind(ModuleNamespaceViolation::KIND_DUPLICATE_CONFIG_FILE)->getElements();
        self::assertCount(1, $duplicateConfigFiles);
        self::assertSame('Shared/settings.php', $duplicateConfigFiles[0]->subject);
    }

    public function testAnAcceptedSharedConfigFileIsNotReported(): void
    {
        $violations = (new ModuleNamespaceChecker())->checkPackages([$this->packageOne(), $this->packageTwo()], ['Shared/settings.php']);
        self::assertCount(0, $violations->filterByKind(ModuleNamespaceViolation::KIND_DUPLICATE_CONFIG_FILE));
    }

    public function testAClassOutsideTheDeclaredNamespacesIsReported(): void
    {
        $ownedPackageOne = new ModuleNamespacePackage('package-one', self::FIXTURES . '/PackageOne/src', null, ['Fixture\Domain\Shared\Feature\\']);
        $violations = (new ModuleNamespaceChecker())->checkPackages([$ownedPackageOne]);

        $outsideClasses = $violations->filterByKind(ModuleNamespaceViolation::KIND_CLASS_OUTSIDE_OWNED_NAMESPACES)->getElements();
        self::assertCount(1, $outsideClasses);
        self::assertSame('Fixture\Domain\Shared\SharedThing', $outsideClasses[0]->subject);
        self::assertStringContainsString('outside the namespaces', $outsideClasses[0]->describe());
    }

    public function testAPackageWithoutDeclaredNamespacesIsNotRestricted(): void
    {
        $violations = (new ModuleNamespaceChecker())->checkPackages([$this->packageTwo()]);
        self::assertCount(0, $violations);
    }

    public function testCoreAloneHasNoViolations(): void
    {
        self::assertCount(0, (new ModuleNamespaceChecker())->checkPackages([ModuleNamespacePackage::forCore()]));
    }

    protected function packageOne(): ModuleNamespacePackage
    {
        return new ModuleNamespacePackage('package-one', self::FIXTURES . '/PackageOne/src', self::FIXTURES . '/PackageOne/config/app');
    }

    protected function packageTwo(): ModuleNamespacePackage
    {
        return new ModuleNamespacePackage('package-two', self::FIXTURES . '/PackageTwo/src', self::FIXTURES . '/PackageTwo/config/app');
    }
}
