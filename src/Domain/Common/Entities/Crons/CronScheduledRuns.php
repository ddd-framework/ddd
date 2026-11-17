<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use Cron\CronExpression;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The next slots of a cron expression, earliest first.
 *
 * @property CronScheduledRun[] $elements
 * @method CronScheduledRun[] getElements()
 * @method CronScheduledRun|null first()
 */
class CronScheduledRuns extends ObjectSet
{
    /**
     * The next `$count` slots of a valid expression after `$after` (default now); none for an invalid one.
     */
    public static function fromSchedule(string $schedule, int $count, ?DateTime $after = null): static
    {
        $cronScheduledRuns = new static();
        if (!CronExpression::isValidExpression($schedule)) {
            return $cronScheduledRuns;
        }
        $cronExpression = new CronExpression($schedule);
        foreach ($cronExpression->getMultipleRunDates($count, $after ?? 'now') as $runDate) {
            $cronScheduledRun = new CronScheduledRun(DateTime::fromTimestamp($runDate->getTimestamp()));
            $cronScheduledRuns->add($cronScheduledRun);
        }
        return $cronScheduledRuns;
    }
}
