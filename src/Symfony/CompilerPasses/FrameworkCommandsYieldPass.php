<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use DDD\Infrastructure\Modules\DDDModule;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

/**
 * A package command yields to the application (or another package) — by class hierarchy and by shared name.
 *
 * **By class hierarchy (package commands).** A package command is a command class of the framework (its `src/`, or a
 * class under {@see self::FRAMEWORK_COMMANDS_NAMESPACE}) or of a registered DDD module (a class file below the
 * module's {@see DDDModule::getSourcePath()}, read from {@see ModuleCompilerPass::MODULES_PARAMETER}). A command
 * service whose class EXTENDS a package command class of ANOTHER package (an application class, or a module class
 * extending a framework command) replaces it: the package command's service is removed, and the subclass's
 * `console.command` tag carries ALL names the package command registered (name and aliases, from its tag's `command`
 * attribute or its #[AsCommand]) after the names the subclass declares itself — the subclass's own primary name first,
 * each name once. A subclass without a name of its own takes over the package command's name, aliases and description.
 * `#[AsCommand]` is not inherited: without this, a host subclass that still repeats a command's old name after the
 * package renamed it registered only the old name, while package code starts the new name by constant. Two commands
 * of the same package extending each other are not affected. Two subclasses of one package command in the
 * application both receive its names; the console then resolves each shared name to the last registered one.
 *
 * **By shared name (framework commands).** When a command name is registered by a class under
 * {@see self::FRAMEWORK_COMMANDS_NAMESPACE} and by another class, the framework's service is removed. Without it the
 * winner would depend on the service registration order. Example: an application with its own
 * `app:runtime-errors:extract` keeps it; one without gets the framework's.
 *
 * Runs after the autoconfiguration has tagged the commands and the {@see ModuleCompilerPass} has registered the
 * modules, before the {@see ConsoleCommandTagsOrderPass} and the console's command loader (BEFORE_REMOVING).
 */
class FrameworkCommandsYieldPass implements CompilerPassInterface
{
    public const string FRAMEWORK_COMMANDS_NAMESPACE = 'DDD\\Symfony\\Commands\\';

    public const string CONSOLE_COMMAND_TAG = 'console.command';

    /** @var string The package key of the framework itself; a module's package key is its module class */
    public const string FRAMEWORK_PACKAGE = 'ddd';

    public function process(ContainerBuilder $container): void
    {
        $this->replacePackageCommandsExtendedByOtherPackages($container);
        $this->removeFrameworkCommandsSharingANameWithOtherCommands($container);
    }

    /**
     * Removes every package command whose class another package's command service extends, and gives that subclass
     * all names (and, without its own, the description and help) of the removed command.
     */
    protected function replacePackageCommandsExtendedByOtherPackages(ContainerBuilder $container): void
    {
        $packageSourceDirectories = $this->getPackageSourceDirectories($container);
        $commandTagsByServiceId = $container->findTaggedServiceIds(self::CONSOLE_COMMAND_TAG);
        /** @var array<string, string[]> $serviceIdsByClass method-local: command class → service ids */
        $serviceIdsByClass = [];
        foreach (array_keys($commandTagsByServiceId) as $serviceId) {
            $serviceClass = $this->getServiceClass($container, (string)$serviceId);
            if ($serviceClass !== null) {
                $serviceIdsByClass[$serviceClass][] = (string)$serviceId;
            }
        }
        /** @var array<string, true> $replacedServiceIds method-local: service id → true */
        $replacedServiceIds = [];
        /** @var array<string, string[]> $replacedServiceIdsBySubclassServiceId method-local: subclass service id → replaced ids, nearest ancestor first */
        $replacedServiceIdsBySubclassServiceId = [];
        foreach (array_keys($commandTagsByServiceId) as $serviceId) {
            $serviceId = (string)$serviceId;
            $serviceClass = $this->getServiceClass($container, $serviceId);
            if ($serviceClass === null || !class_exists($serviceClass)) {
                continue;
            }
            $subclassPackage = $this->getPackageOfClass($serviceClass, $packageSourceDirectories);
            foreach ($this->getAncestorClasses($serviceClass) as $ancestorClass) {
                if (!isset($serviceIdsByClass[$ancestorClass])) {
                    continue;
                }
                $ancestorPackage = $this->getPackageOfClass($ancestorClass, $packageSourceDirectories);
                if ($ancestorPackage === null || $ancestorPackage === $subclassPackage) {
                    continue;
                }
                foreach ($serviceIdsByClass[$ancestorClass] as $ancestorServiceId) {
                    $replacedServiceIds[$ancestorServiceId] = true;
                    $replacedServiceIdsBySubclassServiceId[$serviceId][] = $ancestorServiceId;
                }
            }
        }
        foreach ($replacedServiceIdsBySubclassServiceId as $subclassServiceId => $ancestorServiceIds) {
            if (isset($replacedServiceIds[$subclassServiceId])) {
                // replaced itself further down the hierarchy: its own subclass collects the ancestors' names directly
                continue;
            }
            $this->mergeAncestorCommandNamesIntoTag($container, (string)$subclassServiceId, $ancestorServiceIds, $commandTagsByServiceId);
        }
        foreach (array_keys($replacedServiceIds) as $replacedServiceId) {
            $container->removeDefinition((string)$replacedServiceId);
        }
    }

