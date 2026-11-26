<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems\AIBadges;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The targets of the badge settings, one per name.
 *
 * @property AIBadgeStampTarget[] $elements
 * @method AIBadgeStampTarget|null first()
 * @method AIBadgeStampTarget[] getElements()
 */
class AIBadgeStampTargets extends ObjectSet
{
    /** The target of that name, or null */
    public function getTargetByName(string $targetName): ?AIBadgeStampTarget
    {
        /** @var AIBadgeStampTarget|null $target */
        $target = $this->getByUniqueKey(AIBadgeStampTarget::uniqueKeyStatic($targetName));
        return $target;
    }

    /**
     * The names of the targets, in config order.
     *
     * @return string[]
     */
    public function getTargetNames(): array
    {
        $targetNames = [];
        foreach ($this->getElements() as $target) {
            $targetNames[] = $target->name;
        }
        return $targetNames;
    }
}
