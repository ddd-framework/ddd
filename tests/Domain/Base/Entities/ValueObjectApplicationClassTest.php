<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use PHPUnit\Framework\TestCase;
use DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass\ApplicationSettingsProbe;
use DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass\ModuleSettingsHolderProbe;
use DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass\ModuleSettingsProbe;
use DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass\ModuleSettingsSetProbe;
use DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass\PlainClassProbe;
use DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass\SpecificSettingsProbe;
use DDD\Tests\Domain\Base\Entities\Fixtures\ValueObjectApplicationClass\UnrelatedSettingsProbe;

/**
 * A value object that is read from a stored or serialized value is built as the application's class of its declared
 * class (container alias or same path under App\) when that class is a genuine subclass — from a column
 * ({@see DBEntity::resolveValueObjectClassOfStoredValue()}) and from JSON (the serializer, the elements of an object set). Before, a module's
 * `?RunSettings $runSettings` came back as the module's class and the fields the application had declared and stored
 * were dropped on every load.
 */
class ValueObjectApplicationClassTest extends TestCase
{
    protected mixed $containerServiceClassMapBefore;

    protected array $frameworkToAppClassNamesBefore;

    protected array $applicationClassNamesOfValueObjectsBefore;

    protected function setUp(): void
    {
        $this->containerServiceClassMapBefore = StaticRegistry::$containerServiceClassMap;
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        $this->applicationClassNamesOfValueObjectsBefore = StaticRegistry::$applicationClassNamesOfValueObjects;
        StaticRegistry::$containerServiceClassMap = ['Some\Other\Service' => 'Some\Other\Service'];
        StaticRegistry::$applicationClassNamesOfValueObjects = [];
    }

    protected function tearDown(): void
    {
        StaticRegistry::$containerServiceClassMap = $this->containerServiceClassMapBefore;
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        StaticRegistry::$applicationClassNamesOfValueObjects = $this->applicationClassNamesOfValueObjectsBefore;
    }

    protected function declareApplicationClass(string $moduleClassName, string $applicationClassName): void
    {
        StaticRegistry::$frameworkToAppClassNames[$moduleClassName] = $applicationClassName;
    }

    public function testTheApplicationsSubclassIsTheClassOfAValueObject(): void
    {
        $this->declareApplicationClass(ModuleSettingsProbe::class, ApplicationSettingsProbe::class);

        $this->assertSame(
            ApplicationSettingsProbe::class,
            DefaultObject::getApplicationClassNameOfValueObject(ModuleSettingsProbe::class)
        );
        // resolved once: the registry answers the next read
        $this->assertSame(
            ApplicationSettingsProbe::class,
            StaticRegistry::$applicationClassNamesOfValueObjects[ModuleSettingsProbe::class]
        );
    }

    public function testWithoutAnApplicationClassTheClassStays(): void
    {
        $this->assertSame(
            ModuleSettingsProbe::class,
            DefaultObject::getApplicationClassNameOfValueObject(ModuleSettingsProbe::class)
        );
    }

    public function testAnApplicationClassOutsideTheHierarchyIsIgnored(): void
    {
        $this->declareApplicationClass(ModuleSettingsProbe::class, UnrelatedSettingsProbe::class);

        $this->assertSame(
            ModuleSettingsProbe::class,
            DefaultObject::getApplicationClassNameOfValueObject(ModuleSettingsProbe::class)
        );
    }

    public function testAClassThatIsNoValueObjectIsReturnedUnchanged(): void
    {
        $this->declareApplicationClass(PlainClassProbe::class, ApplicationSettingsProbe::class);

        $this->assertSame(PlainClassProbe::class, DefaultObject::getApplicationClassNameOfValueObject(PlainClassProbe::class));
    }

    public function testAStoredValueIsBuiltAsTheApplicationsClassWhateverItNames(): void
    {
        $this->declareApplicationClass(ModuleSettingsProbe::class, ApplicationSettingsProbe::class);

        // a row written by the application names the application's class
        $this->assertSame(
            ApplicationSettingsProbe::class,
            DBEntity::resolveValueObjectClassOfStoredValue(
                ModuleSettingsProbe::class,
                ['objectType' => ApplicationSettingsProbe::class, 'ownRowId' => 7]
            )
        );
        // a row that names the module's class, a row without a class, the object form, a still encrypted string
        $this->assertSame(
            ApplicationSettingsProbe::class,
            DBEntity::resolveValueObjectClassOfStoredValue(ModuleSettingsProbe::class, ['objectType' => ModuleSettingsProbe::class])
        );
        $this->assertSame(
            ApplicationSettingsProbe::class,
            DBEntity::resolveValueObjectClassOfStoredValue(ModuleSettingsProbe::class, ['maxSteps' => 3])
        );
        $this->assertSame(
            ApplicationSettingsProbe::class,
            DBEntity::resolveValueObjectClassOfStoredValue(ModuleSettingsProbe::class, (object)['maxSteps' => 3])
        );
        $this->assertSame(
            ApplicationSettingsProbe::class,
            DBEntity::resolveValueObjectClassOfStoredValue(ModuleSettingsProbe::class, 'encrypted-text')
        );
    }

