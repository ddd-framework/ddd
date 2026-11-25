<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Commands\Common\Crons;

use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Domain\Common\Entities\Crons\CommandRegistrationProbeCron;
use DDD\Tests\Symfony\Commands\Common\Crons\Fixtures\FixtureCronsList;
use DDD\Tests\Symfony\Commands\Common\Crons\Fixtures\FixtureCronsService;
use DDD\Tests\Symfony\Commands\Common\Crons\Fixtures\FixtureCronsUpsert;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A Cron whose command a release removed: `app:crons:upsert --active=0` switches it off, switching it on or changing
 * the command to an unknown one is refused, and `app:crons:list` marks it. Every upsert runs with --dryRun.
 */
class CronsUnregisteredCommandTest extends TestCase
{
    protected FixtureCronsService $cronsService;

    protected function setUp(): void
    {
        $this->cronsService = new FixtureCronsService();
        $cronsClassName = DDDService::instance()->getContainerServiceClassNameForClass(Crons::class);
        StaticRegistry::$entityServices[$cronsClassName] = $this->cronsService;
    }

    protected function tearDown(): void
    {
        $cronsClassName = DDDService::instance()->getContainerServiceClassNameForClass(Crons::class);
        unset(StaticRegistry::$entityServices[$cronsClassName]);
    }

    protected function addCron(string $name, string $command, bool $isActive, bool $commandRegistered): CommandRegistrationProbeCron
    {
        $cron = CommandRegistrationProbeCron::createStored($command, $isActive, $commandRegistered);
        $cron->id = count($this->cronsService->crons->getElements()) + 1;
        $cron->name = $name;
        $this->cronsService->crons->add($cron);
        return $cron;
    }

    /** @param array<string, mixed> $options */
    protected function runUpsert(array $options): CommandTester
    {
        $commandTester = new CommandTester(new FixtureCronsUpsert());
        $commandTester->execute($options + ['--dryRun' => true]);
        return $commandTester;
    }

    public function testUpsertActiveZeroSwitchesOffACronWhoseCommandWasRemoved(): void
    {
        $cron = $this->addCron('removed-command', 'app:removed:command', true, false);

        $commandTester = $this->runUpsert(['--name' => 'removed-command', '--active' => '0']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $this->assertFalse($cron->isActive);
        $this->assertStringContainsString('DRY RUN — would UPDATE', $commandTester->getDisplay());
    }

    public function testUpsertActiveOneOfACronWhoseCommandWasRemovedIsRefused(): void
    {
        $this->addCron('removed-command', 'app:removed:command', false, false);

        $commandTester = $this->runUpsert(['--name' => 'removed-command', '--active' => '1']);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('is invalid', $commandTester->getDisplay());
        $this->assertStringContainsString('not registered', $commandTester->getDisplay());
    }

    public function testUpsertChangingTheCommandToAnUnknownOneIsRefusedAlsoWhenInactive(): void
    {
        $this->addCron('removed-command', 'app:removed:command', false, false);

        $commandTester = $this->runUpsert(['--name' => 'removed-command', '--active' => '0', '--command' => 'app:another:unknown']);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('not registered', $commandTester->getDisplay());
    }

    public function testListMarksACronWhoseCommandIsNotRegistered(): void
    {
        $this->addCron('kept-command', 'app:fixture:kept', true, true);
        $this->addCron('removed-command', 'app:removed:command --batch=10', true, false);
        $consoleApplication = new Application();
        $consoleApplication->setAutoExit(false);
        $consoleApplication->add(new FixtureCronsList('app:crons:list'));
        $consoleApplication->add(new Command('app:fixture:kept'));

        $commandTester = new CommandTester($consoleApplication->find('app:crons:list'));
        $exitCode = $commandTester->execute([]);

        $display = $commandTester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Command registered', $display);
        $this->assertMatchesRegularExpression('/kept-command.*\| Yes +\| Yes /', $display);
        $this->assertMatchesRegularExpression('/removed-command.*\| Yes +\| NO /', $display);
        $this->assertStringContainsString('1 Cron(s) with a command this console does not register', $display);
        $this->assertStringContainsString('removed-command (active', $display);
        $this->assertStringNotContainsString('kept-command (', $display);
    }
}
