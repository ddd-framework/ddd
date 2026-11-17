<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Every Cron with its health, and the runner's summary.
 */
class CronsOverview extends ValueObject
{
    /** @var CronOverviews The Crons, sorted by name */
    public CronOverviews $crons;

    /** @var CronRunnerSummary The runner's summary */
    public CronRunnerSummary $runner;

    public function __construct()
    {
        $this->crons = new CronOverviews();
        $this->runner = new CronRunnerSummary();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }
}
