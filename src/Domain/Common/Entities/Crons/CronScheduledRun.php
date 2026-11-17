<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One slot at which a cron expression runs.
 */
class CronScheduledRun extends ValueObject
{
    public function __construct(
        /** @var DateTime When the slot is, in the PHP default time zone the runner uses */
        public DateTime $scheduledAt = new DateTime(),
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->scheduledAt->getTimestamp());
    }
}
