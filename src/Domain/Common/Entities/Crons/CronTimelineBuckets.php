<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The buckets of one timeline lane that have runs, oldest first.
 *
 * @property CronTimelineBucket[] $elements
 * @method CronTimelineBucket[] getElements()
 * @method CronTimelineBucket|null getByUniqueKey(string $uniqueKey)
 */
class CronTimelineBuckets extends ObjectSet
{
}
