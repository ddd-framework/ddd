<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslationInfos;
use DDD\Tests\Domain\Base\Entities\Translatable\Fixtures\TranslatableFixtureRow;
use DDD\Tests\Domain\Base\Entities\Translatable\Fixtures\TranslatableFixtureRowRequestDto;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * A save that sets translation keys of a Translatable property in the store says which language each text belongs
 * to; the plain scalar must not add one of those texts under the current language as well. By default a scalar that
 * repeats a text of a payload's keys is ignored; inside Translatable::withStoreOnlyWrites() the scalar is ignored
 * whenever keys were set (by a payload or by code). The persisted map is what getTranslationsForProperty(…,
 * forPersistence: true) returns — the column value of update(), of updatePartialIgnoringRights() and of the merge
 * write.
 */
class TranslatableStoreOnlyWritesTest extends TranslatableTestCase
{
    protected function hydrateRow(object $payload): TranslatableFixtureRow
    {
        $translatableFixtureRow = new TranslatableFixtureRow();
        $translatableFixtureRow->setPropertiesFromObject($payload);
        return $translatableFixtureRow;
    }

    /** What DBEntity::mapToEntity() does with the stored column: the translations are loaded, not set for a save */
    protected function loadRowWithOnlyGerman(): TranslatableFixtureRow
    {
        $translatableFixtureRow = new TranslatableFixtureRow();
        $translatableFixtureRow->id = 7;
        $translatableFixtureRow->setTranslationsForProperty('name', ['de::FORMAL' => 'Brot']);
        $translatableFixtureRow->getTranslationInfos()->forgetStoreKeysSetForProperty('name');
        return $translatableFixtureRow;
    }

    protected function payloadWithMapAndPlainValue(string $plainValue, array $translations): object
    {
        return (object)[
            'id' => 7,
            'name' => $plainValue,
            'translationInfos' => (object)['translationsStore' => (object)['name' => (object)$translations]],
        ];
    }

