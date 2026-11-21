<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Tests\Domain\Base\Entities\Translatable\Fixtures\TranslatableFixtureRow;

/**
 * The fallback to "a present translation" must not depend on the key order of the stored JSON: PostgreSQL's jsonb
 * re-sorts the keys of every document it rewrites (by length, then bytes), so a first-key read changed after a merge.
 */
class TranslatableFallbackOrderTest extends TranslatableTestCase
{
    /** The same translations in stored order and in the order jsonb gives them back after a merge */
    public function testTheFallbackIsTheSameWhateverTheKeyOrder(): void
    {
        Translatable::$defaultLanguageCode = 'it';
        Translatable::$activeLanguageCodes = ['it', 'fr', 'de', 'en'];
        $storedOrder = ['de::FORMAL' => 'a', 'en::FORMAL' => 'b', 'fr::FORMAL' => 'c'];
        $reversedOrder = array_reverse($storedOrder, true);

        $this->assertSame('c', Translatable::getFallbackTranslationFromTranslations($storedOrder));
        $this->assertSame('c', Translatable::getFallbackTranslationFromTranslations($reversedOrder));
    }

    public function testTheDefaultLanguageComesFirstThenTheActiveLanguagesInTheirConfiguredOrder(): void
    {
        Translatable::$defaultLanguageCode = 'en';
        Translatable::$activeLanguageCodes = ['en', 'fr', 'de'];

        $this->assertSame('english', Translatable::getFallbackTranslationFromTranslations(
            ['de::FORMAL' => 'german', 'fr::FORMAL' => 'french', 'en::INFORMAL' => 'english']
        ));
        $this->assertSame('french', Translatable::getFallbackTranslationFromTranslations(
            ['de::FORMAL' => 'german', 'fr::FORMAL' => 'french']
        ));
        // languages that are neither default nor active come last, by key
        $this->assertSame('german', Translatable::getFallbackTranslationFromTranslations(
            ['nl::FORMAL' => 'dutch', 'de::FORMAL' => 'german', 'es::FORMAL' => 'spanish']
        ));
        $this->assertSame('spanish', Translatable::getFallbackTranslationFromTranslations(
            ['nl::FORMAL' => 'dutch', 'es::FORMAL' => 'spanish']
        ));
    }

    public function testWithinOneLanguageTheKeyDecidesAndEmptyValuesAreSkipped(): void
    {
        Translatable::$defaultLanguageCode = 'de';
        $this->assertSame('formal', Translatable::getFallbackTranslationFromTranslations(
            ['de:at:FORMAL' => 'austrian', 'de::INFORMAL' => 'informal', 'de::FORMAL' => 'formal']
        ));
        $this->assertSame('informal', Translatable::getFallbackTranslationFromTranslations(
            ['de::FORMAL' => '', 'de::INFORMAL' => 'informal']
        ));
        $this->assertNull(Translatable::getFallbackTranslationFromTranslations([]));
    }

    /** The repro of the report: the read of a language that has no translation, before and after a merge */
    public function testAnEntityReadsTheSameFallbackBeforeAndAfterAMergeReorderedTheKeys(): void
    {
        Translatable::$defaultLanguageCode = 'it';
        Translatable::$activeLanguageCodes = ['it', 'fr', 'de', 'en'];
        Translatable::setCurrentLanguageCode('es');

        $beforeMerge = new TranslatableFixtureRow();
        $beforeMerge->setTranslationsForProperty('name', ['de::FORMAL' => 'a', 'en::FORMAL' => 'b']);
        // the merge of {"fr::FORMAL":"c"}: appended in document order, re-sorted by jsonb
        $afterMergeInDocumentOrder = new TranslatableFixtureRow();
        $afterMergeInDocumentOrder->setTranslationsForProperty('name', ['de::FORMAL' => 'a', 'en::FORMAL' => 'b', 'fr::FORMAL' => 'c']);
        $afterMergeThroughJsonb = new TranslatableFixtureRow();
        $afterMergeThroughJsonb->setTranslationsForProperty('name', ['fr::FORMAL' => 'c', 'en::FORMAL' => 'b', 'de::FORMAL' => 'a']);

        $this->assertSame('a', $beforeMerge->name);
        $this->assertSame('c', $afterMergeInDocumentOrder->name);
        $this->assertSame($afterMergeInDocumentOrder->name, $afterMergeThroughJsonb->name);
    }

    public function testTheDefaultLanguageFallbackStillComesBeforeTheOrderedFallback(): void
    {
        Translatable::setCurrentLanguageCode('es');
        $translatableFixtureRow = new TranslatableFixtureRow();
        $translatableFixtureRow->setTranslationsForProperty('name', ['fr::FORMAL' => 'c', 'en::FORMAL' => 'b']);

        $this->assertSame('b', $translatableFixtureRow->name);
    }

    public function testTranslateKeyFallsBackInTheSameOrder(): void
    {
        Translatable::$defaultLanguageCode = 'it';
        Translatable::$activeLanguageCodes = ['it', 'de', 'fr'];
        $translatableService = (new \ReflectionMethod(Translatable::class, 'getTranslatableService'))->invoke(null);

        $this->assertSame('german', $translatableService->getFallbackTranslationFromTranslations(
            ['fr::FORMAL' => 'french', 'de::FORMAL' => 'german']
        ));
    }
}
