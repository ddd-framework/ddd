<?php

declare(strict_types=1);

namespace DDD\Testing\Modules;

use DDD\Infrastructure\Modules\DDDModule;
use PHPUnit\Framework\TestCase;

/**
 * The owned-namespace check as a test every module suite and every host suite runs ({@see ModuleNamespaceChecker}):
 *
 *   class ModuleNamespacesTest extends \DDD\Testing\Modules\ModuleNamespacesTestCase
 *   {
 *       protected function getProjectDirectory(): string { return dirname(__DIR__); }
 *   }
 *
 * The packages are Core and every DDD module of `vendor/composer/installed.json`, plus the project's own module (its
 * `composer.json` `extra.ddd-module`) when the project is a module. No kernel is booted, no class is loaded except the
 * module classes.
 */
abstract class ModuleNamespacesTestCase extends TestCase
{
    /**
     * @return string The project root (holds `composer.json` and `vendor/`)
     */
    abstract protected function getProjectDirectory(): string;

    /**
     * Config files (relative to `config/app/`) two packages may ship while one of them moves. A list that only shrinks.
     *
     * @return string[]
     */
    protected function getAcceptedSharedConfigFiles(): array
    {
        return [];
    }

    public function testNoClassIsShippedByTwoPackages(): void
    {
        $violations = $this->checkPackages()->filterByKind(ModuleNamespaceViolation::KIND_DUPLICATE_CLASS);
        self::assertCount(0, $violations, "Rename one of them or move it into the owning package:\n" . implode("\n", $violations->describe()));
    }

    public function testNoConfigFileIsShippedByTwoPackages(): void
    {
        $violations = $this->checkPackages()->filterByKind(ModuleNamespaceViolation::KIND_DUPLICATE_CONFIG_FILE);
        self::assertCount(0, $violations, "Put the file below the package's feature folder (config/app/<Domain>/<Feature>/):\n" . implode("\n", $violations->describe()));
    }

    public function testEveryClassLiesInItsPackagesOwnedNamespaces(): void
    {
        $violations = $this->checkPackages()->filterByKind(ModuleNamespaceViolation::KIND_CLASS_OUTSIDE_OWNED_NAMESPACES);
        self::assertCount(0, $violations, "Move the class below one of the package's owned namespaces, or declare the namespace in getOwnedNamespaces():\n" . implode("\n", $violations->describe()));
    }

    protected function checkPackages(): ModuleNamespaceViolations
    {
        $moduleNamespacePackages = [ModuleNamespacePackage::forCore()];
        foreach ($this->findModuleClasses() as $moduleClass) {
            $moduleNamespacePackages[] = ModuleNamespacePackage::fromModuleClass($moduleClass);
        }
        return (new ModuleNamespaceChecker())->checkPackages($moduleNamespacePackages, $this->getAcceptedSharedConfigFiles());
    }

    /**
     * @return class-string<DDDModule>[] The installed modules and the project's own module, each once
     */
    protected function findModuleClasses(): array
    {
        $moduleClasses = [];
        $installedPath = $this->getProjectDirectory() . '/vendor/composer/installed.json';
        if (is_file($installedPath)) {
            $installed = json_decode((string)file_get_contents($installedPath), true);
            foreach (($installed['packages'] ?? $installed ?? []) as $package) {
                $moduleClass = is_array($package) ? ($package['extra']['ddd-module'] ?? null) : null;
                if (is_string($moduleClass) && class_exists($moduleClass) && is_subclass_of($moduleClass, DDDModule::class)) {
                    $moduleClasses[] = $moduleClass;
                }
            }
        }
        $composerPath = $this->getProjectDirectory() . '/composer.json';
        if (is_file($composerPath)) {
            $ownModuleClass = json_decode((string)file_get_contents($composerPath), true)['extra']['ddd-module'] ?? null;
            if (is_string($ownModuleClass) && class_exists($ownModuleClass) && is_subclass_of($ownModuleClass, DDDModule::class)) {
                $moduleClasses[] = $ownModuleClass;
            }
        }
        return array_values(array_unique($moduleClasses));
    }
}
