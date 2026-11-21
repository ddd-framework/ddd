<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Repo;

use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRow;
use DDD\Tests\Integration\IntegrationTestCase;
use ReflectionProperty;

/**
 * Translatable on both engines:
 * - the fallback a reader takes when the current language has no translation does not depend on the stored key
 *   order — PostgreSQL's jsonb (SqlDialect::jsonUpdate()->mergePatch()) re-sorts the keys of a rewritten document;
 * - the search column of a fullTextIndex Translatable joins the values in key order on PostgreSQL;
 * - a write of a loaded entity never stores the fallback text it read as the current language's translation, through
 *   update(), updatePartialIgnoringRights() and the overwrite-then-update path of an update endpoint;
 * - a save that carries translation keys for a property in translationInfos.translationsStore does not add the
 *   property's plain value under the current language when it repeats one of those texts (always), or at all
 *   (inside Translatable::withStoreOnlyWrites()), through the same three paths.
 */
class TranslatableKeyOrderAndFallbackWriteTest extends IntegrationTestCase
{
    /** @var string[] Names of the Translatable statics this test changes */
    protected const array CONFIGURATION_PROPERTY_NAMES = [
        'defaultLanguageCode',
        'activeLanguageCodes',
        'fallbackToDefaultLanguageCode',
        'fallbackToNativeValue',
    ];

    /** @var mixed[] Property name => value before the test */
    protected array $configurationBeforeTest = [];

    protected function setUp(): void
    {
        parent::setUp();
        Translatable::getDefaultLanguageCode();
        Translatable::fallbackToDefaultLanguageIfNoTranslationIsPresent();
        Translatable::fallbackToNativeValueIfNoTranslationIsPresent();
        foreach (self::CONFIGURATION_PROPERTY_NAMES as $propertyName) {
            $reflectionProperty = new ReflectionProperty(Translatable::class, $propertyName);
            if ($reflectionProperty->isInitialized()) {
                $this->configurationBeforeTest[$propertyName] = $reflectionProperty->getValue();
            }
        }
        Translatable::$defaultLanguageCode = 'it';
        Translatable::$activeLanguageCodes = ['it', 'fr', 'de', 'en'];
        Translatable::$fallbackToDefaultLanguageCode = true;
        Translatable::$fallbackToNativeValue = true;
        Translatable::resetToDefaultsForNewJob();
        Translatable::setCurrentWritingStyle(Translatable::WRITING_STYLE_FORMAL);
    }

    protected function tearDown(): void
    {
        foreach (self::CONFIGURATION_PROPERTY_NAMES as $propertyName) {
            if (array_key_exists($propertyName, $this->configurationBeforeTest)) {
                (new ReflectionProperty(Translatable::class, $propertyName))->setValue(null, $this->configurationBeforeTest[$propertyName]);
            }
        }
        if (!array_key_exists('activeLanguageCodes', $this->configurationBeforeTest)) {
            Translatable::$activeLanguageCodes = [];
        }
        Translatable::resetToDefaultsForNewJob();
        parent::tearDown();
    }

    protected function insertTranslatedRow(string $namesJson): int
    {
        static::insertRow('TranslatedRows', ['name' => $namesJson]);
        return (int)static::connection()->lastInsertId();
    }

    /** A fresh read from the database: no entity registry, no unit of work */
    protected function loadTranslatedRow(int $rowId): TranslatedRow
    {
        DoctrineEntityRegistry::clear();
        EntityManagerFactory::clearAllInstanceCaches();
        /** @var TranslatedRow $translatedRow */
        $translatedRow = (new DBTranslatedRow())->find($rowId, false);
        $this->assertInstanceOf(TranslatedRow::class, $translatedRow);
        return $translatedRow;
    }

    /** @return array<string, string> The stored translations of a column */
    protected function fetchStoredTranslations(int $rowId, string $columnName): array
    {
        return json_decode((string)$this->fetchRow('TranslatedRows', $rowId)[$columnName], true) ?? [];
    }

    protected function mergeIntoNameColumn(int $rowId, string $patchJson): void
    {
        $dialect = static::dialect();
        $nameColumn = $dialect->quote('name');
        static::connection()->executeStatement(
            'UPDATE ' . $dialect->quote('TranslatedRows') . " SET $nameColumn = "
            . $dialect->jsonUpdate($nameColumn)->mergePatch(':patch')->toSql()
            . ' WHERE ' . $dialect->quote('id') . ' = :id',
            ['patch' => $patchJson, 'id' => $rowId]
        );
    }