    /**
     * Rewrites the subclass's `console.command` tags into ONE named tag: its own names first, then each ancestor's,
     * each name once; description and help of the first ancestor that has them when the subclass has none.
     *
     * @param string[] $ancestorServiceIds Services of the replaced package commands, nearest ancestor first
     * @param array<string, array<int, array<string, mixed>>> $commandTagsByServiceId The tags before any change
     */
    protected function mergeAncestorCommandNamesIntoTag(
        ContainerBuilder $container,
        string $subclassServiceId,
        array $ancestorServiceIds,
        array $commandTagsByServiceId
    ): void {
        $subclassTags = $commandTagsByServiceId[$subclassServiceId] ?? [];
        $mergedTag = $this->getFirstNamedTag($subclassTags) ?? [];
        $subclassCommandNames = $this->getCommandNames($container, $subclassServiceId, $subclassTags);
        $isHidden = $subclassCommandNames !== []
            ? $this->isHiddenCommand($container, $subclassServiceId, $subclassTags)
            : null;
        $commandNames = $subclassCommandNames;
        $subclassAsCommand = $this->getOwnAsCommandAttribute($this->getServiceClass($container, $subclassServiceId));
        $mergedTag['description'] ??= $subclassAsCommand?->description;
        $mergedTag['help'] ??= $subclassAsCommand?->help;
        foreach ($ancestorServiceIds as $ancestorServiceId) {
            $ancestorTags = $commandTagsByServiceId[$ancestorServiceId] ?? [];
            $ancestorCommandNames = $this->getCommandNames($container, $ancestorServiceId, $ancestorTags);
            $isHidden ??= $ancestorCommandNames !== []
                ? $this->isHiddenCommand($container, $ancestorServiceId, $ancestorTags)
                : null;
            $commandNames = [...$commandNames, ...$ancestorCommandNames];
            $ancestorNamedTag = $this->getFirstNamedTag($ancestorTags) ?? [];
            $ancestorAsCommand = $this->getOwnAsCommandAttribute($this->getServiceClass($container, $ancestorServiceId));
            $mergedTag['description'] ??= $ancestorNamedTag['description'] ?? $ancestorAsCommand?->description;
            $mergedTag['help'] ??= $ancestorNamedTag['help'] ?? $ancestorAsCommand?->help;
        }
        $commandNames = array_values(array_unique($commandNames));
        if ($commandNames === []) {
            return;
        }
        $mergedTag['command'] = ($isHidden === true ? '|' : '') . implode('|', $commandNames);
        $mergedTag = array_filter($mergedTag, static fn(mixed $tagValue): bool => $tagValue !== null);
        $subclassDefinition = $container->getDefinition($subclassServiceId);
        $subclassDefinition->clearTag(self::CONSOLE_COMMAND_TAG);
        $subclassDefinition->addTag(self::CONSOLE_COMMAND_TAG, $mergedTag);
    }

