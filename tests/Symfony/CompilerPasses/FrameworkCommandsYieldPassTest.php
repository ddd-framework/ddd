<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses;

use DDD\Symfony\Commands\Common\Issues\IssuesHealth;
use DDD\Symfony\Commands\Common\Issues\IssuesSync;
use DDD\Symfony\Commands\Common\RuntimeErrors\RuntimeErrorsExtract;
use DDD\Symfony\Commands\Common\RuntimeErrors\RuntimeErrorsProbe;
use DDD\Symfony\CompilerPasses\ConsoleCommandTagsOrderPass;
use DDD\Symfony\CompilerPasses\FrameworkCommandsYieldPass;
use DDD\Symfony\CompilerPasses\ModuleCompilerPass;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ApplicationCommands\InheritingIssuesHealthCommand;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ApplicationCommands\RenamedIssuesHealthCommand;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ApplicationCommands\StaleNameModuleCommand;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\CommandSubclassFixtureModule;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\CommandSubclassModuleSource\ExtendedModuleCommand;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\CommandSubclassModuleSource\RenamedModuleCommand;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\FrameworkSubclassFixtureModule;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\FrameworkSubclassModuleSource\ModuleIssuesSyncCommand;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * A package command yields to an application command of the same name, whichever registered first, and to a command
 * of another package or the application that extends its class — the subclass then carries all its names.
 */
class FrameworkCommandsYieldPassTest extends TestCase
{
    protected function setUp(): void
    {
        $this->forgetDiscoveredModules();
    }

    protected function tearDown(): void
    {
        $this->forgetDiscoveredModules();
    }

    protected function forgetDiscoveredModules(): void
    {
        (new ReflectionProperty(ModuleCompilerPass::class, 'discoveredModules'))->setValue(null, null);
    }

