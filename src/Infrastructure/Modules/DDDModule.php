<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Modules;

/**
 * Base class for DDD modules. Each module is a separate Composer package
 * that self-registers its services, config directories, and optional controller routes.
 *
 * Modules are discovered automatically via the "ddd-module" key in their
 * composer.json "extra" section.
 */
abstract class DDDModule
{
    /**
     * Absolute path to this module's src/ directory.
     * Used by the compiler pass to register services.
     */
    abstract public static function getSourcePath(): string;

    /**
     * Absolute path to this module's config/app/ directory, or null if none.
     * Registered via Config::addConfigDirectory() at boot time.
     *
     * Config priority: App > Module > DDD Core (last registered = first searched).
     */
    public static function getConfigPath(): ?string
    {
        return null;
    }

    /**
     * Namespace prefixes whose services should be public in the container.
     * e.g. ['DDD\Domain\Common\Services\PoliticalEntities\']
     */
    public static function getPublicServiceNamespaces(): array
    {
        return [];
    }

    /**
     * Namespace prefixes whose services the container builds lazily (a proxy until first use) — the module's
     * equivalent of `lazy: true` on an application's resource block.
     * e.g. ['DDD\Domain\AI\Services\Conversations\']
     *
     * @return string[]
     */
    public static function getLazyServiceNamespaces(): array
    {
        return [];
    }

    /**
     * Namespace substrings to exclude from auto-wiring.
     * Matches against the fully qualified class name.
     */
    public static function getExcludePatterns(): array
    {
        return ['Attributes', 'Exceptions', 'Traits', 'Validators', 'Interfaces', 'LazyLoad', 'QueryOptions'];
    }

    /**
     * Parts of the module that work only beside another package: namespace prefix => a class of that package. While
     * the class does not exist, the module's classes below the prefix are no services and no entities of the model
     * generator, so their files are never loaded; with the package installed they register like every other class.
     * e.g. ['DDD\Domain\CloudDrive\Agents\' => 'DDD\Domain\AI\Entities\MCPPackages\MCPPackage']
     *
     * @return string[]
     */
    public static function getNamespacesRequiringClasses(): array
    {
        return [];
    }

    /**
     * True when the class belongs to a part of the module whose required class is missing
     * ({@see getNamespacesRequiringClasses()}).
     */
    public static function isClassOfAnInactivePart(string $className): bool
    {
        foreach (static::getNamespacesRequiringClasses() as $namespacePrefix => $requiredClassName) {
            if (str_starts_with($className, $namespacePrefix)
                && !class_exists($requiredClassName) && !interface_exists($requiredClassName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The namespace prefixes this module owns. Several packages may share a domain (`DDD\Domain\AI`,
     * `DDD\Domain\Common`); a package that declares its prefixes ships no class outside them, and no class name or
     * config file is shipped by two packages. Checked by {@see \DDD\Testing\Modules\ModuleNamespacesTestCase}, which
     * every module suite and host suite runs. A new package in a shared domain puts every class below
     * `<Layer>\<Feature>\` and its config below `config/app/<Domain>/<Feature>/`, so it owns a few prefixes.
     * e.g. ['DDD\Domain\AI\Entities\Sandboxes\', 'DDD\Domain\AI\Services\Sandboxes\', 'DDD\Modules\AgentSandbox\']
     * Empty (the default) = not declared: only the duplicate checks apply.
     *
     * @return string[]
     */
    public static function getOwnedNamespaces(): array
    {
        return [];
    }

    /**
     * Controller directories available for routing.
     * Keys are route prefixes, values are absolute directory paths.
     * e.g. ['/api/batch' => __DIR__ . '/Presentation/Api/Batch']
     *
     * These are NOT auto-registered. The consuming app decides which to import
     * in its routes.yaml. This method provides a discoverable registry of
     * available controller paths that modules ship.
     */
    public static function getControllerPaths(): array
    {
        return [];
    }
}
