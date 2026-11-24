<?php

declare(strict_types=1);

namespace DDD\Testing\Modules;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The owned-namespace check: several packages may share a domain (`DDD\Domain\AI`, `DDD\Domain\Common`), but
 * - no class name is shipped by two installed packages (Composer silently loads the first one),
 * - no config file below `config/app/` is shipped by two packages (the one searched first shadows the other),
 * - a package that declares owned namespaces ({@see \DDD\Infrastructure\Modules\DDDModule::getOwnedNamespaces()}) has
 *   no class outside them.
 * Classes are read from the source files (namespace and the declared class, interface, trait or enum), so the check
 * loads no class and needs no kernel. The application is no package here: its same-path classes and config files
 * override the packages' on purpose.
 */
class ModuleNamespaceChecker
{
    /** @var string Declared class-like names: one per file is the convention, every declaration is read */
    public const string CLASS_DECLARATION_PATTERN = '/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m';

    /** @var string The file's namespace */
    public const string NAMESPACE_PATTERN = '/^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*;/m';

    /**
     * @param ModuleNamespacePackage[] $moduleNamespacePackages The installed packages (Core and the modules)
     * @param string[] $acceptedSharedConfigFiles Config file paths (relative to `config/app/`) two packages may ship
     *                                            while one of them moves; a list that only shrinks
     */
    public function checkPackages(array $moduleNamespacePackages, array $acceptedSharedConfigFiles = []): ModuleNamespaceViolations
    {
        $moduleNamespaceViolations = new ModuleNamespaceViolations();
        $packageNamesByClassName = [];
        $packageNamesByConfigFile = [];
        foreach ($moduleNamespacePackages as $moduleNamespacePackage) {
            foreach ($this->findDeclaredClassNames($moduleNamespacePackage->sourcePath) as $className) {
                $packageNamesByClassName[$className][] = $moduleNamespacePackage->packageName;
                if ($moduleNamespacePackage->ownedNamespaces !== [] && !$this->liesInNamespaces($className, $moduleNamespacePackage->ownedNamespaces)) {
                    $moduleNamespaceViolations->add(new ModuleNamespaceViolation(
                        ModuleNamespaceViolation::KIND_CLASS_OUTSIDE_OWNED_NAMESPACES,
                        $className,
                        [$moduleNamespacePackage->packageName]
                    ));
                }
            }
            foreach ($this->findConfigFiles($moduleNamespacePackage->configPath) as $configFile) {
                $packageNamesByConfigFile[$configFile][] = $moduleNamespacePackage->packageName;
            }
        }
        foreach ($packageNamesByClassName as $className => $packageNames) {
            $packageNames = array_values(array_unique($packageNames));
            if (count($packageNames) > 1) {
                $moduleNamespaceViolations->add(new ModuleNamespaceViolation(ModuleNamespaceViolation::KIND_DUPLICATE_CLASS, (string)$className, $packageNames));
            }
        }
        foreach ($packageNamesByConfigFile as $configFile => $packageNames) {
            $packageNames = array_values(array_unique($packageNames));
            if (count($packageNames) > 1 && !in_array((string)$configFile, $acceptedSharedConfigFiles, true)) {
                $moduleNamespaceViolations->add(new ModuleNamespaceViolation(ModuleNamespaceViolation::KIND_DUPLICATE_CONFIG_FILE, (string)$configFile, $packageNames));
            }
        }
        return $moduleNamespaceViolations;
    }

    /**
     * @param string $sourcePath
     * @return string[] Fully qualified names of the classes, interfaces, traits and enums declared below the path
     */
    public function findDeclaredClassNames(string $sourcePath): array
    {
        $classNames = [];
        foreach ($this->findFiles($sourcePath, 'php') as $file) {
            $content = (string)file_get_contents($file->getPathname());
            if (preg_match(static::NAMESPACE_PATTERN, $content, $namespaceMatch) !== 1) {
                continue;
            }
            if (preg_match_all(static::CLASS_DECLARATION_PATTERN, $content, $classMatches) < 1) {
                continue;
            }
            foreach ($classMatches[1] as $shortClassName) {
                $classNames[] = $namespaceMatch[1] . '\\' . $shortClassName;
            }
        }
        return array_values(array_unique($classNames));
    }

    /**
     * @param string|null $configPath
     * @return string[] Paths of the config files relative to the config folder (`AI/models.php`)
     */
    public function findConfigFiles(?string $configPath): array
    {
        if ($configPath === null) {
            return [];
        }
        $configFiles = [];
        $normalizedConfigPath = rtrim((string)realpath($configPath), '/');
        foreach ($this->findFiles($configPath, null) as $file) {
            $configFiles[] = ltrim(substr((string)realpath($file->getPathname()), strlen($normalizedConfigPath)), '/');
        }
        sort($configFiles);
        return $configFiles;
    }

    /**
     * @param string[] $namespaces
     */
    protected function liesInNamespaces(string $className, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if (str_starts_with($className, rtrim($namespace, '\\') . '\\')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return SplFileInfo[]
     */
    protected function findFiles(string $path, ?string $extension): array
    {
        if (!is_dir($path)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (str_starts_with($file->getFilename(), '.')) {
                continue;
            }
            if ($extension !== null && $file->getExtension() !== $extension) {
                continue;
            }
            $files[] = $file;
        }
        return $files;
    }
}