    /** The repro of the report: column {"de::FORMAL":"a","en::FORMAL":"b"}, merge {"fr::FORMAL":"c"} */
    public function testTheFallbackAfterAMergeFollowsTheConfiguredOrderNotTheStoredKeyOrder(): void
    {
        Translatable::setCurrentLanguageCode('es');
        $rowId = $this->insertTranslatedRow('{"de::FORMAL":"a","en::FORMAL":"b"}');

        $this->assertSame('a', $this->loadTranslatedRow($rowId)->name, 'de is ranked before en');

        $this->mergeIntoNameColumn($rowId, '{"fr::FORMAL":"c"}');

        $storedNames = $this->fetchStoredTranslations($rowId, 'name');
        ksort($storedNames);
        $this->assertSame(
            ['de::FORMAL' => 'a', 'en::FORMAL' => 'b', 'fr::FORMAL' => 'c'],
            $storedNames,
            'precondition: the merge landed'
        );
        $this->assertSame('c', $this->loadTranslatedRow($rowId)->name, 'fr is ranked before de, whatever the key order');
    }

    public function testTheSameTranslationsInAnotherStoredOrderGiveTheSameFallback(): void
    {
        Translatable::setCurrentLanguageCode('es');
        $documentOrderRowId = $this->insertTranslatedRow('{"de::FORMAL":"a","en::FORMAL":"b","fr::FORMAL":"c"}');
        $otherOrderRowId = $this->insertTranslatedRow('{"fr::FORMAL":"c","en::FORMAL":"b","de::FORMAL":"a"}');

        $this->assertSame(
            $this->loadTranslatedRow($documentOrderRowId)->name,
            $this->loadTranslatedRow($otherOrderRowId)->name
        );
    }

    /** PostgreSQL joins the search column in key order, so a jsonb rewrite does not change it */
    public function testThePostgresSearchColumnIsJoinedInKeyOrder(): void
    {
        if (static::currentDriver() !== 'pdo_pgsql') {
            $this->markTestSkipped('MariaDB joins the search column in stored order; MariaDB writes keep that order');
        }
        $rowId = $this->insertTranslatedRow('{"fr::FORMAL":"c","de::FORMAL":"a"}');
        $this->assertSame('a | c', $this->fetchRow('TranslatedRows', $rowId)['virtualNameSearch']);

        $this->mergeIntoNameColumn($rowId, '{"en::FORMAL":"b"}');

        $this->assertSame('a | b | c', $this->fetchRow('TranslatedRows', $rowId)['virtualNameSearch']);
    }

    public function testUpdateOfALoadedEntityDoesNotStoreTheFallbackUnderTheCurrentLanguage(): void
    {
        $rowId = $this->insertTranslatedRow('{"de::FORMAL":"Brot"}');
        Translatable::setCurrentLanguageCode('en');
        $translatedRow = $this->loadTranslatedRow($rowId);
        $this->assertSame('Brot', $translatedRow->name, 'precondition: the scalar holds the German fallback');
        $translatedRow->title = 'Title';

        (new DBTranslatedRow())->update($translatedRow);

        $this->assertSame(['de::FORMAL' => 'Brot'], $this->fetchStoredTranslations($rowId, 'name'));
        $this->assertSame(['en::FORMAL' => 'Title'], $this->fetchStoredTranslations($rowId, 'title'));
    }

    public function testAPartialWriteOfALoadedEntityDoesNotStoreTheFallbackUnderTheCurrentLanguage(): void
    {
        $rowId = $this->insertTranslatedRow('{"de::FORMAL":"Brot"}');
        Translatable::setCurrentLanguageCode('en');
        $translatedRow = $this->loadTranslatedRow($rowId);

        (new DBTranslatedRow())->updatePartialIgnoringRights($translatedRow, 'name');

        $this->assertSame(['de::FORMAL' => 'Brot'], $this->fetchStoredTranslations($rowId, 'name'));
    }

    /** The update endpoint pattern: load, overwrite from the payload, write (the column is merged) */
    public function testTheOverwriteThenUpdatePathDoesNotStoreTheFallbackUnderTheCurrentLanguage(): void
    {
        $rowId = $this->insertTranslatedRow('{"de::FORMAL":"Brot"}');
        Translatable::setCurrentLanguageCode('en');
        $loadedRow = $this->loadTranslatedRow($rowId);
        $sentRow = new TranslatedRow();
        $sentRow->id = $rowId;
        $sentRow->title = 'Title';

        $loadedRow->overwritePropertiesFromOtherObject($sentRow);
        (new DBTranslatedRow())->update($loadedRow);

        $this->assertSame(['de::FORMAL' => 'Brot'], $this->fetchStoredTranslations($rowId, 'name'));
        $this->assertSame(['en::FORMAL' => 'Title'], $this->fetchStoredTranslations($rowId, 'title'));
    }

