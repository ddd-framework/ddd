<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * @property Entity $parent
 * @method Entity getParent()
 */
class TranslationInfos extends ValueObject
{
    /** Store keys of a property arrived with a payload (`translationInfos.translationsStore` of a request body, a
     *  message or another hydrated object) */
    public const string STORE_KEYS_SET_BY_PAYLOAD = 'PAYLOAD';

    /** Store keys of a property were set by code ({@see setTranslationForProperty()}, {@see setTranslationsForProperty()}) */
    public const string STORE_KEYS_SET_BY_CODE = 'CODE';

    /** @var array[] Stores translations applied with setter function */
    public array $translationsStore = [];

    /** @var bool If true, on persist the translationsStore fully replaces existing translations instead of JSON_MERGE_PATCH */
    public bool $replaceExistingTranslations = false;

    /**
     * Property name => the text this object put on the entity's scalar property from the store (the current
     * language's translation or a fallback). A scalar that still holds exactly that text was read, not set: it is
     * already in the store under its own index and is never written again under the current language. Copied by
     * clone() and kept by __serialize(); not part of the serialized output.
     *
     * @var string[]
     */
    protected array $scalarValuesResolvedFromStore = [];

    /**
     * Property name => who set translation keys of that property in the store since the load
     * (STORE_KEYS_SET_BY_PAYLOAD or STORE_KEYS_SET_BY_CODE; a payload wins). Keys put there by a load are not tracked.
     * Decides whether a write ignores the property's scalar ({@see isScalarValueIgnoredForPersistence()}). Copied by
     * clone() and kept by __serialize(); not part of the serialized output.
     *
     * @var string[]
     */
    protected array $storeKeysSetBySourceByPropertyName = [];

    /**
     * The current language code.
     * @var string
     */
    public string $currentLanguageCode;

    /**
     * The current country code of the application.
     * @var string
     */
    public ?string $currentCountryCode;

    /**
     * The current writing style used in the application.
     * @var string
     */
    #[Choice([Translatable::WRITING_STYLE_FORMAL, Translatable::WRITING_STYLE_INFORMAL])]
    public string $currentWritingStyle;

    /**
     * Default language code.
     *
     * This variable stores the default language code that will be used when no language code is provided.
     * @var string
     */
    public string $defaultLanguageCode;

    /**
     * The default writing style used in the application.
     * @var string
     */
    #[Choice([Translatable::WRITING_STYLE_FORMAL, Translatable::WRITING_STYLE_INFORMAL])]
    public string $defaultWritingStyle;

    /**
     * Array that stores the available language codes, locales or locales + writing styles for which translations exist
     * Possible formats are:
     * <languageCode> => true
     * <languageCode>::<wrintingStyle> => true
     * <locale> => true
     * <locale>::<wrintingStyle> => true
     * @var array
     */
    public array $availableTranslations = [];

    /**
     * Indicates whether the entity has a translation for the current language code.
     * @var bool
     */
    public bool $hasTranslationForCurrentLanguageCode = false;

    /**
     * Indicates whether the entity has a translation for the current country code
     * @var bool
     */
    public bool $hasTranslationForCurrentCountryCode = false;

    /**
     * Indicates whether the entity has a translation for the writing style.
     * @var bool
     */
    public bool $hasTranslationForCurrentWritingStyle = false;

