<?php

declare(strict_types=1);

namespace DDD\Testing\Modules;

use DDD\Infrastructure\Modules\DDDModule;

/**
 * One installed package as the owned-namespace check sees it: its name, its source and config folders, and the
 * namespaces it declares as its own (none = no restriction). Built from a {@see DDDModule} or for Core itself.
 */
class ModuleNamespacePackage
{
    /**
     * @param string $packageName The name in failure messages (the module class, or `mgamadeus/ddd` for Core)
     * @param string $sourcePath Absolute path of the package's `src/`
     * @param string|null $configPath Absolute path of the package's `config/app/`, null for none
     * @param string[] $ownedNamespaces Namespace prefixes every class of the package must lie in; empty = not declared
     */
    public function __construct(
        public readonly string $packageName,
        public readonly string $sourcePath,
        public readonly ?string $configPath,
        public readonly array $ownedNamespaces = [],
    ) {
    }

    /**
     * @param class-string<DDDModule> $moduleClass
     */
    public static function fromModuleClass(string $moduleClass): static
    {
        return new static(
            $moduleClass,
            $moduleClass::getSourcePath(),
            $moduleClass::getConfigPath(),
            $moduleClass::getOwnedNamespaces(),
        );
    }

    /** Core itself (`mgamadeus/ddd`): its `src/` and `config/app/`, no declared namespaces */
    public static function forCore(): static
    {
        $coreRootPath = dirname(__DIR__, 3);
        return new static('mgamadeus/ddd', $coreRootPath . '/src', $coreRootPath . '/config/app');
    }
}