    public function testAValueSetAfterTheLoadIsStoredUnderTheCurrentLanguage(): void
    {
        $rowId = $this->insertTranslatedRow('{"de::FORMAL":"Brot"}');
        Translatable::setCurrentLanguageCode('en');
        $translatedRow = $this->loadTranslatedRow($rowId);
        $translatedRow->name = 'Bread';

        (new DBTranslatedRow())->update($translatedRow);

        $storedNames = $this->fetchStoredTranslations($rowId, 'name');
        ksort($storedNames);
        $this->assertSame(['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'], $storedNames);
    }

    /** A sent row as a request body hydrates it: a plain value next to the translation map */
    protected function hydrateSentRow(int $rowId, string $plainName, array $nameTranslations): TranslatedRow
    {
        $payload = (object)[
            'id' => $rowId,
            'name' => $plainName,
            'translationInfos' => (object)['translationsStore' => (object)['name' => (object)$nameTranslations]],
        ];
        $sentRow = new TranslatedRow();
        $sentRow->setPropertiesFromObject($payload);
        return $sentRow;
    }

    public function testUpdateOfAPayloadWithMapAndRepeatedPlainValueStoresOnlyTheMap(): void
    {
        $rowId = $this->insertTranslatedRow('{"de::FORMAL":"Brot"}');
        Translatable::setCurrentLanguageCode('de');
        $sentRow = $this->hydrateSentRow($rowId, 'Bread', ['en::FORMAL' => 'Bread']);

        (new DBTranslatedRow())->update($sentRow);

        $storedNames = $this->fetchStoredTranslations($rowId, 'name');
        ksort($storedNames);
        $this->assertSame(['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'], $storedNames);
    }

    public function testAPartialWriteOfAPayloadWithMapAndRepeatedPlainValueStoresOnlyTheMap(): void
    {
        $rowId = $this->insertTranslatedRow('{"de::FORMAL":"Brot"}');
        Translatable::setCurrentLanguageCode('de');
        $sentRow = $this->hydrateSentRow($rowId, 'Bread', ['en::FORMAL' => 'Bread']);

        (new DBTranslatedRow())->updatePartialIgnoringRights($sentRow, 'name');

        $storedNames = $this->fetchStoredTranslations($rowId, 'name');
        ksort($storedNames);
        $this->assertSame(['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'], $storedNames);
    }

    /** The update endpoint pattern with a map in the payload: load, overwrite, write (the column is merged) */
    public function testTheOverwriteThenUpdatePathWithAMapStoresOnlyTheMap(): void
    {
        $rowId = $this->insertTranslatedRow('{"de::FORMAL":"Brot"}');
        Translatable::setCurrentLanguageCode('de');
        $loadedRow = $this->loadTranslatedRow($rowId);
        $sentRow = $this->hydrateSentRow($rowId, 'Bread', ['en::FORMAL' => 'Bread']);

        $loadedRow->overwritePropertiesFromOtherObject($sentRow);
        (new DBTranslatedRow())->update($loadedRow);

        $storedNames = $this->fetchStoredTranslations($rowId, 'name');
        ksort($storedNames);
        $this->assertSame(['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'], $storedNames);
    }

    public function testInsideStoreOnlyWritesADifferentPlainValueNextToTheMapIsNotStored(): void
    {
        $rowId = $this->insertTranslatedRow('{"de::FORMAL":"Brot"}');
        Translatable::setCurrentLanguageCode('de');
        $loadedRow = $this->loadTranslatedRow($rowId);
        $sentRow = $this->hydrateSentRow($rowId, 'Brötchen', ['en::FORMAL' => 'Bread']);

        $loadedRow->overwritePropertiesFromOtherObject($sentRow);
        Translatable::withStoreOnlyWrites(static fn() => (new DBTranslatedRow())->update($loadedRow));

        $storedNames = $this->fetchStoredTranslations($rowId, 'name');
        ksort($storedNames);
        $this->assertSame(['de::FORMAL' => 'Brot', 'en::FORMAL' => 'Bread'], $storedNames);
    }

    /** Without the scope a plain value that differs from every text of the map is a new text, as before */
    public function testADifferentPlainValueNextToTheMapIsStoredUnderTheCurrentLanguageByDefault(): void
    {
        $rowId = $this->insertTranslatedRow('{"de::FORMAL":"Brot"}');
        Translatable::setCurrentLanguageCode('de');
        $sentRow = $this->hydrateSentRow($rowId, 'Brötchen', ['en::FORMAL' => 'Bread']);

        (new DBTranslatedRow())->update($sentRow);

        $storedNames = $this->fetchStoredTranslations($rowId, 'name');
        ksort($storedNames);
        $this->assertSame(['de::FORMAL' => 'Brötchen', 'en::FORMAL' => 'Bread'], $storedNames);
    }
}
