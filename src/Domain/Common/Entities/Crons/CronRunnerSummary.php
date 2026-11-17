<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Health of the runner across all Crons: the summary tiles and the overdue banner of an admin screen.
 */
class CronRunnerSummary extends ValueObject
{
    /** @var DateTime The server's clock; clients count down against it */
    public DateTime $serverNow;

    /** @var DateTime|null Newest run start across all Crons */
    public ?DateTime $lastRunStartedAt = null;

    /** @var int Active Crons */
    public int $activeCount = 0;

    /** @var int Paused Crons */
    public int $pausedCount = 0;

    /** @var int Crons with a run in progress */
    public int $runningCount = 0;

    /** @var int Crons whose slot is overdue */
    public int $overdueCount = 0;

    /** @var int Crons whose last ended run failed (counted by the run, not by the status: OVERDUE and PAUSED outrank FAILING) */
    public int $failingCount = 0;

    /** @var int Crons whose command is not in this build */
    public int $missingCommandCount = 0;

    /** @var int Ended runs in the last 24 hours */
    public int $runCount24h = 0;

    /** @var int Failed runs in the last 24 hours */
    public int $failureCount24h = 0;

    public function __construct(?DateTime $serverNow = null)
    {
        $this->serverNow = $serverNow ?? new DateTime();
        parent::__construct();
    }

    /**
     * Counts one Cron into the summary.
     */
    public function addCronOverview(CronOverview $cronOverview): void
    {
        $this->activeCount += $cronOverview->isActive ? 1 : 0;
        $this->pausedCount += $cronOverview->isActive ? 0 : 1;
        $this->runningCount += $cronOverview->status === CronOverview::STATUS_RUNNING ? 1 : 0;
        $this->overdueCount += $cronOverview->status === CronOverview::STATUS_OVERDUE ? 1 : 0;
        $this->failingCount += $cronOverview->hasLastEndedRunFailed() ? 1 : 0;
        $this->missingCommandCount += $cronOverview->status === CronOverview::STATUS_MISSING_COMMAND ? 1 : 0;
        $this->runCount24h += $cronOverview->stats24h->runCount;
        $this->failureCount24h += $cronOverview->stats24h->failureCount;
        if ($cronOverview->lastExecutionStartedAt !== null
            && ($this->lastRunStartedAt === null || $cronOverview->lastExecutionStartedAt->getTimestamp() > $this->lastRunStartedAt->getTimestamp())) {
            $this->lastRunStartedAt = $cronOverview->lastExecutionStartedAt;
        }
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }
}
