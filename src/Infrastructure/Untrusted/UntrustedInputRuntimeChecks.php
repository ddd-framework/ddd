<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The result of a runtime check of the untrusted-input rule.
 *
 * @property UntrustedInputRuntimeCheck[] $elements
 * @method UntrustedInputRuntimeCheck[] getElements()
 */
class UntrustedInputRuntimeChecks extends ObjectSet
{
    /**
     * @return bool true when every check is SAFE
     */
    public function isSafe(): bool
    {
        foreach ($this->getElements() as $untrustedInputRuntimeCheck) {
            if ($untrustedInputRuntimeCheck->status !== UntrustedInputRuntimeCheck::STATUS_SAFE) {
                return false;
            }
        }
        return true;
    }
}
