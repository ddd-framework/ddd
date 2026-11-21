<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer;

use DDD\Tests\Infrastructure\Traits\Serializer\Fixtures\AttributedSerializationProbe;
use PHPUnit\Framework\TestCase;

/**
 * What the serialization attributes of a property put on the wire: another name, aliases, hidden, not persisted —
 * for display, for persistence and with null values kept. The serializer reads these attributes once per class;
 * this pins the wire of every object after the first.
 */
class SerializationAttributesWireTest extends TestCase
{
    protected function buildProbe(): AttributedSerializationProbe
    {
        $probe = new AttributedSerializationProbe();
        $probe->plain = 'p';
        $probe->original = 'o';
        $probe->aliased = 'a';
        $probe->hidden = 'h';
        $probe->notPersisted = 'n';
        return $probe;
    }

    public function testDisplayRenamesAddsAliasesAndLeavesOutTheHiddenProperty(): void
    {
        $expected = [
            'plain' => 'p',
            'renamed' => 'o',
            'aliased' => 'a',
            'firstAlias' => 'a',
            'secondAlias' => 'a',
            'notPersisted' => 'n',
            'objectType' => AttributedSerializationProbe::class,
        ];
        // twice: the second object is serialized from what was read about the class for the first
        $this->assertSame($expected, $this->buildProbe()->toObject(false, forPersistence: false));
        $this->assertSame($expected, $this->buildProbe()->toObject(false, forPersistence: false));
    }

    public function testPersistenceKeepsTheHiddenPropertyAndLeavesOutTheOneNotPersisted(): void
    {
        $expected = [
            'plain' => 'p',
            'renamed' => 'o',
            'aliased' => 'a',
            'firstAlias' => 'a',
            'secondAlias' => 'a',
            'hidden' => 'h',
            'objectType' => AttributedSerializationProbe::class,
        ];
        $this->assertSame($expected, $this->buildProbe()->toObject(false, ignoreHideAttributes: true));
        $this->assertSame($expected, $this->buildProbe()->toObject(false, ignoreHideAttributes: true));
    }

    public function testNullValuesAreKeptOnRequestAndAStaticPropertyIsNeverSerialized(): void
    {
        $serialized = $this->buildProbe()->toObject(false, ignoreNullValues: false, forPersistence: false);

        $this->assertArrayHasKey('empty', $serialized);
        $this->assertNull($serialized['empty']);
        $this->assertArrayNotHasKey('staticValue', $serialized);
        $this->assertArrayNotHasKey('hidden', $serialized);
    }

    public function testAnObjectWithItsOwnHiddenPropertiesIsSerializedByItsOwnList(): void
    {
        $probe = $this->buildProbe();
        $probe->addPropertiesToHide('plain');

        $this->assertArrayNotHasKey('plain', $probe->toObject(false, forPersistence: false));
        $this->assertArrayHasKey('plain', $this->buildProbe()->toObject(false, forPersistence: false));
    }
}
