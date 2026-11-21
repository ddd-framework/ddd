<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Services;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;

/**
 * Service encapsulating translatable logic.
 * All methods previously implemented as static in Translatable are delegated here,
 * so they can be overridden in application-level service configuration.
 */
class TranslatableService extends Service
{
    /** @var EntitySet|null Cached active locales set */
    protected ?EntitySet $activeLocalesSet = null;

    /**
     * Checks if the system should fallback to the default language if no translation is present.
     */
    public function fallbackToDefaultLanguageIfNoTranslationIsPresent(): bool
    {
        if (isset(Translatable::$fallbackToDefaultLanguageCode)) {
            return Translatable::$fallbackToDefaultLanguageCode;
        }
        $fallbackToDefaultLanguageCode = Config::getEnv('TRANSLATABLE_FALLBACK_TO_DEFAULT_LANGUAGE_CODE');
        if (isset($fallbackToDefaultLanguageCode)) {
            Translatable::$fallbackToDefaultLanguageCode = $fallbackToDefaultLanguageCode;
        } else {
            Translatable::$fallbackToDefaultLanguageCode = false;
        }
        return Translatable::$fallbackToDefaultLanguageCode;
    }

    /**
     * Checks if the system should fallback to the first available (native) translation value.
     */
    public function fallbackToNativeValueIfNoTranslationIsPresent(): bool
    {
        if (isset(Translatable::$fallbackToNativeValue)) {
            return Translatable::$fallbackToNativeValue;
        }
        $fallbackToNativeValue = Config::getEnv('TRANSLATABLE_FALLBACK_TO_NATIVE_VALUE');
        if (isset($fallbackToNativeValue)) {
            Translatable::$fallbackToNativeValue = $fallbackToNativeValue;
        } else {
            Translatable::$fallbackToNativeValue = false;
        }
        return Translatable::$fallbackToNativeValue;
    }

    /**
     * Gets the default language code.
     */
    public function getDefaultLanguageCode(): string
    {
        if (isset(Translatable::$defaultLanguageCode)) {
            return Translatable::$defaultLanguageCode;
        }
        if ($defaultLanguageCode = Config::getEnv('TRANSLATABLE_DEFAULT_LANGUAGE_CODE')) {
            Translatable::$defaultLanguageCode = $defaultLanguageCode;
        } else {
            Translatable::$defaultLanguageCode = Translatable::DEFAULT_LANGUAGE_CODE;
        }
        return Translatable::$defaultLanguageCode;
    }

    /**
     * Returns active language codes.
     * @return string[]
     */
    public function getActiveLanguageCodes(): array
    {
        if (isset(Translatable::$activeLanguageCodes)) {
            return Translatable::$activeLanguageCodes;
        }
        if ($activeLanguageCodes = Config::getEnv('TRANSLATABLE_ACTIVE_LANGUAGE_CODES')) {
            Translatable::$activeLanguageCodes = explode(',', $activeLanguageCodes);
            return Translatable::$activeLanguageCodes;
        }
        // not configured: no active language codes (reading the uninitialized static raised an Error)
        return [];
    }

    /**
     * Returns active locales.
     * @return string[]
     */
    public function getActiveLocales(): array
    {
        if (isset(Translatable::$activeLocales)) {
            return Translatable::$activeLocales;
        }
        if ($activeLocales = Config::getEnv('TRANSLATABLE_ACTIVE_LOCALES')) {
            Translatable::$activeLocales = explode(',', $activeLocales);
            return Translatable::$activeLocales;
        }
        // not configured: no active locales (reading the uninitialized static raised an Error)
        return [];
    }

    /**
     * Returns the set of active locales as an EntitySet, built from the TRANSLATABLE_ACTIVE_LOCALES env config.
     * Cached after first call. Override in application-level service if Locale entity differs.
     * @return EntitySet|null
     */
    public function getActiveLocalesSet(): ?EntitySet
    {
        return $this->activeLocalesSet;
    }

    /**
     * Returns true if given language code is in activeLanguageCodes.
     */
    public function isSupportedLanguageCode(string $languageCode): bool
    {
        return in_array($languageCode, $this->getActiveLanguageCodes());
    }

    /**
     * Retrieves the default writing style.
     */
    public function getDefaultWritingStyle(): string
    {
        if (isset(Translatable::$defaultWritingStyle)) {
            return Translatable::$defaultWritingStyle;
        }
        if ($defaultWritingStyle = Config::getEnv('TRANSLATABLE_DEFAULT_WRITING_STYLE')) {
            Translatable::$defaultWritingStyle = $defaultWritingStyle;
        } else {
            Translatable::$defaultWritingStyle = Translatable::DEFAULT_WRITING_STYLE;
        }
        return Translatable::$defaultWritingStyle;
    }

    /**
     * Returns the current language code.
     */
    public function getCurrentLanguageCode(): string
    {
        if (isset(Translatable::$currentLanguageCode)) {
            return Translatable::$currentLanguageCode;
        }
        Translatable::$currentLanguageCode = $this->getDefaultLanguageCode();
        return Translatable::$currentLanguageCode;
    }

    /**
     * Retrieves the current writing style.
     */
    public function getCurrentWritingStyle(): string
    {
        if (isset(Translatable::$currentWritingStyle)) {
            return Translatable::$currentWritingStyle;
        }
        Translatable::$currentWritingStyle = $this->getDefaultWritingStyle();
        return Translatable::$currentWritingStyle;
    }

