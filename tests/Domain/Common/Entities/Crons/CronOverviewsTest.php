<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Crons;

use DDD\Domain\Common\Entities\Crons\CronCommandLine;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Entities\Crons\CronExecutionOverview;
use DDD\Domain\Common\Entities\Crons\CronExecutionOverviews;
use DDD\Domain\Common\Entities\Crons\CronOverview;
use DDD\Domain\Common\Entities\Crons\CronOverviews;
use DDD\Domain\Common\Entities\Crons\CronRunnerSummary;
use DDD\Domain\Common\Entities\Crons\CronRunStatistics;
use DDD\Domain\Common\Entities\Crons\CronSchedulePreview;
use DDD\Domain\Common\Entities\Crons\CronsTimeline;
use DDD\Infrastructure\Base\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

/**
 * What an admin screen reads about Crons, computed without a database: status verdicts, run statistics, the runs
 * timeline, the schedule preview and the command line split.
 */
class CronOverviewsTest extends TestCase
{
    protected int $executionIdSequence = 0;

    protected function createExecution(int $cronId, int $startedAtTimestamp, ?int $durationSeconds, ?string $executionState): CronExecutionOverview
    {
        $cronExecutionOverview = new CronExecutionOverview();
        $cronExecutionOverview->id = ++$this->executionIdSequence;
        $cronExecutionOverview->cronId = $cronId;
        $cronExecutionOverview->startedAt = DateTime::fromTimestamp($startedAtTimestamp);
        if ($durationSeconds !== null) {
            $cronExecutionOverview->state = CronExecution::STATE_ENDED;
            $cronExecutionOverview->endedAt = DateTime::fromTimestamp($startedAtTimestamp + $durationSeconds);
            $cronExecutionOverview->durationMs = CronExecutionOverview::calculateDurationMs($cronExecutionOverview->startedAt, $cronExecutionOverview->endedAt);
            $cronExecutionOverview->executionState = $executionState;
        }
        return $cronExecutionOverview;
    }

    protected function createCronOverview(int $cronId, string $name): CronOverview
    {
        $cronOverview = new CronOverview();
        $cronOverview->id = $cronId;
        $cronOverview->name = $name;
        return $cronOverview;
    }

    public function testTheStatusTakesTheFirstMatchInItsOrder(): void
    {
        $now = DateTime::fromTimestamp(1_800_000_000);
        $cronOverview = $this->createCronOverview(1, 'probe');
        $failedExecution = $this->createExecution(1, 1_799_999_000, 5, CronExecution::EXECUTION_STATE_FAILED);
        $cronOverview->recentExecutions->add($failedExecution);

        $this->assertSame(CronOverview::STATUS_FAILING, $cronOverview->resolveStatus(false, $now));
        $cronOverview->nextExecutionScheduledAt = DateTime::fromTimestamp($now->getTimestamp() - CronOverview::OVERDUE_AFTER_SECONDS - 1);
        $this->assertSame(CronOverview::STATUS_OVERDUE, $cronOverview->resolveStatus(false, $now));
        $this->assertSame(CronOverview::STATUS_RUNNING, $cronOverview->resolveStatus(true, $now));
        $cronOverview->isActive = false;
        $this->assertSame(CronOverview::STATUS_PAUSED, $cronOverview->resolveStatus(true, $now));
        $cronOverview->commandExists = false;
        $this->assertSame(CronOverview::STATUS_MISSING_COMMAND, $cronOverview->resolveStatus(true, $now));
    }

    public function testARunningRunIsSkippedWhenJudgingTheLastResult(): void
    {
        $cronOverview = $this->createCronOverview(1, 'probe');
        $runningExecution = $this->createExecution(1, 1_799_999_900, null, null);
        $successfulExecution = $this->createExecution(1, 1_799_999_000, 5, CronExecution::EXECUTION_STATE_SUCCESSFUL);
        $cronOverview->recentExecutions->add($runningExecution, $successfulExecution);

        $this->assertFalse($cronOverview->hasLastEndedRunFailed());
        $this->assertSame(CronOverview::STATUS_OK, $cronOverview->resolveStatus(false, DateTime::fromTimestamp(1_800_000_000)));
    }

    public function testStatisticsCountOnlyEndedRuns(): void
    {
        $cronExecutionOverviews = new CronExecutionOverviews();
        $successfulExecution = $this->createExecution(1, 1_000, 2, CronExecution::EXECUTION_STATE_SUCCESSFUL);
        $failedExecution = $this->createExecution(1, 2_000, 6, CronExecution::EXECUTION_STATE_FAILED);
        $runningExecution = $this->createExecution(1, 3_000, null, null);
        $cronExecutionOverviews->add($successfulExecution, $failedExecution, $runningExecution);

        $cronRunStatistics = CronRunStatistics::fromExecutions($cronExecutionOverviews);

        $this->assertSame(2, $cronRunStatistics->runCount);
        $this->assertSame(1, $cronRunStatistics->failureCount);
        $this->assertSame(0.5, $cronRunStatistics->successRate);
        $this->assertSame(4000, $cronRunStatistics->averageDurationMs);
        $this->assertSame(6000, $cronRunStatistics->maxDurationMs);
        $this->assertNull(CronRunStatistics::fromExecutions(new CronExecutionOverviews())->successRate);
    }