    /**
     * The framework's rule by name: a command name registered by a framework command and by another command
     * keeps the other one.
     */
    protected function removeFrameworkCommandsSharingANameWithOtherCommands(ContainerBuilder $container): void
    {
        /** @var array<string, string[]> $serviceIdsByCommandName method-local: command name → service ids */
        $serviceIdsByCommandName = [];
        foreach ($container->findTaggedServiceIds(self::CONSOLE_COMMAND_TAG) as $serviceId => $tags) {
            foreach ($this->getCommandNames($container, $serviceId, $tags) as $commandName) {
                $serviceIdsByCommandName[$commandName][] = $serviceId;
            }
        }
        // collected over all names first: an application command sharing several names (name and aliases) with a
        // framework command names the same framework service under each of them, and it is removed once
        /** @var array<string, true> $frameworkServiceIdsToRemove method-local: service id → true */
        $frameworkServiceIdsToRemove = [];
        foreach ($serviceIdsByCommandName as $serviceIds) {
            $serviceIds = array_values(array_unique($serviceIds));
            if (count($serviceIds) < 2) {
                continue;
            }
            $frameworkServiceIds = array_filter(
                $serviceIds,
                fn(string $serviceId): bool => str_starts_with($this->getServiceClass($container, $serviceId) ?? '', self::FRAMEWORK_COMMANDS_NAMESPACE)
            );
            if ($frameworkServiceIds === [] || count($frameworkServiceIds) === count($serviceIds)) {
                continue;
            }
            foreach ($frameworkServiceIds as $frameworkServiceId) {
                $frameworkServiceIdsToRemove[$frameworkServiceId] = true;
            }
        }
        foreach (array_keys($frameworkServiceIdsToRemove) as $frameworkServiceId) {
            $container->removeDefinition((string)$frameworkServiceId);
        }
    }

    /**
     * The names a command service registers: the tags' `command` attributes, else its #[AsCommand] name (with aliases).
     *
     * @param array<int, array<string, mixed>> $tags
     * @return string[]
     */
    protected function getCommandNames(ContainerBuilder $container, string $serviceId, array $tags): array
    {
        $commandNames = [];
        foreach ($this->getRegisteredCommandStrings($container, $serviceId, $tags) as $commandString) {
            $commandNames = [...$commandNames, ...explode('|', $commandString)];
        }
        return array_values(array_filter($commandNames, static fn(string $commandName): bool => $commandName !== ''));
    }

    /**
     * Whether the command is hidden: its first registered name string starts with `|` (an empty primary name).
     *
     * @param array<int, array<string, mixed>> $tags
     */
    protected function isHiddenCommand(ContainerBuilder $container, string $serviceId, array $tags): bool
    {
        $commandStrings = $this->getRegisteredCommandStrings($container, $serviceId, $tags);
        return $commandStrings !== [] && str_starts_with($commandStrings[0], '|');
    }

    /**
     * The raw `name|alias` strings of a command service: the tags' `command` attributes, else its own #[AsCommand] name.
     *
     * @param array<int, array<string, mixed>> $tags
     * @return string[]
     */
    protected function getRegisteredCommandStrings(ContainerBuilder $container, string $serviceId, array $tags): array
    {
        $commandStrings = [];
        foreach ($tags as $tag) {
            if (isset($tag['command']) && is_string($tag['command'])) {
                $commandStrings[] = $tag['command'];
            }
        }
        if ($commandStrings === []) {
            $asCommandAttribute = $this->getOwnAsCommandAttribute($this->getServiceClass($container, $serviceId));
            if ($asCommandAttribute !== null) {
                $commandStrings[] = $asCommandAttribute->name;
            }
        }
        return $commandStrings;
    }

