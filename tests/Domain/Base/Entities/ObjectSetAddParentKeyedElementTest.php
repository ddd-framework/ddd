<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities;

use DDD\Tests\Domain\Base\Entities\Fixtures\ParentKeyedElements\ParentKeyedElement;
use DDD\Tests\Domain\Base\Entities\Fixtures\ParentKeyedElements\ParentKeyedElements;
use PHPUnit\Framework\TestCase;

/**
 * {@see \DDD\Domain\Base\Entities\ObjectSet::add()} sets the element's parent between its duplicate check and the
 * store. An element whose unique key reads its parent has another key after that, so the store reads the key again:
 * with the key of the check, every further element without a parent would collide with the first one and be dropped.
 */
class ObjectSetAddParentKeyedElementTest extends TestCase
{
    public function testElementsWhoseKeyReadsTheParentAreAllAdded(): void
    {
        $set = new ParentKeyedElements();
        $first = new ParentKeyedElement();
        $first->content = 'first';
        $second = new ParentKeyedElement();
        $second->content = 'second';
        // before they are added both have the same key: no parent yet
        $this->assertSame($first->uniqueKey(), $second->uniqueKey());

        $set->add($first);
        $set->add($second);

        $this->assertSame(2, $set->count());
        $contents = [];
        foreach ($set->getElements() as $element) {
            $contents[] = $element->content;
        }
        $this->assertSame(['first', 'second'], $contents);
    }

    public function testAnAddedElementIsFoundUnderTheKeyItHasInTheSet(): void
    {
        $set = new ParentKeyedElements();
        $element = new ParentKeyedElement();
        $element->content = 'first';
        $set->add($element);

        $this->assertTrue($set->contains($element));
        $this->assertSame($element, $set->getByUniqueKey($element->uniqueKey()));
        // adding it again changes nothing
        $set->add($element);
        $this->assertSame(1, $set->count());
    }
}