    /**
     * Retrieves the translations for a given property.
     * @param string $propertyName The name of the property to retrieve translations for.
     * @param bool $forPersistence If true, we are operating in context of mapping the values for persistance, in this case also the value of the property is taken into consideration
     * @return array|null An array of translations, or null if the property does not exist or has no translations.
     */
    public function getTranslationsForProperty(string $propertyName, bool $forPersistence = false): ?array
    {
        if (!$this->getParent()) {
            return null;
        }
        if (!property_exists($this->getParent(), $propertyName)) {
            return null;
        }
        $translations = [];
        if (!isset($this->getParent()->$propertyName)) {
            if (!isset($this->translationsStore[$propertyName])) {
                return null;
            }
            return $this->translationsStore[$propertyName];
        }
        // We are operating in context of mapping the values for persistance, in this case also the value of the property is taken into consideration
        // Otherwise not, as we want to have the values "as they are" and understand if a translation is missing or not
        if (isset($this->translationsStore[$propertyName])) {
            $translations = array_merge($translations, $this->translationsStore[$propertyName]);
        }
        if (
            $forPersistence
            && isset($this->getParent()->$propertyName)
            && $this->getParent()->$propertyName !== null
            && !$this->isScalarValueResolvedFromStore($propertyName)
            && !$this->isScalarValueIgnoredForPersistence($propertyName)
        ) {
            $translations[Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle()] = $this->getParent()->$propertyName;
        }
        return $translations;
    }

    /**
     * True when the entity's scalar property still holds the text this object resolved from the store (a translation
     * of the current language or a fallback from another language), i.e. it was read and not set since. Such a value
     * is never written under the current language: a fallback text would otherwise be stored as the current
     * language's translation by any write of a loaded entity.
     */
    public function isScalarValueResolvedFromStore(string $propertyName): bool
    {
        if (!array_key_exists($propertyName, $this->scalarValuesResolvedFromStore)) {
            return false;
        }
        $parent = $this->getParent();
        return $parent && isset($parent->$propertyName)
            && $parent->$propertyName === $this->scalarValuesResolvedFromStore[$propertyName];
    }

    /**
     * True when a write ignores the scalar property because translation keys of the property were set in the store
     * for this save — the keys say which language a text belongs to, the scalar would add it under the current
     * language as well:
     * - keys from a payload: the scalar is ignored when it equals one of the property's stored texts (a plain value
     *   sent next to the map, or an echoed fallback). A scalar that differs from every text is a new text and is
     *   written under the current language, as before.
     * - inside {@see Translatable::withStoreOnlyWrites()}: the scalar is ignored whenever keys were set, by a payload
     *   or by code.
     * Keys set by code outside that scope never suppress the scalar.
     */
    public function isScalarValueIgnoredForPersistence(string $propertyName): bool
    {
        $storeKeysSetBy = $this->storeKeysSetBySourceByPropertyName[$propertyName] ?? null;
        if ($storeKeysSetBy === null || empty($this->translationsStore[$propertyName])) {
            return false;
        }
        if (Translatable::isStoreOnlyWritesActive()) {
            return true;
        }
        if ($storeKeysSetBy !== self::STORE_KEYS_SET_BY_PAYLOAD) {
            return false;
        }
        $parent = $this->getParent();
        if (!$parent || !isset($parent->$propertyName)) {
            return false;
        }
        return in_array($parent->$propertyName, $this->translationsStore[$propertyName], true);
    }

    /**
     * Records that translation keys of the property were set in the store for this save. A payload source is kept
     * when code sets keys afterwards.
     *
     * @param string $propertyName The Translatable property
     * @param string $storeKeysSetBy STORE_KEYS_SET_BY_PAYLOAD or STORE_KEYS_SET_BY_CODE
     */
    public function markStoreKeysSetForProperty(string $propertyName, string $storeKeysSetBy): void
    {
        if (($this->storeKeysSetBySourceByPropertyName[$propertyName] ?? null) === self::STORE_KEYS_SET_BY_PAYLOAD) {
            return;
        }
        $this->storeKeysSetBySourceByPropertyName[$propertyName] = $storeKeysSetBy;
    }

    /** Who set translation keys of the property in the store since the load (STORE_KEYS_SET_BY_*), null if nobody */
    public function getStoreKeysSetBySourceForProperty(string $propertyName): ?string
    {
        return $this->storeKeysSetBySourceByPropertyName[$propertyName] ?? null;
    }

    /**
     * Forgets that translation keys of the property were set for this save (a repository does so after putting the
     * stored translations into the store): its scalar is written as without them.
     */
    public function forgetStoreKeysSetForProperty(string $propertyName): void
    {
        unset($this->storeKeysSetBySourceByPropertyName[$propertyName]);
    }

