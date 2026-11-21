<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Domain\Base\Entities\Translatable\Fixtures\TranslatableFixtureRow;
use DDD\Tests\Domain\Base\Entities\Translatable\Fixtures\TranslatableFixtureRowRequestDto;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * A plain text sent for a Translatable property is stored under the current (account / request) language. A client
 * that edits another language names it with the Content-Language header; the text is then stored as that language's
 * translation.
 */
class TranslatableInputContentLanguageTest extends TranslatableTestCase
{
    protected function hydrateRow(object $payload): TranslatableFixtureRow
    {
        $translatableFixtureRow = new TranslatableFixtureRow();
        $translatableFixtureRow->setPropertiesFromObject($payload);
        return $translatableFixtureRow;
    }

    public function testWithoutAContentLanguageAPlainValueBelongsToTheCurrentLanguage(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = $this->hydrateRow((object)['name' => 'Bread']);

        $this->assertSame('Bread', $translatableFixtureRow->name);
        $this->assertSame(
            ['de::FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testAPlainValueIsStoredUnderTheContentLanguage(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = Translatable::withInputContentLanguage(
            'en',
            fn() => $this->hydrateRow((object)['name' => 'Bread', 'label' => 'plain'])
        );

        $this->assertFalse(isset($translatableFixtureRow->name), 'the scalar must not carry another language');
        $this->assertSame('plain', $translatableFixtureRow->label);
        $this->assertSame(
            ['en::FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
        $this->assertTrue($translatableFixtureRow->hasTranslationsInStoreForProperty('name'));
    }

    public function testAContentLanguageWithARegionNamesTheCountry(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = Translatable::withInputContentLanguage(
            'en-US',
            fn() => $this->hydrateRow((object)['name' => 'Bread'])
        );

        $this->assertSame(
            ['en:us:FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testTheCurrentLanguageAsContentLanguageKeepsTheScalar(): void
    {
        Translatable::setCurrentLanguageCode('en');
        $translatableFixtureRow = Translatable::withInputContentLanguage(
            'EN',
            fn() => $this->hydrateRow((object)['name' => 'Bread'])
        );

        $this->assertSame('Bread', $translatableFixtureRow->name);
        $this->assertSame(
            ['en::FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    /** The update endpoint pattern: load, overwrite from the payload, write */
    public function testAnOverwriteOfALoadedEntityKeepsTheCurrentLanguageUntouched(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $loadedRow = new TranslatableFixtureRow();
        $loadedRow->id = 3;
        $loadedRow->setTranslationsForProperty('name', ['de::FORMAL' => 'Brot']);
        $sentRow = Translatable::withInputContentLanguage(
            'en',
            fn() => $this->hydrateRow((object)['id' => 3, 'name' => 'Bread'])
        );

        $loadedRow->overwritePropertiesFromOtherObject($sentRow);

        $this->assertSame('Brot', $loadedRow->name);
        $persistedTranslations = $loadedRow->getTranslationInfos()->getTranslationsForProperty('name', true);
        $this->assertSame('Bread', $persistedTranslations['en::FORMAL'] ?? null);
        $this->assertNotSame('Bread', $persistedTranslations['de::FORMAL'] ?? null);
    }

    public function testATranslationMapInThePayloadIsKept(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $translatableFixtureRow = Translatable::withInputContentLanguage(
            'en',
            fn() => $this->hydrateRow((object)[
                'name' => 'Bread',
                'translationInfos' => (object)['translationsStore' => (object)['name' => (object)['fr::FORMAL' => 'Pain']]],
            ])
        );

        $this->assertSame(
            ['fr::FORMAL' => 'Pain', 'en::FORMAL' => 'Bread'],
            $translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testAnInvalidContentLanguageIsRefused(): void
    {
        foreach (['de-DE, en', 'english', 'e', 'en-USA'] as $contentLanguageTag) {
            try {
                Translatable::withInputContentLanguage($contentLanguageTag, static fn() => null);
                $this->fail("'$contentLanguageTag' must be refused");
            } catch (BadRequestException) {
                $this->assertNull(Translatable::getInputContentLanguageCode());
            }
        }
    }

    public function testTheScopeEndsAlsoWhenTheHydrationThrows(): void
    {
        try {
            Translatable::withInputContentLanguage('fr', static function (): void {
                throw new RuntimeException('hydration failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertNull(Translatable::getInputContentLanguageCode());
        $this->assertNull(Translatable::getInputContentCountryCode());
    }

    public function testARequestDtoHonoursTheContentLanguageHeader(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $request = Request::create(
            '/rows',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_CONTENT_LANGUAGE' => 'en'],
            content: json_encode(['translatableFixtureRow' => ['name' => 'Bread']])
        );

        $translatableFixtureRowRequestDto = new TranslatableFixtureRowRequestDto();
        $translatableFixtureRowRequestDto->setPropertiesFromRequest($request);

        $this->assertSame(
            ['en::FORMAL' => 'Bread'],
            $translatableFixtureRowRequestDto->translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
        $this->assertNull(Translatable::getInputContentLanguageCode());
    }

    public function testARequestDtoWithoutTheHeaderKeepsTheCurrentLanguage(): void
    {
        Translatable::setCurrentLanguageCode('de');
        $request = Request::create(
            '/rows',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['translatableFixtureRow' => ['name' => 'Brot']])
        );

        $translatableFixtureRowRequestDto = new TranslatableFixtureRowRequestDto();
        $translatableFixtureRowRequestDto->setPropertiesFromRequest($request);

        $this->assertSame(
            ['de::FORMAL' => 'Brot'],
            $translatableFixtureRowRequestDto->translatableFixtureRow->getTranslationInfos()->getTranslationsForProperty('name', true)
        );
    }

    public function testARequestDtoRefusesAnInvalidContentLanguageHeader(): void
    {
        $request = Request::create(
            '/rows',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_CONTENT_LANGUAGE' => 'de-DE, en'],
            content: json_encode(['translatableFixtureRow' => ['name' => 'Bread']])
        );

        $this->expectException(BadRequestException::class);
        (new TranslatableFixtureRowRequestDto())->setPropertiesFromRequest($request);
    }
}
