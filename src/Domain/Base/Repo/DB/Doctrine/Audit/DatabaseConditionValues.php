<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The bound values of a condition, in placeholder order.
 *
 * @property DatabaseConditionValue[] $elements
 * @method DatabaseConditionValue first()
 * @method DatabaseConditionValue[] getElements()
 */
class DatabaseConditionValues extends ObjectSet
{
    /**
     * The stored document of the values: one `{column, value}` per placeholder, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        $storedConditionValues = [];
        foreach ($this->getElements() as $databaseConditionValue) {
            $storedConditionValues[] = $databaseConditionValue->toArray();
        }
        return $storedConditionValues;
    }
}