    /**
     * Hydrates this object; every property that the payload's `translationsStore` carries keys for is recorded as
     * set by a payload ({@see isScalarValueIgnoredForPersistence()}).
     */
    public function setPropertiesFromObject(
        object &$object,
        $throwErrors = true,
        bool $rootCall = true,
        bool $sanitizeInput = false
    ): void {
        parent::setPropertiesFromObject($object, $throwErrors, $rootCall, $sanitizeInput);
        if (!isset($object->translationsStore) || !(is_object($object->translationsStore) || is_array($object->translationsStore))) {
            return;
        }
        foreach ($object->translationsStore as $propertyName => $translations) {
            if ((is_array($translations) || is_object($translations)) && (array)$translations !== []) {
                $this->markStoreKeysSetForProperty((string)$propertyName, self::STORE_KEYS_SET_BY_PAYLOAD);
            }
        }
    }

    /**
     * Overwrites this object from another one (the merge of a sent entity into a loaded one) and takes over which
     * properties had translation keys set, so the write of the loaded entity treats its scalars as the sent one would.
     */
    public function overwritePropertiesFromOtherObject(
        ?DefaultObject $other,
        array $callPath = [],
        bool $cloneProperties = false
    ): DefaultObject {
        $result = parent::overwritePropertiesFromOtherObject($other, $callPath, $cloneProperties);
        if ($other instanceof self && $other !== $this) {
            foreach ($other->storeKeysSetBySourceByPropertyName as $propertyName => $storeKeysSetBy) {
                $this->markStoreKeysSetForProperty($propertyName, $storeKeysSetBy);
            }
        }
        return $result;
    }

    /**
     * Forgets that the scalar property holds a value resolved from the store: its value is written under the current
     * language on the next write, also when it equals the resolved text.
     */
    public function forgetScalarValueResolvedFromStore(string $propertyName): void
    {
        unset($this->scalarValuesResolvedFromStore[$propertyName]);
    }

    /**
     * Sets translations for a specific property.
     *
     * @param string $propertyName The name of the property to set translations for.
     * @param array $translations An associative array where the keys represent language codes and the values represent the translations.
     * @return void
     */
    public function setTranslationsForProperty(string $propertyName, array $translations): void
    {
        if (!$this->getParent()) {
            return;
        }
        if (!property_exists($this->getParent(), $propertyName)) {
            return;
        }
        $this->translationsStore[$propertyName] = $translations;
        if ($translations !== []) {
            $this->markStoreKeysSetForProperty($propertyName, self::STORE_KEYS_SET_BY_CODE);
        }
        $defaultTranslationWithFallback = $this->getTranslationForProperty(
            $propertyName,
            Translatable::getCurrentLanguageCode(),
            Translatable::getCurrentCountryCode(),
            Translatable::getCurrentWritingStyle(),
            useFallBack: true
        );
        if ($defaultTranslationWithFallback) {
            $this->getParent()->$propertyName = $defaultTranslationWithFallback;
            $this->scalarValuesResolvedFromStore[$propertyName] = $defaultTranslationWithFallback;
        } else {
            unset($this->scalarValuesResolvedFromStore[$propertyName]);
        }
    }

