<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One page of Cron runs and the number of runs the filter matches over all pages.
 */
class CronExecutionsPage extends ValueObject
{
    /** Every run */
    public const string RESULT_ALL = 'ALL';
    /** Ended and successful */
    public const string RESULT_SUCCESSFUL = 'SUCCESSFUL';
    /** Ended and failed */
    public const string RESULT_FAILED = 'FAILED';
    /** Still running */
    public const string RESULT_RUNNING = 'RUNNING';

    /** @var string[] */
    public const array RESULTS = [self::RESULT_ALL, self::RESULT_SUCCESSFUL, self::RESULT_FAILED, self::RESULT_RUNNING];

    /** @var CronExecutionOverviews The runs, newest first */
    public CronExecutionOverviews $executions;

    /** @var int Runs matching the filter, over all pages */
    public int $totalCount = 0;

    public function __construct()
    {
        $this->executions = new CronExecutionOverviews();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }
}
