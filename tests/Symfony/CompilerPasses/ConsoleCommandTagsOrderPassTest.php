<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses;

use DDD\Symfony\CompilerPasses\ConsoleCommandTagsOrderPass;
use DDD\Symfony\CompilerPasses\ModuleCompilerPass;
use DDD\Symfony\Messenger\WorkerStateResetterInterface;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\AdditionalFixtureModule;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A module command with aliases is registered under its name and each alias once — never under the joined
 * `name|alias` string the console's command pass makes of a second named tag.
 */
class ConsoleCommandTagsOrderPassTest extends TestCase
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

    /** The kernel's module pass plus the console autoconfiguration of the framework bundle and its command pass */
    protected function buildContainerWithModuleCommands(bool $withTagsOrderPass): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.project_dir', __DIR__ . '/Fixtures');
        $containerBuilder->setParameter(ModuleCompilerPass::ADDITIONAL_MODULES_PARAMETER, [AdditionalFixtureModule::class]);
        $containerBuilder->registerForAutoconfiguration(WorkerStateResetterInterface::class)
            ->addTag(WorkerStateResetterInterface::TAG);
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
        $containerBuilder->addCompilerPass(new ModuleCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, ModuleCompilerPass::PASS_PRIORITY);
        if ($withTagsOrderPass) {
            $containerBuilder->addCompilerPass(new ConsoleCommandTagsOrderPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -5);
        }
        $containerBuilder->addCompilerPass(new AddConsoleCommandPass(), PassConfig::TYPE_BEFORE_REMOVING);
        return $containerBuilder;
    }

    /** @return string[] the command names and aliases the console's command loader knows */
    protected function getLoadableCommandNames(ContainerBuilder $containerBuilder): array
    {
        return array_keys($containerBuilder->getDefinition('console.command_loader')->getArgument(1));
    }

    public function testAModuleCommandIsRegisteredUnderItsNameAndAliasOnly(): void
    {
        $containerBuilder = $this->buildContainerWithModuleCommands(true);

        $containerBuilder->compile();

        $loadableCommandNames = $this->getLoadableCommandNames($containerBuilder);
        self::assertContains('app:module-fixture:aliased', $loadableCommandNames);
        self::assertContains('app:module-fixture:old-name', $loadableCommandNames);
        self::assertNotContains('app:module-fixture:aliased|app:module-fixture:old-name', $loadableCommandNames);
        self::assertContains('app:module-fixture:run', $loadableCommandNames);
    }

    /** Documents the failure the pass removes: without it the joined string is one more command name */
    public function testWithoutThePassTheJoinedNameBecomesACommandName(): void
    {
        $containerBuilder = $this->buildContainerWithModuleCommands(false);

        $containerBuilder->compile();

        self::assertContains('app:module-fixture:aliased|app:module-fixture:old-name', $this->getLoadableCommandNames($containerBuilder));
    }

    public function testTheNamedTagMovesFirstAndRepeatedNamesAreDropped(): void
    {
        $orderedTags = (new ConsoleCommandTagsOrderPass())->orderCommandTags([
            [],
            ['command' => 'app:x|app:old-x', 'description' => 'X'],
            ['command' => 'app:x|app:old-x', 'description' => 'X'],
            ['command' => 'app:x|app:old-x', 'description' => 'X', 'help' => null, 'usages' => []],
            ['command' => 'app:third-name'],
        ]);

        self::assertSame(
            [['command' => 'app:x|app:old-x', 'description' => 'X'], [], ['command' => 'app:third-name']],
            $orderedTags
        );
    }

    public function testTagsWithoutANameStayAsTheyAre(): void
    {
        self::assertSame([[]], (new ConsoleCommandTagsOrderPass())->orderCommandTags([[], []]));
    }
}
