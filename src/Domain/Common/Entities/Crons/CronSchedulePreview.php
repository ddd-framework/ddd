<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use Cron\CronExpression;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Whether a cron expression is valid, and its next slots. An invalid expression is a result, never an exception.
 */
class CronSchedulePreview extends ValueObject
{
    public const string INVALID_SCHEDULE_MESSAGE = "This isn't a valid cron expression. Use five fields: minute, hour, day of month, month, day of week.";

    /** Slots a preview shows */
    public const int NEXT_RUNS_COUNT = 5;

    /** @var bool Whether the expression is valid */
    public bool $isValid = false;

    /** @var string|null A sentence saying what is wrong; null when valid */
    public ?string $error = null;

    /** @var CronScheduledRuns The next 5 slots; empty when invalid */
    public CronScheduledRuns $nextRuns;

    public function __construct()
    {
        $this->nextRuns = new CronScheduledRuns();
        parent::__construct();
    }

    public static function fromSchedule(string $schedule): static
    {
        $cronSchedulePreview = new static();
        $schedule = trim($schedule);
        if ($schedule === '' || !CronExpression::isValidExpression($schedule)) {
            $cronSchedulePreview->error = self::INVALID_SCHEDULE_MESSAGE;
            return $cronSchedulePreview;
        }
        $cronSchedulePreview->isValid = true;
        $cronSchedulePreview->nextRuns = CronScheduledRuns::fromSchedule($schedule, self::NEXT_RUNS_COUNT);
        return $cronSchedulePreview;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }
}