    public function testTheRunnerSummaryCountsFailuresByTheRunNotByTheStatus(): void
    {
        $cronOverview = $this->createCronOverview(1, 'probe');
        $failedExecution = $this->createExecution(1, 1_000, 2, CronExecution::EXECUTION_STATE_FAILED);
        $cronOverview->recentExecutions->add($failedExecution);
        $cronOverview->status = CronOverview::STATUS_OVERDUE;
        $cronOverview->lastExecutionStartedAt = DateTime::fromTimestamp(1_000);
        $cronOverview->stats24h = CronRunStatistics::fromExecutions($cronOverview->recentExecutions);

        $cronRunnerSummary = new CronRunnerSummary();
        $cronRunnerSummary->addCronOverview($cronOverview);

        $this->assertSame(1, $cronRunnerSummary->failingCount);
        $this->assertSame(1, $cronRunnerSummary->overdueCount);
        $this->assertSame(1, $cronRunnerSummary->failureCount24h);
        $this->assertSame(1_000, $cronRunnerSummary->lastRunStartedAt->getTimestamp());
    }

    public function testTheTimelineBucketsRunsPerCronAndGivesEveryCronALane(): void
    {
        $cronOverviews = new CronOverviews();
        $busyCron = $this->createCronOverview(1, 'busy');
        $idleCron = $this->createCronOverview(2, 'idle');
        $cronOverviews->add($busyCron, $idleCron);
        $bucketStart = 1_800_000_000 - (1_800_000_000 % 900);
        $cronExecutionOverviews = new CronExecutionOverviews();
        $laterExecution = $this->createExecution(1, $bucketStart + 900 + 10, 3, CronExecution::EXECUTION_STATE_SUCCESSFUL);
        $firstExecution = $this->createExecution(1, $bucketStart + 10, 4, CronExecution::EXECUTION_STATE_SUCCESSFUL);
        $failedExecution = $this->createExecution(1, $bucketStart + 300, 9, CronExecution::EXECUTION_STATE_FAILED);
        $runningExecution = $this->createExecution(1, $bucketStart + 600, null, null);
        $cronExecutionOverviews->add($laterExecution, $firstExecution, $failedExecution, $runningExecution);

        $cronsTimeline = CronsTimeline::fromExecutions(
            DateTime::fromTimestamp($bucketStart - 3600),
            DateTime::fromTimestamp($bucketStart + 1800),
            24,
            $cronOverviews,
            $cronExecutionOverviews
        );

        $this->assertSame(15, $cronsTimeline->bucketMinutes);
        $this->assertCount(2, $cronsTimeline->lanes->getElements());
        $this->assertCount(0, $cronsTimeline->lanes->getByCronId(2)->buckets->getElements(), 'an idle Cron keeps its lane');
        $busyBuckets = $cronsTimeline->lanes->getByCronId(1)->buckets->getElements();
        $this->assertCount(2, $busyBuckets);
        $this->assertSame($bucketStart, $busyBuckets[0]->startsAt->getTimestamp(), 'oldest first');
        $this->assertSame(3, $busyBuckets[0]->runCount);
        $this->assertSame(1, $busyBuckets[0]->failureCount);
        $this->assertSame(9000, $busyBuckets[0]->maxDurationMs, 'the running run has no duration yet');
        $this->assertSame(60, CronsTimeline::getBucketMinutesForHours(25));
    }

    public function testTheSchedulePreviewAnswersInsteadOfThrowing(): void
    {
        $invalidSchedulePreview = CronSchedulePreview::fromSchedule('every five minutes');
        $this->assertFalse($invalidSchedulePreview->isValid);
        $this->assertSame(CronSchedulePreview::INVALID_SCHEDULE_MESSAGE, $invalidSchedulePreview->error);
        $this->assertCount(0, $invalidSchedulePreview->nextRuns->getElements());

        $validSchedulePreview = CronSchedulePreview::fromSchedule(' 0 3 * * * ');
        $this->assertTrue($validSchedulePreview->isValid);
        $nextRuns = $validSchedulePreview->nextRuns->getElements();
        $this->assertCount(CronSchedulePreview::NEXT_RUNS_COUNT, $nextRuns);
        $this->assertSame('03:00', $nextRuns[0]->scheduledAt->format('H:i'));
        $this->assertSame(86400, $nextRuns[1]->scheduledAt->getTimestamp() - $nextRuns[0]->scheduledAt->getTimestamp());
    }

    public function testTheOutputPreviewIsTheFirstNonEmptyLine(): void
    {
        $this->assertSame('Closed 4 rows', CronExecutionOverview::extractOutputPreview("\n  \nClosed 4 rows\nDone"));
        $this->assertNull(CronExecutionOverview::extractOutputPreview(" \n "));
        $this->assertSame(200, mb_strlen(CronExecutionOverview::extractOutputPreview(str_repeat('x', 500))));
    }

    public function testTheCommandLineSplitsLikeTheRunner(): void
    {
        $cronCommandLine = CronCommandLine::fromCommand(' app:fixture:close-stale --batch=100 --dryRun ');
        $this->assertSame('app:fixture:close-stale', $cronCommandLine->commandName);
        $this->assertSame('--batch=100 --dryRun', $cronCommandLine->arguments);
        $this->assertNull(CronCommandLine::fromCommand('app:fixture:close-stale')->arguments);
        $this->assertSame('app:fixture:close-stale', CronCommandLine::fromCommand('app:fixture:close-stale')->getCommand());
    }
}
