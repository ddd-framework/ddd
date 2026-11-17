<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Crons as an admin screen shows them, sorted by name.
 *
 * @property CronOverview[] $elements
 * @method CronOverview[] getElements()
 * @method CronOverview|null getByUniqueKey(string $uniqueKey)
 */
class CronOverviews extends ObjectSet
{
    public function getByCronId(int $cronId): ?CronOverview
    {
        return $this->getByUniqueKey(CronOverview::uniqueKeyStatic((string)$cronId));
    }
}
