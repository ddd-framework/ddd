<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\ExpandDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\HiddenExpand\HiddenExpandHolder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A relation with #[HideProperty] is never serialized: a request's expand may not name it (400, like a filter on it),
 * also nested, and the refusal lists only the relations a request may expand. Server code keeps expanding it.
 */
class ExpandOfHiddenRelationsTest extends TestCase
{
    protected function setUp(): void
    {
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, 'DB');
    }

    public function testTheDefinitionsMarkTheHiddenRelation(): void
    {
        $expandDefinitions = ExpandDefinitions::getExpandDefinitionsForReferenceClass(HiddenExpandHolder::class);

        self::assertFalse($expandDefinitions->getExpandDefinitionByPropertyName('items')->isHidden);
        self::assertTrue($expandDefinitions->getExpandDefinitionByPropertyName('internalItems')->isHidden);
    }

    public function testARequestMayExpandTheVisibleRelation(): void
    {
        self::assertTrue(ExpandOptions::fromString('items')->validateAgainstDefinitionsFromReferenceClass(HiddenExpandHolder::class, true));
    }

    public function testARequestMayNotExpandTheHiddenRelation(): void
    {
        try {
            ExpandOptions::fromString('internalItems')->validateAgainstDefinitionsFromReferenceClass(HiddenExpandHolder::class, true);
            self::fail('a hidden relation was accepted in a request expand');
        } catch (BadRequestException $badRequestException) {
            self::assertStringContainsString('(internalItems) is not allowed', $badRequestException->getMessage());
            self::assertStringContainsString('[items]', $badRequestException->getMessage(), 'the refusal never names the hidden relation');
        }
    }

    public function testARequestMayNotExpandAHiddenRelationInsideAnExpand(): void
    {
        $this->expectException(BadRequestException::class);
        ExpandOptions::fromString('items(expand=secretHolder)')->validateAgainstDefinitionsFromReferenceClass(HiddenExpandHolder::class, true);
    }

    public function testServerCodeKeepsExpandingTheHiddenRelation(): void
    {
        self::assertTrue(ExpandOptions::fromString('internalItems')->validateAgainstDefinitionsFromReferenceClass(HiddenExpandHolder::class));
        self::assertTrue(ExpandOptions::fromString('items(expand=secretHolder)')->validateAgainstDefinitionsFromReferenceClass(HiddenExpandHolder::class));
    }
}
