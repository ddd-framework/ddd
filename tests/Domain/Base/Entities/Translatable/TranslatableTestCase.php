<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Entities\Translatable\Translatable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Sets the Translatable configuration a test needs (default language, active languages, fallback switches) and puts
 * the previous values back afterwards: they are process statics.
 */
abstract class TranslatableTestCase extends TestCase
{
    /** @var string[] Names of the Translatable statics a test changes */
    protected const array CONFIGURATION_PROPERTY_NAMES = [
        'defaultLanguageCode',
        'activeLanguageCodes',
        'fallbackToDefaultLanguageCode',
        'fallbackToNativeValue',
        'defaultWritingStyle',
    ];

    /** @var mixed[] Property name => value before the test; a name missing here was uninitialized */
    protected array $configurationBeforeTest = [];

    protected function setUp(): void
    {
        // initialize the statics from the configuration first, so the values put back are the configured ones
        Translatable::getDefaultLanguageCode();
        Translatable::getDefaultWritingStyle();
        Translatable::fallbackToDefaultLanguageIfNoTranslationIsPresent();
        Translatable::fallbackToNativeValueIfNoTranslationIsPresent();
        foreach (self::CONFIGURATION_PROPERTY_NAMES as $propertyName) {
            $reflectionProperty = new ReflectionProperty(Translatable::class, $propertyName);
            if ($reflectionProperty->isInitialized()) {
                $this->configurationBeforeTest[$propertyName] = $reflectionProperty->getValue();
            }
        }
        Translatable::$defaultLanguageCode = 'en';
        Translatable::$defaultWritingStyle = Translatable::WRITING_STYLE_FORMAL;
        Translatable::$activeLanguageCodes = ['en', 'de', 'fr'];
        Translatable::$fallbackToDefaultLanguageCode = true;
        Translatable::$fallbackToNativeValue = true;
        Translatable::resetToDefaultsForNewJob();
    }

    protected function tearDown(): void
    {
        foreach (self::CONFIGURATION_PROPERTY_NAMES as $propertyName) {
            if (array_key_exists($propertyName, $this->configurationBeforeTest)) {
                (new ReflectionProperty(Translatable::class, $propertyName))->setValue(null, $this->configurationBeforeTest[$propertyName]);
            }
        }
        // statics without a value before: the neutral values the service also answers when nothing is configured
        if (!array_key_exists('activeLanguageCodes', $this->configurationBeforeTest)) {
            Translatable::$activeLanguageCodes = [];
        }
        Translatable::resetToDefaultsForNewJob();
    }
}