    public function testAPlainValueRepeatingATextOfThePayloadsMapIsIgnored(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = $this->hydrateRow($this->payloadWithMapAndPlainValue('Bread', ['en::FORMAL' => 'Bread']));

        $this->assertSame(
            TranslationInfos::STORE_KEYS_SET_BY_PAYLOAD,
            $translatableFixtureRow->getTranslationInfos()->getStoreKeysSetBySourceForProperty('name')
        );
        $this->assertSame(
            ['en::FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    /** A client that echoes a read entity: the scalar shows a fallback from another language */
    public function testAnEchoedFallbackNextToTheMapIsIgnored(): void
    {
        Translatable::setCurrentLanguageCode('fr');
        $translatableFixtureRow = $this->hydrateRow(
            $this->payloadWithMapAndPlainValue('Brot', ['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'])
        );

        $this->assertSame(
            ['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    /** A client that echoes a read entity and edits the plain value: the new text is written, as before */
    public function testAPlainValueDifferingFromEveryTextOfTheMapIsWrittenByDefault(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = $this->hydrateRow(
            $this->payloadWithMapAndPlainValue('Brötchen', ['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'])
        );

        $this->assertSame(
            ['de::FORMAL' => 'Brötchen', 'en::FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testInsideStoreOnlyWritesAPayloadsKeysSuppressEveryScalar(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = $this->hydrateRow(
            $this->payloadWithMapAndPlainValue('Brötchen', ['en::FORMAL' => 'Bread'])
        );

        $persistedTranslations = Translatable::withStoreOnlyWrites(
            fn() => $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );

        $this->assertSame(['en::FORMAL' => 'Bread'], $persistedTranslations);
        $this->assertFalse(Translatable::isStoreOnlyWritesActive());
    }

    public function testAnEmptyMapSetsNoKeys(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = $this->hydrateRow($this->payloadWithMapAndPlainValue('Brot', []));

        $this->assertNull($translatableFixtureRow->getTranslationInfos()->getStoreKeysSetBySourceForProperty('name'));
        $this->assertSame(
            ['de::FORMAL' => 'Brot'],
            Translatable::withStoreOnlyWrites(
                fn() => $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
            )
        );
    }

    public function testOnlyThePropertyWithKeysLosesItsScalar(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $payload = $this->payloadWithMapAndPlainValue('Bread', ['en::FORMAL' => 'Bread']);
        $payload->title = 'Titel';
        $translatableFixtureRow = $this->hydrateRow($payload);

        $this->assertSame(
            ['de::FORMAL' => 'Titel'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('title', true)
        );
    }

    /** Code that sets the scalar for the current language and a key for another one keeps both by default */
    public function testKeysSetByCodeKeepTheScalarByDefault(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = new TranslatableFixtureRow();
        $translatableFixtureRow->name = 'Bread';
        $translatableFixtureRow->setTranslationForProperty('name', 'Bread', 'en');

        $this->assertSame(
            TranslationInfos::STORE_KEYS_SET_BY_CODE,
            $translatableFixtureRow->getTranslationInfos()->getStoreKeysSetBySourceForProperty('name')
        );
        $this->assertSame(
            ['en::FORMAL' => 'Bread', 'de::FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testInsideStoreOnlyWritesKeysSetByCodeSuppressTheScalar(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = new TranslatableFixtureRow();
        $translatableFixtureRow->name = 'Bread';
        $translatableFixtureRow->setTranslationForProperty('name', 'Bread', 'en');

        $this->assertSame(
            ['en::FORMAL' => 'Bread'],
            Translatable::withStoreOnlyWrites(
                fn() => $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
            )
        );
    }

    /** Loaded translations are no keys set for the save: a scalar set since the load is written also in the scope */
    public function testInsideStoreOnlyWritesALoadedEntitysNewScalarIsWritten(): void
    {
        Translatable::setCurrentLanguageCode('en');
        $loadedRow = $this->loadRowWithOnlyGerman();
        $loadedRow->name = 'Bread';

        $this->assertSame(
            ['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'],
            Translatable::withStoreOnlyWrites(
                fn() => $loadedRow->getTranslationInfos()->getTranslationsForProperty('name', true)
            )
        );
    }

    /** The merge path of an update endpoint: the loaded entity is overwritten from the payload, then written */
    public function testAnOverwriteFromAPayloadWithKeysTakesOverTheTracking(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $loadedRow = $this->loadRowWithOnlyGerman();
        $sentRow = $this->hydrateRow($this->payloadWithMapAndPlainValue('Bread', ['en::FORMAL' => 'Bread']));

        $loadedRow->overwritePropertiesFromOtherObject($sentRow);

        $this->assertSame('Bread', $loadedRow->name);
        $this->assertSame(
            TranslationInfos::STORE_KEYS_SET_BY_PAYLOAD,
            $loadedRow->getTranslationInfos()->getStoreKeysSetBySourceForProperty('name')
        );
        // the column is merged: the stored German translation stays, the English text is not added under German
        $this->assertSame(
            ['en::FORMAL' => 'Bread'],
            $loadedRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testTheTrackingSurvivesCloneAndSerialization(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = $this->hydrateRow($this->payloadWithMapAndPlainValue('Bread', ['en::FORMAL' => 'Bread']));

        $clonedRow = $translatableFixtureRow->clone();
        $unserializedRow = unserialize(serialize($translatableFixtureRow));
        // unserialize() does not restore the child's parent link (it never did); the tracking itself is serialized
        $unserializedRow->translationInfos->setParent($unserializedRow);

        foreach ([$clonedRow, $unserializedRow] as $copiedRow) {
            $this->assertSame(
                ['en::FORMAL' => 'Bread'],
                $copiedRow->getTranslationInfos()->getTranslationsForProperty('name', true)
            );
        }
    }

    public function testARequestDtoBodyWithMapAndPlainValueWritesTheMap(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $request = Request::create(
            '/rows',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'translatableFixtureRow' => [
                    'name' => 'Bread',
                    'translationInfos' => ['translationsStore' => ['name' => ['en::FORMAL' => 'Bread']]],
                ],
            ])
        );

        $translatableFixtureRowRequestDto = new TranslatableFixtureRowRequestDto();
        $translatableFixtureRowRequestDto->setPropertiesFromRequest($request);

        $this->assertSame(
            ['en::FORMAL' => 'Bread'],
            $translatableFixtureRowRequestDto->translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testTheStoreOnlyScopeEndsAlsoWhenAWriteThrows(): void
    {
        try {
            Translatable::withStoreOnlyWrites(static function (): void {
                throw new RuntimeException('write failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertFalse(Translatable::isStoreOnlyWritesActive());
    }

    public function testTheEnableRestorePairIsNestable(): void
    {
        $outerBefore = Translatable::enableStoreOnlyWrites();
        $innerBefore = Translatable::enableStoreOnlyWrites();
        $this->assertTrue($innerBefore);
        Translatable::restoreStoreOnlyWrites($innerBefore);
        $this->assertTrue(Translatable::isStoreOnlyWritesActive());
        Translatable::restoreStoreOnlyWrites($outerBefore);
        $this->assertFalse(Translatable::isStoreOnlyWritesActive());
    }

    public function testTheInputContentLanguagePairIsNestable(): void
    {
        $outerBefore = Translatable::enableInputContentLanguage('en-US');
        $this->assertNull($outerBefore);
        $innerBefore = Translatable::enableInputContentLanguage('fr');
        $this->assertSame('en-us', $innerBefore);
        $this->assertSame('fr', Translatable::getInputContentLanguageCode());
        Translatable::restoreInputContentLanguage($innerBefore);
        $this->assertSame('en', Translatable::getInputContentLanguageCode());
        $this->assertSame('us', Translatable::getInputContentCountryCode());
        Translatable::restoreInputContentLanguage($outerBefore);
        $this->assertNull(Translatable::getInputContentLanguageCode());
    }
}
