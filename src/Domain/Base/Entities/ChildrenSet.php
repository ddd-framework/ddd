<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

class ChildrenSet extends ObjectSet
{
    /**
     * Adds Element without adding Children
     * @param BaseObject ...$elements
     * @return void
     */
    public function add(?BaseObject &...$elements): void
    {
        foreach ($elements as $element) {
            $uniqueKeyOfElement = $element->uniqueKey();
            if (!isset($this->elementsByUniqueKey[$uniqueKeyOfElement])) {
                $this->elements[] = $element;
                $this->elementCount++;
                $this->elementsByUniqueKey[$uniqueKeyOfElement] = $element;
            }
        }
    }
}