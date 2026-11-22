<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

class ChildrenSet extends ObjectSet
{
    /**
     * Adds Element without adding Children; a null element is skipped, as in {@see ObjectSet::add()}.
     * @param BaseObject|null ...$elements
     * @return void
     */
    public function add(?BaseObject &...$elements): void
    {
        foreach ($elements as $element) {
            if (!$element) {
                continue;
            }
            $uniqueKeyOfElement = $element->uniqueKey();
            if (!isset($this->elementsByUniqueKey[$uniqueKeyOfElement])) {
                $this->elements[] = $element;
                $this->elementCount++;
                $this->elementsByUniqueKey[$uniqueKeyOfElement] = $element;
            }
        }
    }
}