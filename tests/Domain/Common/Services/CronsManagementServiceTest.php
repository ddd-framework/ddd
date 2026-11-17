<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services;

use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronOverview;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Tests\Domain\Common\Services\Fixtures\Crons\FixtureCronsManagementService;
use DDD\Tests\Domain\Common\Services\Fixtures\Crons\FixtureHiddenSchedulableCommand;
use DDD\Tests\Domain\Common\Services\Fixtures\Crons\FixtureInheritedSchedulableCommand;
use DDD\Tests\Domain\Common\Services\Fixtures\Crons\FixtureSchedulableCommand;
use DDD\Tests\Domain\Common\Services\Fixtures\Crons\FixtureUnmarkedCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;

/**
 * The admin-facing rules of the Cron management that need neither a database nor a kernel: which commands may be
 * scheduled, the arguments rules, the columns a write touches, and the guards of run-now and re-scheduling.
 */
class CronsManagementServiceTest extends TestCase
{
    protected function createCronsManagementService(): FixtureCronsManagementService
    {
        $consoleApplication = new Application();
        $consoleApplication->setAutoExit(false);
        $consoleApplication->addCommands([
            new FixtureSchedulableCommand(),
            new FixtureInheritedSchedulableCommand(),
            new FixtureUnmarkedCommand(),
            new FixtureHiddenSchedulableCommand(),
        ]);
        return new FixtureCronsManagementService($consoleApplication);
    }

    protected function createStoredCron(): Cron
    {
        $cron = new Cron();
        $cron->id = 7;
        $cron->name = 'close-stale';
        $cron->description = 'Closes stale fixture rows every five minutes';
        $cron->schedule = '*/5 * * * *';
        $cron->command = 'app:fixture:close-stale --batch=100';
        $cron->isActive = true;
        $cron->nextExecutionScheduledAt = DateTime::fromTimestamp(time() + 120);
        return $cron;
    }

    public function testOnlyMarkedVisibleCommandsAreOfferedWithTheCronsThatRunThem(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cronOverview = new CronOverview();
        $cronOverview->id = 7;
        $cronOverview->name = 'close-stale';
        $cronOverview->commandName = 'app:fixture:close-stale';
        $cronsManagementService->cronOverviews->add($cronOverview);

        $cronSchedulableCommands = $cronsManagementService->getSchedulableCommands();

        $offeredCommandNames = array_map(
            static fn ($cronSchedulableCommand): string => $cronSchedulableCommand->name,
            $cronSchedulableCommands->getElements()
        );
        $this->assertSame(['app:fixture:close-stale', 'app:fixture:close-stale-inherited'], $offeredCommandNames, 'marked (also inherited), not hidden, sorted');
        $this->assertSame(['close-stale'], $cronSchedulableCommands->getByCommandName('app:fixture:close-stale')->usedByCronNames);
        $this->assertSame('Closes stale fixture rows', $cronSchedulableCommands->getByCommandName('app:fixture:close-stale')->description);
    }

    public function testAnUnmarkedOrUnknownCommandIsRefusedAsAFieldError(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        foreach (['app:fixture:drop-everything' => 'carries no', 'app:fixture:unknown' => "isn't in this build"] as $commandName => $expectedMessagePart) {
            try {
                $cronsManagementService->assertCommandIsSchedulable($commandName);
                $this->fail($commandName . ' was accepted');
            } catch (BadRequestException $badRequestException) {
                $this->assertStringContainsString($expectedMessagePart, $badRequestException->getMessage());
                $this->assertStringStartsWith('commandName: ', $badRequestException->getMessage());
            }
        }
    }

    public function testArgumentsAreCollapsedToOneLineOfSingleSpaces(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $this->assertSame('--batch=100 --dryRun', $cronsManagementService->normalizeArgumentsOrThrow("  --batch=100 \t --dryRun "));
        $this->assertNull($cronsManagementService->normalizeArgumentsOrThrow('   '));
        $this->assertNull($cronsManagementService->normalizeArgumentsOrThrow(null));
    }

    /**
     * @return string[][]
     */
    public static function refusedArgumentsProvider(): array
    {
        return [
            'quotes reach the command literally' => ['--name="x y"'],
            'two lines' => ["--a\n--b"],
            'a loop never ends' => ['--loop'],
            'another environment' => ['--env=prod'],
            'short environment option' => ['-e prod'],
            'profiling' => ['--profile'],
            'too long' => [str_repeat('a', 301)],
        ];
    }

    /**
     * @dataProvider refusedArgumentsProvider
     */
    public function testArgumentsTheRunnerCannotPassOnAreRefused(string $arguments): void
    {
        $this->expectException(BadRequestException::class);
        $this->createCronsManagementService()->normalizeArgumentsOrThrow($arguments);
    }

