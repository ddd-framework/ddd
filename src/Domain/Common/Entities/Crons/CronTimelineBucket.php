<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Runs of one Cron that started inside one time bucket.
 */
class CronTimelineBucket extends ValueObject
{
    /** @var DateTime Start of the bucket */
    public DateTime $startsAt;

    /** @var int Runs that started in the bucket */
    public int $runCount = 0;

    /** @var int Of those, runs that failed */
    public int $failureCount = 0;

    /** @var int|null Longest ended run of the bucket in milliseconds; null when none ended */
    public ?int $maxDurationMs = null;

    public function __construct(?DateTime $startsAt = null)
    {
        $this->startsAt = $startsAt ?? new DateTime();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->startsAt->getTimestamp());
    }

    /** Counts one run that started in this bucket */
    public function addExecution(CronExecutionOverview $cronExecutionOverview): void
    {
        $this->runCount++;
        $this->failureCount += $cronExecutionOverview->hasFailed() ? 1 : 0;
        if ($cronExecutionOverview->isEnded() && $cronExecutionOverview->durationMs !== null) {
            $this->maxDurationMs = max($this->maxDurationMs ?? 0, $cronExecutionOverview->durationMs);
        }
    }
}
