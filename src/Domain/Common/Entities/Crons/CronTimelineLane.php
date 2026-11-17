<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One row of the runs timeline: a Cron and the buckets in which it ran.
 */
class CronTimelineLane extends ValueObject
{
    /** @var int Id of the Cron */
    public int $cronId = 0;

    /** @var string Name of the Cron */
    public string $cronName = '';

    /** @var CronTimelineBuckets Only the buckets that have runs, oldest first */
    public CronTimelineBuckets $buckets;

    public function __construct()
    {
        $this->buckets = new CronTimelineBuckets();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->cronId);
    }
}