    /**
     * A compiled container as the kernel builds it: the module pass, the console autoconfiguration of the framework
     * bundle, the framework command {@see IssuesHealth}, the given application commands, the yield pass, the tags
     * order pass and the console's command pass.
     *
     * @param string[] $applicationCommandClasses
     */
    protected function buildCompiledContainer(array $applicationCommandClasses): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.project_dir', __DIR__ . '/Fixtures');
        $containerBuilder->setParameter(ModuleCompilerPass::ADDITIONAL_MODULES_PARAMETER, [CommandSubclassFixtureModule::class]);
        $containerBuilder->registerAttributeForAutoconfiguration(
            AsCommand::class,
            static function (ChildDefinition $definition, AsCommand $attribute): void {
                $definition->addTag('console.command', [
                    'command' => $attribute->name,
                    'description' => $attribute->description,
                    'help' => $attribute->help ?? null,
                ]);
            }
        );
        $containerBuilder->registerForAutoconfiguration(Command::class)->addTag('console.command');
        foreach ([IssuesHealth::class, ...$applicationCommandClasses] as $commandClass) {
            $containerBuilder->setDefinition($commandClass, (new Definition($commandClass))->setAutoconfigured(true)->setPublic(true));
        }
        $containerBuilder->addCompilerPass(new ModuleCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, ModuleCompilerPass::PASS_PRIORITY);
        $containerBuilder->addCompilerPass(new FrameworkCommandsYieldPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
        $containerBuilder->addCompilerPass(new ConsoleCommandTagsOrderPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -5);
        $containerBuilder->addCompilerPass(new AddConsoleCommandPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $containerBuilder->compile();
        return $containerBuilder;
    }

    /** A console application on the container's command loader, as bin/console runs it */
    protected function createApplication(ContainerBuilder $containerBuilder): Application
    {
        /** @var CommandLoaderInterface $commandLoader */
        $commandLoader = $containerBuilder->get('console.command_loader');
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCommandLoader($commandLoader);
        return $application;
    }

    /** The command behind a name, built from its class (not the lazy wrapper) */
    protected function getInstantiatedCommand(Application $application, string $commandName): Command
    {
        $command = $application->get($commandName);
        return $command instanceof LazyCommand ? $command->getCommand() : $command;
    }

    public function testApplicationSubclassWithoutANameInheritsAllNamesOfTheFrameworkCommand(): void
    {
        $containerBuilder = $this->buildCompiledContainer([InheritingIssuesHealthCommand::class]);
        $application = $this->createApplication($containerBuilder);

        self::assertFalse($containerBuilder->has(IssuesHealth::class));
        self::assertTrue($application->has(IssuesHealth::COMMAND_NAME));
        self::assertTrue($application->has(IssuesHealth::DEPRECATED_COMMAND_NAME));
        $command = $this->getInstantiatedCommand($application, IssuesHealth::DEPRECATED_COMMAND_NAME);
        self::assertInstanceOf(InheritingIssuesHealthCommand::class, $command);
        self::assertSame(IssuesHealth::COMMAND_NAME, $command->getName());
        self::assertSame([IssuesHealth::DEPRECATED_COMMAND_NAME], $command->getAliases());
        // the description is carried over, so the command stays lazy in `list`
        self::assertInstanceOf(LazyCommand::class, $application->get(IssuesHealth::COMMAND_NAME));
        self::assertNotSame('', $application->get(IssuesHealth::COMMAND_NAME)->getDescription());
    }

    public function testApplicationSubclassWithItsOwnNameKeepsItFirstAndGetsTheInheritedNamesAsAliases(): void
    {
        $containerBuilder = $this->buildCompiledContainer([RenamedIssuesHealthCommand::class]);
        $application = $this->createApplication($containerBuilder);

        self::assertFalse($containerBuilder->has(IssuesHealth::class));
        foreach ([
            RenamedIssuesHealthCommand::COMMAND_NAME,
            RenamedIssuesHealthCommand::ALIAS_NAME,
            IssuesHealth::COMMAND_NAME,
            IssuesHealth::DEPRECATED_COMMAND_NAME,
        ] as $commandName) {
            self::assertTrue($application->has($commandName), $commandName);
            self::assertInstanceOf(RenamedIssuesHealthCommand::class, $this->getInstantiatedCommand($application, $commandName));
        }
        $command = $this->getInstantiatedCommand($application, IssuesHealth::COMMAND_NAME);
        self::assertSame(RenamedIssuesHealthCommand::COMMAND_NAME, $command->getName());
        self::assertSame(
            [RenamedIssuesHealthCommand::ALIAS_NAME, IssuesHealth::COMMAND_NAME, IssuesHealth::DEPRECATED_COMMAND_NAME],
            $command->getAliases()
        );
        self::assertSame(RenamedIssuesHealthCommand::COMMAND_NAME, $application->get(IssuesHealth::COMMAND_NAME)->getName());
    }

    /**
     * The module renamed its command; the host's subclass still declares the former name. The module's new name (the
     * one module code starts by constant) reaches the subclass instead of being lost with the removed module service.
     */
    public function testApplicationSubclassOfAModuleCommandRepeatingAFormerNameGetsTheNewNameToo(): void
    {
        $containerBuilder = $this->buildCompiledContainer([StaleNameModuleCommand::class]);
        $application = $this->createApplication($containerBuilder);

        self::assertFalse($containerBuilder->has(RenamedModuleCommand::class));
        self::assertTrue($application->has(RenamedModuleCommand::COMMAND_NAME));
        self::assertTrue($application->has(RenamedModuleCommand::PREVIOUS_COMMAND_NAME));
        $command = $this->getInstantiatedCommand($application, RenamedModuleCommand::COMMAND_NAME);
        self::assertInstanceOf(StaleNameModuleCommand::class, $command);
        self::assertSame(RenamedModuleCommand::PREVIOUS_COMMAND_NAME, $command->getName());
        self::assertSame([RenamedModuleCommand::COMMAND_NAME], $command->getAliases());
        $loadableCommandNames = array_keys($containerBuilder->getDefinition('console.command_loader')->getArgument(1));
        self::assertNotContains(RenamedModuleCommand::PREVIOUS_COMMAND_NAME . '|' . RenamedModuleCommand::COMMAND_NAME, $loadableCommandNames);
    }

    /** Two commands of one package extending each other both stay, each with its own names */
    public function testCommandsOfOnePackageExtendingEachOtherAreNotAffected(): void
    {
        $containerBuilder = $this->buildCompiledContainer([]);
        $application = $this->createApplication($containerBuilder);

        self::assertTrue($containerBuilder->has(IssuesHealth::class));
        self::assertTrue($containerBuilder->has(RenamedModuleCommand::class));
        self::assertTrue($containerBuilder->has(ExtendedModuleCommand::class));
        self::assertInstanceOf(RenamedModuleCommand::class, $this->getInstantiatedCommand($application, RenamedModuleCommand::COMMAND_NAME));
        self::assertNotInstanceOf(ExtendedModuleCommand::class, $this->getInstantiatedCommand($application, RenamedModuleCommand::COMMAND_NAME));
        self::assertSame([], $this->getInstantiatedCommand($application, ExtendedModuleCommand::COMMAND_NAME)->getAliases());
        self::assertTrue($application->has(IssuesHealth::DEPRECATED_COMMAND_NAME));
    }

    /** A module command extending a framework command replaces it like an application command does */
    public function testModuleSubclassOfAFrameworkCommandReplacesIt(): void
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter(ModuleCompilerPass::MODULES_PARAMETER, [FrameworkSubclassFixtureModule::class]);
        $containerBuilder->setDefinition(IssuesSync::class, (new Definition(IssuesSync::class))
            ->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG));
        $containerBuilder->setDefinition(ModuleIssuesSyncCommand::class, (new Definition(ModuleIssuesSyncCommand::class))
            ->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG));

        (new FrameworkCommandsYieldPass())->process($containerBuilder);

        self::assertFalse($containerBuilder->hasDefinition(IssuesSync::class));
        $moduleCommandTags = $containerBuilder->getDefinition(ModuleIssuesSyncCommand::class)->getTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG);
        self::assertCount(1, $moduleCommandTags);
        self::assertSame(IssuesSync::COMMAND_NAME . '|' . IssuesSync::DEPRECATED_COMMAND_NAME, $moduleCommandTags[0]['command']);
        self::assertNotEmpty($moduleCommandTags[0]['description']);
    }

    public function testFrameworkCommandYieldsToTheApplicationCommand(): void
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setDefinition(RuntimeErrorsExtract::class, (new Definition(RuntimeErrorsExtract::class))
            ->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG, ['command' => RuntimeErrorsExtract::COMMAND_NAME]));
        // tagged without a name: the #[AsCommand] name is read
        $containerBuilder->setDefinition(RuntimeErrorsProbe::class, (new Definition(RuntimeErrorsProbe::class))
            ->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG));
        $containerBuilder->setDefinition('App\\Symfony\\Commands\\RuntimeErrorsExtractCommand', (new Definition(Command::class))
            ->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG, ['command' => RuntimeErrorsExtract::COMMAND_NAME]));

        (new FrameworkCommandsYieldPass())->process($containerBuilder);

        self::assertFalse($containerBuilder->hasDefinition(RuntimeErrorsExtract::class));
        self::assertTrue($containerBuilder->hasDefinition('App\\Symfony\\Commands\\RuntimeErrorsExtractCommand'));
        self::assertTrue($containerBuilder->hasDefinition(RuntimeErrorsProbe::class));
    }

    /**
     * An application command repeating the framework command's name AND its alias (to keep an old name working)
     * shares two names with it: the framework service is removed once, the pass does not ask for it again.
     */
    public function testApplicationCommandSharingNameAndAliasRemovesTheFrameworkCommandOnce(): void
    {
        $sharedCommandNames = RuntimeErrorsExtract::COMMAND_NAME . '|app:legacy:runtime-errors-extract';
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setDefinition(RuntimeErrorsExtract::class, (new Definition(RuntimeErrorsExtract::class))
            ->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG, ['command' => $sharedCommandNames]));
        $containerBuilder->setDefinition('App\\Symfony\\Commands\\RuntimeErrorsExtractCommand', (new Definition(Command::class))
            ->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG, ['command' => $sharedCommandNames]));

        (new FrameworkCommandsYieldPass())->process($containerBuilder);

        self::assertFalse($containerBuilder->hasDefinition(RuntimeErrorsExtract::class));
        self::assertTrue($containerBuilder->hasDefinition('App\\Symfony\\Commands\\RuntimeErrorsExtractCommand'));
    }
}
