<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Run counts and durations of one Cron (or of all) over a window. Only ENDED runs count.
 */
class CronRunStatistics extends ValueObject
{
    /** @var int Ended runs in the window */
    public int $runCount = 0;

    /** @var int Ended runs that failed */
    public int $failureCount = 0;

    /** @var float|null Share of successful runs from 0 to 1; null when there was no run */
    public ?float $successRate = null;

    /** @var int|null Average duration of an ended run in milliseconds; null when there was no run */
    public ?int $averageDurationMs = null;

    /** @var int|null Longest duration of an ended run in milliseconds; null when there was no run */
    public ?int $maxDurationMs = null;

    /**
     * The statistics of the ENDED runs among the given ones; runs still RUNNING are left out.
     */
    public static function fromExecutions(CronExecutionOverviews $cronExecutionOverviews): static
    {
        $cronRunStatistics = new static();
        $durationsMs = [];
        foreach ($cronExecutionOverviews->getElements() as $cronExecutionOverview) {
            if (!$cronExecutionOverview->isEnded()) {
                continue;
            }
            $cronRunStatistics->runCount++;
            $cronRunStatistics->failureCount += $cronExecutionOverview->hasFailed() ? 1 : 0;
            if ($cronExecutionOverview->durationMs !== null) {
                $durationsMs[] = $cronExecutionOverview->durationMs;
            }
        }
        if ($cronRunStatistics->runCount === 0) {
            return $cronRunStatistics;
        }
        $cronRunStatistics->successRate = ($cronRunStatistics->runCount - $cronRunStatistics->failureCount) / $cronRunStatistics->runCount;
        if ($durationsMs !== []) {
            $cronRunStatistics->averageDurationMs = (int)round(array_sum($durationsMs) / count($durationsMs));
            $cronRunStatistics->maxDurationMs = max($durationsMs);
        }
        return $cronRunStatistics;
    }
}