    /** The #[AsCommand] declared on the class itself (the attribute is not inherited) */
    protected function getOwnAsCommandAttribute(?string $className): ?AsCommand
    {
        if ($className === null || !class_exists($className)) {
            return null;
        }
        $asCommandAttributes = (new ReflectionClass($className))->getAttributes(AsCommand::class);
        return $asCommandAttributes === [] ? null : $asCommandAttributes[0]->newInstance();
    }

    /**
     * @param array<int, array<string, mixed>> $tags
     * @return array<string, mixed>|null The first tag with a `command` attribute
     */
    protected function getFirstNamedTag(array $tags): ?array
    {
        foreach ($tags as $tag) {
            if (isset($tag['command']) && is_string($tag['command'])) {
                return $tag;
            }
        }
        return null;
    }

    /**
     * @return string[] The parent classes of a class, nearest first
     */
    protected function getAncestorClasses(string $className): array
    {
        $ancestorClasses = [];
        $parentClass = get_parent_class($className);
        while ($parentClass !== false) {
            $ancestorClasses[] = $parentClass;
            $parentClass = get_parent_class($parentClass);
        }
        return $ancestorClasses;
    }

    /**
     * The source directories of the packages whose commands yield by class hierarchy: each registered module's source
     * path (keyed by its module class), then the framework's `src/`.
     *
     * @return array<string, string> package key → real directory path with a trailing separator
     */
    protected function getPackageSourceDirectories(ContainerBuilder $container): array
    {
        $packageSourceDirectories = [];
        $moduleClasses = $container->hasParameter(ModuleCompilerPass::MODULES_PARAMETER)
            ? (array)$container->getParameter(ModuleCompilerPass::MODULES_PARAMETER)
            : [];
        foreach ($moduleClasses as $moduleClass) {
            if (!is_string($moduleClass) || !is_subclass_of($moduleClass, DDDModule::class)) {
                continue;
            }
            try {
                $moduleSourceDirectory = realpath($moduleClass::getSourcePath());
            } catch (Throwable) {
                continue;
            }
            if ($moduleSourceDirectory !== false) {
                $packageSourceDirectories[$moduleClass] = rtrim($moduleSourceDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        }
        $frameworkSourceDirectory = realpath(dirname(__DIR__, 2));
        if ($frameworkSourceDirectory !== false) {
            $packageSourceDirectories[self::FRAMEWORK_PACKAGE] = rtrim($frameworkSourceDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        return $packageSourceDirectories;
    }

    /**
     * The package a class belongs to: the module whose source path holds its file, the framework (its `src/` or
     * {@see self::FRAMEWORK_COMMANDS_NAMESPACE}), or null for the application.
     *
     * @param array<string, string> $packageSourceDirectories From {@see getPackageSourceDirectories()}
     */
    protected function getPackageOfClass(string $className, array $packageSourceDirectories): ?string
    {
        try {
            $classFile = (new ReflectionClass($className))->getFileName();
        } catch (Throwable) {
            $classFile = false;
        }
        $realClassFile = $classFile === false ? false : realpath($classFile);
        if ($realClassFile !== false) {
            foreach ($packageSourceDirectories as $packageKey => $packageSourceDirectory) {
                if (str_starts_with($realClassFile, $packageSourceDirectory)) {
                    return (string)$packageKey;
                }
            }
        }
        return str_starts_with($className, self::FRAMEWORK_COMMANDS_NAMESPACE) ? self::FRAMEWORK_PACKAGE : null;
    }

    protected function getServiceClass(ContainerBuilder $container, string $serviceId): ?string
    {
        $serviceClass = $container->getDefinition($serviceId)->getClass() ?? $serviceId;
        $resolvedClass = $container->getParameterBag()->resolveValue($serviceClass);
        return is_string($resolvedClass) ? ltrim($resolvedClass, '\\') : null;
    }
}
