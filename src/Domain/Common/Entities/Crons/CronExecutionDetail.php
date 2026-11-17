<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One Cron run with its full output.
 */
class CronExecutionDetail extends ValueObject
{
    /** @var CronExecutionOverview The run */
    public CronExecutionOverview $execution;

    /** @var string The full output (stdout, stderr, the executor's reason); empty when the run wrote none */
    public string $output = '';

    public function __construct()
    {
        $this->execution = new CronExecutionOverview();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }
}
