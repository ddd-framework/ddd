<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Tests\Domain\Base\Entities\Translatable\Fixtures\TranslatableFixtureRow;

/**
 * A loaded entity's Translatable scalar holds the current language's translation or a fallback from another
 * language. A write of that entity must not store the fallback text as the current language's translation; only a
 * value set since the load is written under the current language. The persisted map is what
 * getTranslationsForProperty(…, forPersistence: true) returns (the column value of update(), of
 * updatePartialIgnoringRights() and of the merge write).
 */
class TranslatableFallbackWriteBackTest extends TranslatableTestCase
{
    protected function loadRowWithOnlyGerman(): TranslatableFixtureRow
    {
        $translatableFixtureRow = new TranslatableFixtureRow();
        $translatableFixtureRow->id = 7;
        // what DBEntity::mapToEntity() does with the stored column
        $translatableFixtureRow->setTranslationsForProperty('name', ['de::FORMAL' => 'Brot']);
        return $translatableFixtureRow;
    }

    public function testAFallbackTextIsNotWrittenUnderTheCurrentLanguage(): void
    {
        Translatable::setCurrentLanguageCode('en');
        $translatableFixtureRow = $this->loadRowWithOnlyGerman();

        $this->assertSame('Brot', $translatableFixtureRow->name, 'precondition: the scalar holds the German fallback');
        $this->assertSame(
            ['de::FORMAL' => 'Brot'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testAValueSetSinceTheLoadIsWrittenUnderTheCurrentLanguage(): void
    {
        Translatable::setCurrentLanguageCode('en');
        $translatableFixtureRow = $this->loadRowWithOnlyGerman();
        $translatableFixtureRow->name = 'Bread';

        $this->assertSame(
            ['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testTheCurrentLanguagesOwnTranslationIsWrittenUnchanged(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = $this->loadRowWithOnlyGerman();

        $this->assertSame(
            ['de::FORMAL' => 'Brot'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testANewEntitysScalarIsWrittenUnderTheCurrentLanguage(): void
    {
        Translatable::setCurrentLanguageCode('fr');
        $translatableFixtureRow = new TranslatableFixtureRow();
        $translatableFixtureRow->name = 'Pain';

        $this->assertSame(
            ['fr::FORMAL' => 'Pain'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testTheTrackingSurvivesCloneAndSerialization(): void
    {
        Translatable::setCurrentLanguageCode('en');
        $translatableFixtureRow = $this->loadRowWithOnlyGerman();

        $clonedRow = $translatableFixtureRow->clone();
        $unserializedRow = unserialize(serialize($translatableFixtureRow));
        // unserialize() does not restore the child's parent link (it never did); the tracking itself is serialized
        $unserializedRow->translationInfos->setParent($unserializedRow);

        foreach ([$clonedRow, $unserializedRow] as $copiedRow) {
            $this->assertSame(
                ['de::FORMAL' => 'Brot'],
                $copiedRow->getTranslationInfos()->getTranslationsForProperty('name', true)
            );
        }
    }

    /** The merge path of an update endpoint: the loaded entity is overwritten from the payload, then written */
    public function testAnOverwriteFromAPayloadWithoutThePropertyKeepsTheFallbackOutOfTheWrite(): void
    {
        Translatable::setCurrentLanguageCode('en');
        $loadedRow = $this->loadRowWithOnlyGerman();
        $sentRow = new TranslatableFixtureRow();
        $sentRow->id = 7;
        $sentRow->label = 'changed';

        $loadedRow->overwritePropertiesFromOtherObject($sentRow);

        $this->assertSame('changed', $loadedRow->label);
        $this->assertSame(
            ['de::FORMAL' => 'Brot'],
            $loadedRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testAnOverwriteWithANewPlainValueWritesItUnderTheCurrentLanguage(): void
    {
        Translatable::setCurrentLanguageCode('en');
        $loadedRow = $this->loadRowWithOnlyGerman();
        $sentRow = new TranslatableFixtureRow();
        $sentRow->id = 7;
        $sentRow->name = 'Bread';

        $loadedRow->overwritePropertiesFromOtherObject($sentRow);

        $this->assertSame(
            ['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'],
            $loadedRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testSetTranslationForTheCurrentLanguageIsWrittenOnce(): void
    {
        Translatable::setCurrentLanguageCode('en');
        $translatableFixtureRow = $this->loadRowWithOnlyGerman();
        $translatableFixtureRow->setTranslationForProperty('name', 'Bread', 'en');

        $this->assertSame('Bread', $translatableFixtureRow->name);
        $this->assertSame(
            ['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }
}
