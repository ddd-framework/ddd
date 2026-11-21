<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer;

use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Tests\Infrastructure\Traits\Serializer\Fixtures\BooleanListHolder;
use DDD\Tests\Infrastructure\Traits\Serializer\Fixtures\SeveralTypesListFirstItem;
use DDD\Tests\Infrastructure\Traits\Serializer\Fixtures\SeveralTypesListHolder;
use DDD\Tests\Infrastructure\Traits\Serializer\Fixtures\SeveralTypesListSecondItem;
use PHPUnit\Framework\TestCase;

/**
 * Reading a list reads each ELEMENT: a boolean written as text or number becomes that element's boolean, and in a
 * list that takes several element types each element names its own type.
 */
class ListElementReadingTest extends TestCase
{
    public function testABooleanListConvertsEachElement(): void
    {
        $holder = new BooleanListHolder();
        $serialized = (object)['switches' => ['true', 1, true, 'false', 0, false, '']];
        $holder->setPropertiesFromObject($serialized);

        $this->assertSame([true, true, true, false, false, false, false], $holder->switches);
    }

    public function testANullElementOfABooleanListIsNeverReadAsFalse(): void
    {
        $holder = new BooleanListHolder();
        $serialized = (object)['switches' => [true, null]];
        try {
            $holder->setPropertiesFromObject($serialized);
        } catch (BadRequestException) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->assertSame([true], $holder->switches, 'null is no boolean: the list does not take it');
    }

    public function testAListWithSeveralElementTypesBuildsEachElementAsTheTypeItNames(): void
    {
        $holder = new SeveralTypesListHolder();
        $serialized = (object)[
            'items' => [
                (object)['objectType' => SeveralTypesListFirstItem::class, 'firstValue' => 'one'],
                (object)['objectType' => SeveralTypesListSecondItem::class, 'secondValue' => 'two'],
            ],
        ];
        $holder->setPropertiesFromObject($serialized);

        $this->assertCount(2, $holder->items);
        $this->assertSame(SeveralTypesListFirstItem::class, $holder->items[0]::class);
        $this->assertSame('one', $holder->items[0]->firstValue);
        $this->assertSame(SeveralTypesListSecondItem::class, $holder->items[1]::class);
        $this->assertSame('two', $holder->items[1]->secondValue);
    }

    public function testAListWithSeveralElementTypesIsWrittenAndReadBack(): void
    {
        $holder = new SeveralTypesListHolder();
        $first = new SeveralTypesListFirstItem();
        $first->firstValue = 'one';
        $second = new SeveralTypesListSecondItem();
        $second->secondValue = 'two';
        $holder->items = [$first, $second];

        $serialized = json_decode($holder->toJSON());
        $readBack = new SeveralTypesListHolder();
        $readBack->setPropertiesFromObject($serialized);

        $this->assertSame(SeveralTypesListFirstItem::class, $readBack->items[0]::class);
        $this->assertSame('two', $readBack->items[1]->secondValue);
    }

    public function testAnElementThatNamesNoTypeIsRefusedWhereSeveralAreAllowed(): void
    {
        $holder = new SeveralTypesListHolder();
        $serialized = (object)['items' => [(object)['firstValue' => 'one']]];

        $this->expectException(BadRequestException::class);
        $holder->setPropertiesFromObject($serialized);
    }

    public function testAPropertyIsReadWithoutAReflectionClassFromTheCaller(): void
    {
        $holder = new BooleanListHolder();
        $property = new ReflectionProperty(BooleanListHolder::class, 'switches');
        $value = [true, false];
        $holder->setPropertyFromObject($property, $value);

        $this->assertSame([true, false], $holder->switches);
    }
}