    public function testAnUpdateWritesOnlyTheChangedColumnsAndJudgesOnlyThem(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cron = $this->createStoredCron();

        $cronsManagementService->updateCron($cron, description: 'Closes stale fixture rows every ten minutes', arguments: '--batch=100');

        $this->assertSame(['description'], $cronsManagementService->persistedPropertyNames, 'unchanged arguments are no write');
        $this->assertSame(['description'], $cronsManagementService->validatedPropertyNames);
    }

    public function testClearingTheArgumentsRewritesTheCommandLine(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cron = $this->createStoredCron();

        $cronsManagementService->updateCron($cron, arguments: '');

        $this->assertSame('app:fixture:close-stale', $cron->command);
        $this->assertSame(['command'], $cronsManagementService->persistedPropertyNames);
    }

    public function testARescheduleReanchorsTheNextSlotByTheNewExpression(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cron = $this->createStoredCron();

        $cronsManagementService->updateCron($cron, schedule: '0 3 * * *');

        $this->assertSame('0 3 * * *', $cron->schedule);
        $this->assertSame('03:00', $cron->nextExecutionScheduledAt->format('H:i'));
        $this->assertSame(['schedule', 'nextExecutionScheduledAt'], $cronsManagementService->persistedPropertyNames);
    }

    public function testARescheduleWhileTheCronRunsIsAConflict(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cronsManagementService->cronIsRunning = true;

        $this->expectException(ConflictException::class);
        $cronsManagementService->updateCron($this->createStoredCron(), schedule: '0 3 * * *');
    }

    public function testARescheduleOfADueCronDuringATickIsAConflict(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cronsManagementService->anyCronIsRunning = true;
        $cron = $this->createStoredCron();
        $cron->nextExecutionScheduledAt = DateTime::fromTimestamp(time() - 30);

        $this->expectException(ConflictException::class);
        $cronsManagementService->updateCron($cron, schedule: '0 3 * * *');
    }

    public function testADescriptionChangeIsNotBlockedByARun(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cronsManagementService->cronIsRunning = true;

        $cronsManagementService->updateCron($this->createStoredCron(), description: 'Closes stale fixture rows, edited while it runs');

        $this->assertSame(['description'], $cronsManagementService->persistedPropertyNames);
    }

    public function testNewArgumentsForAnUnmarkedCommandAreRefused(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cron = $this->createStoredCron();
        $cron->command = 'app:fixture:drop-everything';

        $this->expectException(BadRequestException::class);
        $cronsManagementService->updateCron($cron, arguments: '--force');
    }

    public function testSwitchingOnReanchorsAndSwitchingOffKeepsTheSlot(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cron = $this->createStoredCron();
        $cron->isActive = false;
        $cron->nextExecutionScheduledAt = DateTime::fromTimestamp(time() - 86400);

        $cronsManagementService->activateCron($cron);
        $this->assertTrue($cron->isActive);
        $this->assertGreaterThan(time(), $cron->nextExecutionScheduledAt->getTimestamp(), 'a slot passed while paused never fires at once');
        $this->assertSame(['isActive', 'nextExecutionScheduledAt'], $cronsManagementService->persistedPropertyNames);

        $cronsManagementService->persistedPropertyNames = [];
        $cronsManagementService->activateCron($cron);
        $this->assertSame([], $cronsManagementService->persistedPropertyNames, 'idempotent');

        $cronsManagementService->deactivateCron($cron);
        $this->assertFalse($cron->isActive);
        $this->assertSame(['isActive'], $cronsManagementService->persistedPropertyNames);
    }

    public function testRunNowMakesTheCronDueForTheNextTick(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cron = $this->createStoredCron();

        $cronsManagementService->scheduleCronToRunNow($cron);

        $this->assertLessThanOrEqual(time(), $cron->nextExecutionScheduledAt->getTimestamp());
        $this->assertSame(['nextExecutionScheduledAt'], $cronsManagementService->persistedPropertyNames, 'only the slot: the tick runs it');
    }

    public function testRunNowRefusesARunningOrPausedCronOrAMissingCommand(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $cronsManagementService->cronIsRunning = true;
        try {
            $cronsManagementService->scheduleCronToRunNow($this->createStoredCron());
            $this->fail('a running Cron was queued again');
        } catch (ConflictException) {
        }

        $cronsManagementService->cronIsRunning = false;
        $pausedCron = $this->createStoredCron();
        $pausedCron->isActive = false;
        try {
            $cronsManagementService->scheduleCronToRunNow($pausedCron);
            $this->fail('a paused Cron was queued');
        } catch (ConflictException) {
        }

        $cronWithMissingCommand = $this->createStoredCron();
        $cronWithMissingCommand->command = 'app:fixture:removed';
        $this->expectException(BadRequestException::class);
        $cronsManagementService->scheduleCronToRunNow($cronWithMissingCommand);
    }

    public function testANameMustBeKebabCase(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('name: ');
        $cronsManagementService->updateCron($this->createStoredCron(), name: 'Close Stale');
    }
}