    public function testAStoredSubclassOfTheDeclaredTypeIsKept(): void
    {
        $this->assertSame(
            SpecificSettingsProbe::class,
            DBEntity::resolveValueObjectClassOfStoredValue(
                ModuleSettingsProbe::class,
                ['objectType' => SpecificSettingsProbe::class, 'kind' => 'weekly']
            )
        );
        $this->assertSame(
            SpecificSettingsProbe::class,
            DBEntity::resolveValueObjectClassOfStoredValue(
                ModuleSettingsProbe::class,
                (object)['objectType' => SpecificSettingsProbe::class]
            )
        );
    }

    public function testAStoredClassOutsideTheDeclaredTypeIsIgnored(): void
    {
        $this->assertSame(
            ModuleSettingsProbe::class,
            DBEntity::resolveValueObjectClassOfStoredValue(ModuleSettingsProbe::class, ['objectType' => UnrelatedSettingsProbe::class])
        );
        $this->assertSame(
            ModuleSettingsProbe::class,
            DBEntity::resolveValueObjectClassOfStoredValue(ModuleSettingsProbe::class, ['objectType' => 'No\Such\Class'])
        );
        $this->assertSame(
            ModuleSettingsProbe::class,
            DBEntity::resolveValueObjectClassOfStoredValue(ModuleSettingsProbe::class, ['objectType' => 12])
        );
        $this->assertSame(ModuleSettingsProbe::class, DBEntity::resolveValueObjectClassOfStoredValue(ModuleSettingsProbe::class, null));
    }

    public function testTheSerializerBuildsTheApplicationsClassAndKeepsItsFields(): void
    {
        $this->declareApplicationClass(ModuleSettingsProbe::class, ApplicationSettingsProbe::class);

        $holder = new ModuleSettingsHolderProbe();
        $serialized = (object)[
            'settings' => (object)['maxSteps' => 3, 'ownRowId' => 7],
            'settingsList' => [
                (object)['objectType' => ModuleSettingsProbe::class, 'maxSteps' => 4, 'ownRowId' => 8],
                (object)['objectType' => ApplicationSettingsProbe::class, 'ownRowId' => 9],
            ],
        ];
        $holder->setPropertiesFromObject($serialized);

        $this->assertSame(ApplicationSettingsProbe::class, $holder->settings::class);
        $this->assertSame(3, $holder->settings->maxSteps);
        $this->assertSame(7, $holder->settings->ownRowId);
        $this->assertSame(ApplicationSettingsProbe::class, $holder->settingsList[0]::class);
        $this->assertSame(8, $holder->settingsList[0]->ownRowId);
        $this->assertSame(ApplicationSettingsProbe::class, $holder->settingsList[1]::class);
        $this->assertSame(9, $holder->settingsList[1]->ownRowId);
    }

    public function testTheSerializerKeepsAProvidedSubclassAndTheDeclaredClassWithoutAnApplicationClass(): void
    {
        $holder = new ModuleSettingsHolderProbe();
        $serialized = (object)[
            'settings' => (object)['objectType' => SpecificSettingsProbe::class, 'kind' => 'weekly'],
            'settingsList' => [(object)['maxSteps' => 5]],
        ];
        $holder->setPropertiesFromObject($serialized);

        $this->assertSame(SpecificSettingsProbe::class, $holder->settings::class);
        $this->assertSame('weekly', $holder->settings->kind);
        $this->assertSame(ModuleSettingsProbe::class, $holder->settingsList[0]::class);
    }

    public function testAnObjectThatNamesOnlyTheDeclaredClassIsStillBuilt(): void
    {
        $this->declareApplicationClass(ModuleSettingsProbe::class, ApplicationSettingsProbe::class);

        $holder = new ModuleSettingsHolderProbe();
        $serialized = (object)['settings' => (object)['objectType' => ModuleSettingsProbe::class]];
        $holder->setPropertiesFromObject($serialized);

        $this->assertTrue(isset($holder->settings));
        $this->assertSame(ApplicationSettingsProbe::class, $holder->settings::class);
    }

    public function testAnObjectThatNamesOnlyAForeignClassStaysAbsent(): void
    {
        $this->declareApplicationClass(ModuleSettingsProbe::class, ApplicationSettingsProbe::class);

        $holder = new ModuleSettingsHolderProbe();
        $serialized = (object)['settings' => (object)['objectType' => UnrelatedSettingsProbe::class]];
        $holder->setPropertiesFromObject($serialized);

        $this->assertFalse(isset($holder->settings));
    }

    public function testTheElementsOfAnObjectSetAreBuiltAsTheApplicationsClass(): void
    {
        $this->declareApplicationClass(ModuleSettingsProbe::class, ApplicationSettingsProbe::class);

        $serialized = (object)[
            'elements' => [
                (object)['maxSteps' => 1, 'ownRowId' => 5],
                (object)['objectType' => SpecificSettingsProbe::class, 'kind' => 'weekly'],
            ],
        ];
        $set = new ModuleSettingsSetProbe();
        $set->setPropertiesFromObject($serialized);

        $this->assertSame(ApplicationSettingsProbe::class, $set->elements[0]::class);
        $this->assertSame(5, $set->elements[0]->ownRowId);
        $this->assertSame(SpecificSettingsProbe::class, $set->elements[1]::class);
        $this->assertSame('weekly', $set->elements[1]->kind);
    }
}
