<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * One timeline lane per Cron, sorted by name.
 *
 * @property CronTimelineLane[] $elements
 * @method CronTimelineLane[] getElements()
 * @method CronTimelineLane|null getByUniqueKey(string $uniqueKey)
 */
class CronTimelineLanes extends ObjectSet
{
    public function getByCronId(int $cronId): ?CronTimelineLane
    {
        return $this->getByUniqueKey(CronTimelineLane::uniqueKeyStatic((string)$cronId));
    }
}
