<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

/**
 * The PostgreSQL text search configurations the installer creates
 * (`app:db:postgres:install-extensions-collation-functions`): each folds accents (`unaccent`) before its dictionary,
 * so `Größe` and `grosse` meet. `ddd_simple` only folds; `ddd_german` and `ddd_english` also stem (`Gewinncodes`
 * finds `Gewinncode`). A column that stores a search vector per row keeps the configuration of its content's language:
 * `to_tsvector(<configuration>, text)` and `websearch_to_tsquery(<configuration>, term)` with the same name.
 */
class PostgresTextSearchConfiguration
{
    public const string SIMPLE = 'ddd_simple';
    public const string GERMAN = 'ddd_german';
    public const string ENGLISH = 'ddd_english';

    /** Configuration => the built-in dictionary it applies after unaccent */
    public const array DICTIONARY_BY_CONFIGURATION = [
        self::SIMPLE => 'simple',
        self::GERMAN => 'german_stem',
        self::ENGLISH => 'english_stem',
    ];

    /** Language code (ISO 639-1) => configuration; every other language gets {@see self::SIMPLE} */
    public const array CONFIGURATION_BY_LANGUAGE_CODE = [
        'de' => self::GERMAN,
        'en' => self::ENGLISH,
    ];

    /** The configuration for content in a language (`de`, `de-AT`, `en_GB`); unknown or null: {@see self::SIMPLE} */
    public static function forLanguageCode(?string $languageCode): string
    {
        if ($languageCode === null || $languageCode === '') {
            return self::SIMPLE;
        }
        $baseLanguageCode = strtolower(substr(str_replace('_', '-', $languageCode), 0, 2));
        return self::CONFIGURATION_BY_LANGUAGE_CODE[$baseLanguageCode] ?? self::SIMPLE;
    }

    /** True for a configuration the installer creates; a caller interpolating a name into SQL checks it first */
    public static function isKnownConfiguration(string $configuration): bool
    {
        return isset(self::DICTIONARY_BY_CONFIGURATION[$configuration]);
    }
}
