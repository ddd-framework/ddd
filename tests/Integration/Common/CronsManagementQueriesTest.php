<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Common;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatements;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Entities\Crons\CronExecutionsPage;
use DDD\Domain\Common\Entities\Crons\CronOverview;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Domain\Common\Services\Fixtures\Crons\FixtureSchedulableCommand;
use DDD\Tests\Integration\Common\Fixtures\ConsoleGivenCronsManagementService;
use DDD\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Application;

/**
 * The scalar reads and the writes of the Cron administration (the admin Crons endpoints) on both engines: overview
 * with status and stats, runs page and detail, timeline, run-now as a slot write, delete with its runs.
 */
class CronsManagementQueriesTest extends IntegrationTestCase
{
    public const array CRON_ENTITY_CLASSES = [Cron::class, CronExecution::class];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        $connection = static::connection();
        $databaseModels = array_map(static fn(string $entityClass): DatabaseModel => DatabaseModel::fromEntityClass($entityClass), self::CRON_ENTITY_CLASSES);
        foreach (array_reverse($databaseModels) as $databaseModel) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . static::quoteIdentifier($databaseModel->sqlTableName)
                . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : ''));
        }
        if (static::currentDriver() === 'pdo_pgsql') {
            $statements = [];
            foreach ($databaseModels as $databaseModel) {
                foreach (static::dialect()->ddlRenderer()->renderCreate($databaseModel)->getElements() as $statement) {
                    $statements[] = $statement;
                }
            }
            foreach (DdlStatements::fromStatements(...$statements)->sortedByPhase() as $statement) {
                $connection->executeStatement($statement->sql);
            }
            return;
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($databaseModels as $databaseModel) {
            foreach (static::splitSqlStatements($databaseModel->getSql()) as $statement) {
                $connection->executeStatement($statement);
            }
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            foreach (array_reverse(self::CRON_ENTITY_CLASSES) as $entityClass) {
                static::connection()->executeStatement('DROP TABLE IF EXISTS '
                    . static::quoteIdentifier(DatabaseModel::fromEntityClass($entityClass)->sqlTableName)
                    . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : ''));
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['CronExecutions', 'Crons'] as $tableName) {
            static::connection()->executeStatement('DELETE FROM ' . static::quoteIdentifier($tableName));
        }
        $this->insertCron(1, 'close-stale', 'app:fixture:close-stale --batch=100', 3600);
        $this->insertCron(2, 'removed', 'app:fixture:removed', 3600);
        $this->insertExecution(1, 1, CronExecution::STATE_ENDED, CronExecution::EXECUTION_STATE_SUCCESSFUL, -7200, 4, "\nClosed 4 rows\nDone");
        $this->insertExecution(2, 1, CronExecution::STATE_ENDED, CronExecution::EXECUTION_STATE_FAILED, -3600, 9, 'Error: deadlock');
        $this->insertExecution(3, 2, CronExecution::STATE_RUNNING, null, -60, null, '');
    }

    protected function secondsFromNow(int $offsetSeconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    protected function insertCron(int $cronId, string $name, string $command, int $nextExecutionOffsetSeconds): void
    {
        static::insertRow('Crons', [
            'id' => $cronId,
            'name' => $name,
            'description' => 'A fixture cron for the admin queries',
            'schedule' => '*/5 * * * *',
            'command' => $command,
            'isActive' => static::currentDriver() === 'pdo_pgsql' ? 'true' : 1,
            'nextExecutionScheduledAt' => $this->secondsFromNow($nextExecutionOffsetSeconds),
        ]);
    }

    protected function insertExecution(int $executionId, int $cronId, string $state, ?string $executionState, int $startedOffsetSeconds, ?int $durationSeconds, string $output): void
    {
        static::insertRow('CronExecutions', array_filter([
            'id' => $executionId,
            'cronId' => $cronId,
            'state' => $state,
            'executionState' => $executionState,
            'executionStartedAt' => $this->secondsFromNow($startedOffsetSeconds),
            'executionEndedAt' => $durationSeconds !== null ? $this->secondsFromNow($startedOffsetSeconds + $durationSeconds) : null,
            'output' => $output,
        ], static fn ($value): bool => $value !== null));
    }

    protected function createCronsManagementService(): ConsoleGivenCronsManagementService
    {
        $consoleApplication = new Application();
        $consoleApplication->add(new FixtureSchedulableCommand());
        return new ConsoleGivenCronsManagementService($consoleApplication);
    }

    public function testTheOverviewCarriesStatusRunsAndStats(): void
    {
        $cronsOverview = $this->createCronsManagementService()->getCronsOverview();

        $cronOverviews = $cronsOverview->crons->getElements();
        self::assertSame(['close-stale', 'removed'], array_map(static fn (CronOverview $cronOverview): string => $cronOverview->name, $cronOverviews));
        $closeStale = $cronsOverview->crons->getByCronId(1);
        self::assertSame(CronOverview::STATUS_FAILING, $closeStale->status);
        self::assertSame('--batch=100', $closeStale->arguments);
        self::assertTrue($closeStale->commandSchedulable);
        self::assertSame(2, $closeStale->recentExecutions->count());
        self::assertSame(2, $closeStale->lastExecution->id, 'newest first');
        self::assertSame('Error: deadlock', $closeStale->lastExecution->outputPreview);
        self::assertSame(15, $closeStale->lastExecution->outputLength);
        self::assertSame(9000, $closeStale->lastExecution->durationMs);
        self::assertSame(2, $closeStale->stats24h->runCount);
        self::assertSame(1, $closeStale->stats24h->failureCount);
        self::assertSame(CronOverview::STATUS_MISSING_COMMAND, $cronsOverview->crons->getByCronId(2)->status);
        self::assertSame(1, $cronsOverview->runner->failingCount);
        self::assertSame(1, $cronsOverview->runner->missingCommandCount);
        self::assertSame(2, $cronsOverview->runner->runCount24h);
    }

    public function testTheDetailRunsPageExecutionAndTimeline(): void
    {
        $cronsManagementService = $this->createCronsManagementService();

        $cronDetail = $cronsManagementService->getCronDetail(1);
        self::assertSame(5, $cronDetail->nextRuns->count());
        self::assertSame([1, 2], array_map(static fn ($execution): int => $execution->id, $cronDetail->durationHistory->getElements()), 'oldest first');

        $failedRunsPage = $cronsManagementService->getCronExecutionsPage(null, CronExecutionsPage::RESULT_FAILED, 50, 0);
        self::assertSame(1, $failedRunsPage->totalCount);
        self::assertSame(2, $failedRunsPage->executions->first()->id);
        self::assertSame(1, $cronsManagementService->getCronExecutionsPage(null, CronExecutionsPage::RESULT_RUNNING, 50, 0)->totalCount);
        self::assertSame(2, $cronsManagementService->getCronExecutionsPage(1, CronExecutionsPage::RESULT_ALL, 1, 0)->totalCount);

        $cronExecutionDetail = $cronsManagementService->getCronExecutionDetail(1);
        self::assertSame("\nClosed 4 rows\nDone", $cronExecutionDetail->output);
        self::assertSame('Closed 4 rows', $cronExecutionDetail->execution->outputPreview);
        self::assertSame('close-stale', $cronExecutionDetail->execution->cronName);

        $cronsTimeline = $cronsManagementService->getCronsTimeline(24);
        self::assertSame(2, $cronsTimeline->lanes->count());
        $busyLaneRunCount = 0;
        foreach ($cronsTimeline->lanes->getByCronId(1)->buckets->getElements() as $cronTimelineBucket) {
            $busyLaneRunCount += $cronTimelineBucket->runCount;
        }
        self::assertSame(2, $busyLaneRunCount);
    }

    public function testRunNowWritesTheSlotAndDeleteRemovesTheRuns(): void
    {
        $cronsManagementService = $this->createCronsManagementService();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $cron = $cronsManagementService->findCronOrThrowNotFound(1);
            $cronsManagementService->scheduleCronToRunNow($cron);
            $storedNextExecution = strtotime((string)static::connection()->fetchOne(
                'SELECT ' . static::quoteIdentifier('nextExecutionScheduledAt') . ' FROM ' . static::quoteIdentifier('Crons')
                . ' WHERE ' . static::quoteIdentifier('id') . ' = 1'
            ) . ' UTC');
            self::assertLessThanOrEqual(time() + 1, $storedNextExecution, 'due now: the next tick runs it');

            try {
                $cronsManagementService->deleteCron($cronsManagementService->findCronOrThrowNotFound(2));
                self::fail('a running Cron was deleted');
            } catch (ConflictException) {
            }
            $cronsManagementService->deleteCron($cronsManagementService->findCronOrThrowNotFound(1));
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        self::assertSame(['2'], array_map('strval', static::connection()->fetchFirstColumn('SELECT ' . static::quoteIdentifier('id') . ' FROM ' . static::quoteIdentifier('Crons'))));
        self::assertSame(['3'], array_map('strval', static::connection()->fetchFirstColumn('SELECT ' . static::quoteIdentifier('id') . ' FROM ' . static::quoteIdentifier('CronExecutions'))));
    }
}
