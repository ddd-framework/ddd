<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One Cron with what a details panel shows: the next slots, 14-day stats and the duration history.
 */
class CronDetail extends ValueObject
{
    /** Ended runs in the duration history */
    public const int DURATION_HISTORY_LIMIT = 100;

    /** @var CronOverview The Cron */
    public CronOverview $cron;

    /** @var CronScheduledRuns The next 5 slots from now; empty when paused or the command is missing */
    public CronScheduledRuns $nextRuns;

    /** @var CronRunStatistics Ended runs of the last 14 days */
    public CronRunStatistics $stats14d;

    /** @var CronExecutionOverviews The last 100 ended runs, oldest first, without output preview */
    public CronExecutionOverviews $durationHistory;

    /** @var DateTime The server's clock */
    public DateTime $serverNow;

    public function __construct()
    {
        $this->cron = new CronOverview();
        $this->nextRuns = new CronScheduledRuns();
        $this->stats14d = new CronRunStatistics();
        $this->durationHistory = new CronExecutionOverviews();
        $this->serverNow = new DateTime();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }
}