    /**
     * Sets the translation for a specific property.
     *
     * @param string $propertyName The name of the property to set the translation for.
     * @param string $translation The translation to set for the property.
     * @param string|null $languageCode The language code. Default is null.
     * @param string|null $countryCode The country code. Default is null.
     * @param string|null $writingStyle The writing style. Default is null.
     * @return void
     */
    public function setTranslationForProperty(
        string $propertyName,
        string $translation,
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null
    ): void {
        if (!$this->getParent()) {
            return;
        }
        if (!property_exists($this->getParent(), $propertyName)) {
            return;
        }
        // Normalize to lowercase and validate 2-letter codes
        if ($languageCode !== null) {
            $languageCode = strtolower($languageCode);
            if (!preg_match('/^[a-z]{2}$/', $languageCode)) {
                throw new BadRequestException("Invalid language code '$languageCode': must be a 2-letter ISO 639-1 code");
            }
        }
        // '' = explicitly no country (the language-wide translation); null = the current country
        if ($countryCode !== null && $countryCode !== '') {
            $countryCode = strtolower($countryCode);
            if (!preg_match('/^[a-z]{2}$/', $countryCode)) {
                throw new BadRequestException("Invalid country code '$countryCode': must be a 2-letter ISO 3166-1 alpha-2 code");
            }
        }
        $key = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
            $languageCode,
            $countryCode,
            $writingStyle
        );
        $this->translationsStore[$propertyName][$key] = $translation;
        $this->markStoreKeysSetForProperty($propertyName, self::STORE_KEYS_SET_BY_CODE);

        // if we are in the default state and all parameter matches, we also set the Entity's property to the translation value
        if (
            (!$languageCode || $languageCode == strtolower(Translatable::getCurrentLanguageCode() ?? '')
            ) && (!$countryCode || $countryCode == strtolower(Translatable::getCurrentCountryCode() ?? '')
            ) && (!$writingStyle || $writingStyle == Translatable::getCurrentWritingStyle())
        ) {
            $this->getParent()->$propertyName = $translation;
            // the scalar now mirrors the store entry just set; writing it again under the current index is redundant
            $this->scalarValuesResolvedFromStore[$propertyName] = $translation;
        }
    }

    /**
     * Retrieves the translation for a specific property.
     *
     * @param string $propertyName The name of the property to retrieve the translation for.
     * @param string|null $languageCode The language code. Default is null.
     * @param string|null $countryCode The country code. Default is null.
     * @param string|null $writingStyle The writing style. Default is null.
     * @param bool $useFallBack Set to true to use fallback translations. Default is false.
     * @return string|null The translation for the property, or null if no translation is found.
     */
    public function getTranslationForProperty(
        string $propertyName,
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null,
        bool $useFallBack = false
    ): ?string {
        if (!($this->getParent() && property_exists($this->getParent(), $propertyName))) {
            return null;
        }
        $key = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
            $languageCode,
            $countryCode,
            $writingStyle
        );
        $translation = $this->translationsStore[$propertyName][$key] ?? null;
        // try to find translation with that is less specific
        if ($translation === null && $useFallBack) {
            // if countryCode was given, we try without country code
            if ($countryCode) {
                $key = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                    $languageCode,
                    '',
                    $writingStyle
                );
                $translation = $this->translationsStore[$propertyName][$key] ?? null;
                if ($translation) {
                    return $translation;
                }
            }
            // try alternate writing style (without country code)
            if (!$translation && $writingStyle) {
                $altWritingStyle = $writingStyle == Translatable::WRITING_STYLE_INFORMAL ? Translatable::WRITING_STYLE_FORMAL : Translatable::WRITING_STYLE_INFORMAL;
                $key = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                    $languageCode,
                    '',
                    $altWritingStyle
                );
                $translation = $this->translationsStore[$propertyName][$key] ?? null;
                if ($translation) {
                    return $translation;
                }
            }
        }
        // If no translation is found and we have set fallback to default language, returns default language
        if (!$translation && Translatable::fallbackToDefaultLanguageIfNoTranslationIsPresent()) {
            $key = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                Translatable::getDefaultLanguageCode(), '',
            );
            $translation = $this->translationsStore[$propertyName][$key] ?? null;
            if ($translation) {
                return $translation;
            }
        }
        // If still no translation found, fall back to a present translation in the fixed fallback order (never the
        // first stored key: the key order of the stored JSON is not stable, see TranslatableService)
        if (!$translation && Translatable::fallbackToNativeValueIfNoTranslationIsPresent()) {
            $translation = Translatable::getFallbackTranslationFromTranslations($this->translationsStore[$propertyName] ?? []);
        }
        return $translation;
    }
}