    /**
     * Retrieves the current country code.
     */
    public function getCurrentCountryCode(): ?string
    {
        if (isset(Translatable::$currentCountryCode)) {
            return Translatable::$currentCountryCode;
        }
        return null;
    }

    /**
     * Returns the translation index for given params.
     */
    public function getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null
    ): string {
        $languageCode = strtolower($languageCode ?? $this->getCurrentLanguageCode());
        $countryCode = strtolower($countryCode ?? ($this->getCurrentCountryCode() ?? ''));
        $writingStyle = $writingStyle ?? $this->getCurrentWritingStyle();
        return $languageCode . ':' . $countryCode . ':' . $writingStyle;
    }

    /**
     * The translation a reader falls back to when the requested language (and the default language, if that fallback
     * is enabled) has none: the first non-empty value in the order of {@see sortTranslationIndexesForFallback()}.
     * Never the first key of the stored map: the key order of a JSON document is not stable (PostgreSQL's jsonb
     * sorts the keys of every document it rewrites), so a "first available" read changed after a merge.
     *
     * @param array $translations Translation index (`<language>:<country>:<writingStyle>`) => text
     * @return string|null The fallback text, null when no translation is present
     */
    public function getFallbackTranslationFromTranslations(array $translations): ?string
    {
        $translationIndexes = array_map('strval', array_keys($translations));
        foreach ($this->sortTranslationIndexesForFallback($translationIndexes) as $translationIndex) {
            $translation = $translations[$translationIndex] ?? null;
            if (is_string($translation) && $translation !== '') {
                return $translation;
            }
        }
        return null;
    }

    /**
     * The fixed order in which translations are tried as a fallback, independent of the order they are stored in:
     * the default language first, then the active language codes in their configured order
     * (TRANSLATABLE_ACTIVE_LANGUAGE_CODES), then every other language; within one rank by the translation index in
     * byte order (so `en::FORMAL` before `en::INFORMAL` before `en:us:FORMAL`).
     *
     * @param string[] $translationIndexes Translation indexes (`<language>:<country>:<writingStyle>`)
     * @return string[] The same indexes in fallback order
     */
    public function sortTranslationIndexesForFallback(array $translationIndexes): array
    {
        $languageRanks = [];
        foreach ([$this->getDefaultLanguageCode(), ...$this->getActiveLanguageCodes()] as $languageCode) {
            $languageCode = strtolower(trim((string)$languageCode));
            if ($languageCode !== '' && !isset($languageRanks[$languageCode])) {
                $languageRanks[$languageCode] = count($languageRanks);
            }
        }
        $unrankedLanguageRank = count($languageRanks);
        usort(
            $translationIndexes,
            static function (string $firstIndex, string $secondIndex) use ($languageRanks, $unrankedLanguageRank): int {
                $firstRank = $languageRanks[strtolower(explode(':', $firstIndex, 2)[0])] ?? $unrankedLanguageRank;
                $secondRank = $languageRanks[strtolower(explode(':', $secondIndex, 2)[0])] ?? $unrankedLanguageRank;
                return ($firstRank <=> $secondRank) ?: strcmp($firstIndex, $secondIndex);
            }
        );
        return $translationIndexes;
    }

    /**
     * Translates the given translation key.
     * @param string $translationKey
     * @param string|null $languageCode
     * @param string|null $countryCode
     * @param string|null $writingStyle
     * @param array $placeholders
     * @return string
     */
    public function translateKey(
        string $translationKey,
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null,
        array $placeholders = []
    ): string {
        $translationsFromConfig = Config::get('Common.Translations');
        $index = $this->getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
            $languageCode,
            $countryCode,
            $writingStyle
        );
        $translation = $translationsFromConfig[$translationKey][$index] ?? null;
        // try to find translation with that is less specific
        if ($translation === null) {
            // if countryCode was given, we try without country code
            if ($countryCode) {
                $index = $this->getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                    $languageCode,
                    '',
                    $writingStyle
                );
                $translation = $translationsFromConfig[$translationKey][$index] ?? null;
                if ($translation) {
                    return Translatable::replacePlaceholders($translation, $placeholders);
                }
            }
            // try alternate writing style (without country code)
            if ($writingStyle) {
                $altWritingStyle = $writingStyle == Translatable::WRITING_STYLE_INFORMAL ? Translatable::WRITING_STYLE_FORMAL : Translatable::WRITING_STYLE_INFORMAL;
                $index = $this->getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                    $languageCode,
                    '',
                    $altWritingStyle
                );
                $translation = $translationsFromConfig[$translationKey][$index] ?? null;
                if ($translation) {
                    return Translatable::replacePlaceholders($translation, $placeholders);
                }
            }
        }
        // If no translation is found and we have set fallback to default language
        if (!$translation && $this->fallbackToDefaultLanguageIfNoTranslationIsPresent()) {
            $index = $this->getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                $this->getDefaultLanguageCode()
            );
            $translation = $translationsFromConfig[$translationKey][$index] ?? null;
            if ($translation) {
                return Translatable::replacePlaceholders($translation, $placeholders);
            }
        }
        // If still no translation found, fall back to a present translation, chosen in the fixed fallback order
        if (!$translation && $this->fallbackToNativeValueIfNoTranslationIsPresent()) {
            $translation = $this->getFallbackTranslationFromTranslations($translationsFromConfig[$translationKey] ?? []);
            if ($translation) {
                return Translatable::replacePlaceholders($translation, $placeholders);
            }
        }
        // if nothing is found, return key itself
        if (!$translation) {
            $translation = $translationKey;
        }
        return Translatable::replacePlaceholders($translation, $placeholders);
    }
}
