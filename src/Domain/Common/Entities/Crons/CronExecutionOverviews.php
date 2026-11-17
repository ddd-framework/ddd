<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Runs of Crons as an admin screen lists them, in the order they were added (newest first unless a read says
 * otherwise).
 *
 * @property CronExecutionOverview[] $elements
 * @method CronExecutionOverview[] getElements()
 * @method CronExecutionOverview|null first()
 * @method CronExecutionOverview|null getByUniqueKey(string $uniqueKey)
 */
class CronExecutionOverviews extends ObjectSet
{
    /** The newest ENDED run (the first one in a newest-first set); a run still RUNNING is skipped */
    public function getNewestEndedExecution(): ?CronExecutionOverview
    {
        foreach ($this->getElements() as $cronExecutionOverview) {
            if ($cronExecutionOverview->isEnded()) {
                return $cronExecutionOverview;
            }
        }
        return null;
    }

    public function countFailedExecutions(): int
    {
        $failedExecutionCount = 0;
        foreach ($this->getElements() as $cronExecutionOverview) {
            $failedExecutionCount += $cronExecutionOverview->hasFailed() ? 1 : 0;
        }
        return $failedExecutionCount;
    }

    /** The same runs in reverse order (e.g. oldest first for a chart) */
    public function getReversed(): static
    {
        $reversedCronExecutionOverviews = new static();
        foreach (array_reverse($this->getElements()) as $cronExecutionOverview) {
            $reversedCronExecutionOverviews->add($cronExecutionOverview);
        }
        return $reversedCronExecutionOverviews;
    }

    /** The runs of one Cron, in the same order */
    public function getExecutionsOfCron(int $cronId): static
    {
        $cronExecutionOverviewsOfCron = new static();
        foreach ($this->getElements() as $cronExecutionOverview) {
            if ($cronExecutionOverview->cronId === $cronId) {
                $cronExecutionOverviewsOfCron->add($cronExecutionOverview);
            }
        }
        return $cronExecutionOverviewsOfCron;
    }
}
