<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities;

use DDD\Domain\Base\Entities\ChildrenSet;
use DDD\Tests\Domain\Base\Entities\Fixtures\ParentKeyedElements\ParentKeyedElement;
use DDD\Tests\Domain\Base\Entities\Fixtures\ParentKeyedElements\ParentKeyedElements;
use PHPUnit\Framework\TestCase;

/**
 * A null element is skipped by {@see ChildrenSet::add()} and therefore by `addChildren()`, like by
 * {@see \DDD\Domain\Base\Entities\ObjectSet::add()} (3.38.0 called uniqueKey() on it); `containsOneOf()` answers for
 * the elements it is given.
 */
class ChildrenSetNullElementTest extends TestCase
{
    public function testANullChildIsSkipped(): void
    {
        $parent = new ParentKeyedElement();
        $child = new ParentKeyedElement();
        $child->content = 'child';
        $missingChild = null;

        $parent->addChildren($missingChild, $child);

        $this->assertSame([$child], array_values($parent->getChildren() ?? []));

        $childrenSet = new ChildrenSet();
        $childrenSet->add($missingChild);
        $this->assertSame(0, $childrenSet->count());
    }

    public function testContainsOneOfAnswersForTheGivenElements(): void
    {
        $set = new ParentKeyedElements();
        $element = new ParentKeyedElement();
        $element->content = 'in the set';
        $set->add($element);
        $otherElement = new ParentKeyedElement();
        $otherElement->content = 'not in the set';
        $otherSet = new ParentKeyedElements();
        $otherSet->add($otherElement);

        $this->assertTrue($set->containsOneOf($element));
        $this->assertFalse($set->containsOneOf($otherElement));
    }
}
