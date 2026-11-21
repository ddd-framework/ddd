<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Translatable;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Database\FullTextMode;
use DDD\Domain\Base\Services\TranslatableService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Validation\Constraints\Choice;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Translatable extends ValueObject
{
    use BaseAttributeTrait;

    public const string WRITING_STYLE_FORMAL = 'FORMAL';

    public const string WRITING_STYLE_INFORMAL = 'INFORMAL';

    public const string DEFAULT_WRITING_STYLE = self::WRITING_STYLE_FORMAL;

    public const string DEFAULT_LANGUAGE_CODE = 'en';

    /** @var string Translation context of a count of one: the singular value of a singular/plural key */
    public const string CONTEXT_ONE = 'ONE';

    /** @var string Translation context of any count but one: the plural value of a singular/plural key */
    public const string CONTEXT_MANY = 'MANY';

    /** @var string */
    public static string $currentLanguageCode;

    /** @var string|null */
    public static ?string $currentCountryCode;

    /** @var string[] Active language codes */
    public static array $activeLanguageCodes;

    /** @var string[] Active locales codes */
    public static array $activeLocales;

    /** @var string */
    #[Choice([self::WRITING_STYLE_FORMAL, self::WRITING_STYLE_INFORMAL])]
    public static string $currentWritingStyle;

    /** @var string */
    public static string $defaultLanguageCode;

    /** @var string */
    #[Choice([self::WRITING_STYLE_FORMAL, self::WRITING_STYLE_INFORMAL])]
    public static string $defaultWritingStyle;

    /** @var bool */
    public static bool $fallbackToDefaultLanguageCode;

    /** @var bool */
    public static bool $fallbackToNativeValue;

    /** @var array|null Snapshot of translation settings */
    protected static ?array $translationSettingsSnapshot = null;

    /** @var string|null Language of the plain values of Translatable properties in the input being hydrated */
    protected static ?string $inputContentLanguageCode = null;

    /** @var string|null Country of the plain values of Translatable properties in the input being hydrated */
    protected static ?string $inputContentCountryCode = null;

    /** @var bool True inside {@see withStoreOnlyWrites()}: a write ignores the scalar of every Translatable property
     *  whose translation keys were set in the store for the save */
    protected static bool $storeOnlyWrites = false;

    /**
     * If enabled, the database model generator creates a stored virtual search column `virtual{Field}Search`
     * and a FULLTEXT index on it.
     */
    public bool $fullTextIndex = false;

    /**
     * D14: which PostgreSQL fulltext rendering `fullTextIndex: true` gets (no effect on MariaDB). Default `NAME`
     * (short text, pg_trgm prefix search); `DOCUMENT` for long text (to_tsvector + websearch_to_tsquery).
     */
    public FullTextMode $fullTextMode = FullTextMode::NAME;

    public function __construct(bool $fullTextIndex = false, FullTextMode $fullTextMode = FullTextMode::NAME)
    {
        $this->fullTextIndex = $fullTextIndex;
        $this->fullTextMode = $fullTextMode;
        parent::__construct();
    }

    /**
     * Returns the stored virtual column name used for FULLTEXT indexing of JSON-backed translatable fields.
     */
    public static function getFullTextSearchVirtualColumnName(string $propertyName): string
    {
        return 'virtual' . ucfirst($propertyName) . 'Search';
    }

    /**
     * A new worker job starts on the configured translation defaults with no snapshot: the previous job's language,
     * country and writing style, or a snapshot it never restored, must not render the next job's texts. Called by
     * the handler base's job reset only.
     */
    public static function resetToDefaultsForNewJob(): void
    {
        self::$translationSettingsSnapshot = null;
        self::$inputContentLanguageCode = null;
        self::$inputContentCountryCode = null;
        self::$storeOnlyWrites = false;
        static::setCurrentLanguageCode(static::getDefaultLanguageCode());
        static::setCurrentWritingStyle(static::getDefaultWritingStyle());
        static::setCurrentCountryCode(null);
    }

    /**
     * Starts a hydration scope in which a plain text sent for a Translatable property is the translation of the given
     * language, not of the current one: {@see TranslatableTrait::applyInputContentLanguageToTranslatableProperties()}
     * moves such a value into the translation store under `<language>:<country>:<current writing style>`. A request
     * DTO uses it for its body hydration when the request carries a `Content-Language` header. Nestable: restore the
     * returned previous scope with {@see restoreInputContentLanguage()} in `finally`.
     *
     * ```php
     * $previousContentLanguage = Translatable::enableInputContentLanguage('en-US');
     * try {
     *     $dto->setPropertiesFromObject($body);
     * } finally {
     *     Translatable::restoreInputContentLanguage($previousContentLanguage);
     * }
     * ```
     *
     * @param string $contentLanguageTag One language tag: `en`, or with a region `en-US` / `en_US`
     * @return string|null The previous scope as a tag (`en`, `en-us`), null when none was active
     * @throws BadRequestException When the tag is not one language tag of that form
     */
    public static function enableInputContentLanguage(string $contentLanguageTag): ?string
    {
        if (!preg_match('/^\s*([a-zA-Z]{2})(?:[-_]([a-zA-Z]{2}))?\s*$/', $contentLanguageTag, $matches)) {
            throw new BadRequestException(
                "Content-Language '$contentLanguageTag' is not supported: one language tag is expected, a two-letter "
                . 'language code with an optional two-letter region (en, en-US)'
            );
        }
        $previousContentLanguageTag = self::getInputContentLanguageTag();
        self::$inputContentLanguageCode = strtolower($matches[1]);
        self::$inputContentCountryCode = isset($matches[2]) && $matches[2] !== '' ? strtolower($matches[2]) : null;
        return $previousContentLanguageTag;
    }

    /**
     * Restores the input content language scope {@see enableInputContentLanguage()} returned (null: no scope).
     *
     * @param string|null $previousContentLanguageTag The tag returned by {@see enableInputContentLanguage()}
     * @return void
     */
    public static function restoreInputContentLanguage(?string $previousContentLanguageTag): void
    {
        if ($previousContentLanguageTag === null) {
            self::$inputContentLanguageCode = null;
            self::$inputContentCountryCode = null;
            return;
        }
        self::enableInputContentLanguage($previousContentLanguageTag);
    }

    /** The active input content language as a tag (`en`, `en-us`), null outside such a scope */
    public static function getInputContentLanguageTag(): ?string
    {
        if (self::$inputContentLanguageCode === null) {
            return null;
        }
        return self::$inputContentLanguageCode . (self::$inputContentCountryCode !== null ? '-' . self::$inputContentCountryCode : '');
    }

    /**
     * Convenience form of {@see enableInputContentLanguage()} / {@see restoreInputContentLanguage()}: runs $hydration
     * in the scope and restores the previous one afterwards, also when it throws.
     *
     * @param string $contentLanguageTag One language tag: `en`, or with a region `en-US` / `en_US`
     * @param callable $hydration The hydration to run in the scope
     * @return mixed The hydration's return value
     * @throws BadRequestException When the tag is not one language tag of that form
     */
    public static function withInputContentLanguage(string $contentLanguageTag, callable $hydration): mixed
    {
        $previousContentLanguageTag = self::enableInputContentLanguage($contentLanguageTag);
        try {
            return $hydration();
        } finally {
            self::restoreInputContentLanguage($previousContentLanguageTag);
        }
    }

    /**
     * Starts a scope of writes in which a Translatable property whose translation keys were set in the store for the
     * save (by a payload or by code: {@see TranslationInfos::setTranslationForProperty()},
     * {@see TranslationInfos::setTranslationsForProperty()}) is written from the store only: its plain scalar value is
     * ignored, never added under the current language ({@see TranslationInfos::isScalarValueIgnoredForPersistence()}).
     * Outside the scope only a scalar that repeats one of the texts of a payload's keys is ignored. Nestable: restore
     * the returned previous state with {@see restoreStoreOnlyWrites()} in `finally`.
     *
     * ```php
     * $storeOnlyWritesBefore = Translatable::enableStoreOnlyWrites();
     * try {
     *     $campaign->update();
     * } finally {
     *     Translatable::restoreStoreOnlyWrites($storeOnlyWritesBefore);
     * }
     * ```
     *
     * @return bool The previous state
     */
    public static function enableStoreOnlyWrites(): bool
    {
        $storeOnlyWritesBefore = self::$storeOnlyWrites;
        self::$storeOnlyWrites = true;
        return $storeOnlyWritesBefore;
    }

    /**
     * Restores the state {@see enableStoreOnlyWrites()} returned.
     *
     * @param bool $storeOnlyWritesBefore The state returned by {@see enableStoreOnlyWrites()}
     * @return void
     */
    public static function restoreStoreOnlyWrites(bool $storeOnlyWritesBefore): void
    {
        self::$storeOnlyWrites = $storeOnlyWritesBefore;
    }

    /**
     * Convenience form of {@see enableStoreOnlyWrites()} / {@see restoreStoreOnlyWrites()}: runs $write in the scope
     * and restores the previous state afterwards, also when a write throws.
     *
     * @param callable $write The writes to run in the scope (update(), updatePartialIgnoringRights(), …)
     * @return mixed The return value of $write
     */
    public static function withStoreOnlyWrites(callable $write): mixed
    {
        $storeOnlyWritesBefore = self::enableStoreOnlyWrites();
        try {
            return $write();
        } finally {
            self::restoreStoreOnlyWrites($storeOnlyWritesBefore);
        }
    }

    /** True inside {@see enableStoreOnlyWrites()} / {@see withStoreOnlyWrites()} */
    public static function isStoreOnlyWritesActive(): bool
    {
        return self::$storeOnlyWrites;
    }

    /** The language of plain Translatable values in the input being hydrated, null outside such a hydration */
    public static function getInputContentLanguageCode(): ?string
    {
        return self::$inputContentLanguageCode;
    }

    /** The country of plain Translatable values in the input being hydrated, null when none was named */
    public static function getInputContentCountryCode(): ?string
    {
        return self::$inputContentCountryCode;
    }

    public static function setTranslationSettingsSnapshot(): void
    {
        if (self::$translationSettingsSnapshot !== null) {
            return;
        }
        self::$translationSettingsSnapshot = [
            'writingStyle' => static::getCurrentWritingStyle(),
            'languageCode' => static::getCurrentLanguageCode(),
            'countryCode' => static::getCurrentCountryCode()
        ];
    }

    public static function getCurrentWritingStyle(): string
    {
        return static::getTranslatableService()->getCurrentWritingStyle();
    }

    public static function setCurrentWritingStyle(string $writingStyle): void
    {
        self::$currentWritingStyle = $writingStyle;
    }

    protected static function getTranslatableService(): TranslatableService
    {
        /** @var TranslatableService $translatableService */
        $translatableService = DDDService::instance()->getService(TranslatableService::class);
        return $translatableService;
    }

    public static function getCurrentLanguageCode(): string
    {
        return static::getTranslatableService()->getCurrentLanguageCode();
    }

    public static function setCurrentLanguageCode(string $languageCode): void
    {
        self::$currentLanguageCode = strtolower($languageCode);
    }

    public static function getCurrentCountryCode(): ?string
    {
        return static::getTranslatableService()->getCurrentCountryCode();
    }

    public static function setCurrentCountryCode(?string $countryCode): void
    {
        self::$currentCountryCode = $countryCode !== null ? strtolower($countryCode) : null;
    }

    public static function restoreTranslationSettingsSnapshot(): void
    {
        if (!self::$translationSettingsSnapshot) {
            return;
        }
        if (isset(self::$translationSettingsSnapshot['writingStyle']) && self::$translationSettingsSnapshot['writingStyle']) {
            static::setCurrentWritingStyle(self::$translationSettingsSnapshot['writingStyle']);
        }
        if (isset(self::$translationSettingsSnapshot['languageCode']) && self::$translationSettingsSnapshot['languageCode']) {
            static::setCurrentLanguageCode(self::$translationSettingsSnapshot['languageCode']);
        }
        if (isset(self::$translationSettingsSnapshot['countryCode']) && self::$translationSettingsSnapshot['countryCode']) {
            static::setCurrentCountryCode(self::$translationSettingsSnapshot['countryCode']);
        }
        self::$translationSettingsSnapshot = null;
    }

    public static function fallbackToDefaultLanguageIfNoTranslationIsPresent(): bool
    {
        return static::getTranslatableService()->fallbackToDefaultLanguageIfNoTranslationIsPresent();
    }

    public static function fallbackToNativeValueIfNoTranslationIsPresent(): bool
    {
        return static::getTranslatableService()->fallbackToNativeValueIfNoTranslationIsPresent();
    }

    public static function getDefaultLanguageCode(): string
    {
        return static::getTranslatableService()->getDefaultLanguageCode();
    }

    /**
     * Returns the set of active locales as an EntitySet.
     * @return EntitySet|null
     */
    public static function getActiveLocalesSet(): ?EntitySet
    {
        return static::getTranslatableService()->getActiveLocalesSet();
    }

    public static function getActiveLanguageCodes(): array
    {
        return static::getTranslatableService()->getActiveLanguageCodes();
    }

    public static function getActiveLocales(): array
    {
        return static::getTranslatableService()->getActiveLocales();
    }

    public static function isSupportedLanguageCode(string $languageCode): bool
    {
        return static::getTranslatableService()->isSupportedLanguageCode($languageCode);
    }

    /**
     * The translation to fall back to when none of the requested language exists, in a fixed order that does not
     * depend on the stored key order ({@see TranslatableService::getFallbackTranslationFromTranslations()}).
     *
     * @param array $translations Translation index => text
     */
    public static function getFallbackTranslationFromTranslations(array $translations): ?string
    {
        return static::getTranslatableService()->getFallbackTranslationFromTranslations($translations);
    }

    public static function getDefaultWritingStyle(): string
    {
        return static::getTranslatableService()->getDefaultWritingStyle();
    }

    public static function getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null
    ): string {
        return static::getTranslatableService()->getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
            $languageCode,
            $countryCode,
            $writingStyle
        );
    }

    public static function translateKey(
        string $translationKey,
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null,
        array $placeholders = []
    ): string {
        return static::getTranslatableService()->translateKey(
            $translationKey,
            $languageCode,
            $countryCode,
            $writingStyle,
            $placeholders
        );
    }

    /**
     * Replaces placeholders in the input string with values from an associative array.
     * Placeholders are expected to be in the format %varname%.
     */
    public static function replacePlaceholders(string $input, array $placeholders): string
    {
        foreach ($placeholders as $placeholder => $value) {
            $input = str_replace("%$placeholder%", (string)$value, $input);
        }
        return $input;
    }

    public static function setTranslationInfosVisibility(bool $visible): void
    {
        if ($visible) {
            DefaultObject::removeStaticPropertiesToHide(false, 'translationInfos');
        } else {
            DefaultObject::addStaticPropertiesToHide(false, 'translationInfos');
        }
    }
}
