<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Libs;

use DDD\Infrastructure\Libs\Config;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** A config file and a folder of the same name side by side: the file never hides the folder */
class ConfigFileBesideFolderTest extends TestCase
{
    /** @var array<string, mixed> the static state before the test */
    protected array $stateBefore = [];

    protected string $applicationRoot;

    protected string $moduleRoot;

    protected function setUp(): void
    {
        foreach (['appRootDirectories', 'moduleRootDirectories', 'configTree', 'configNodeRootRanksByPath', 'configNodePathsWithoutOwnFile'] as $propertyName) {
            $property = new ReflectionProperty(Config::class, $propertyName);
            $this->stateBefore[$propertyName] = $property->isInitialized() ? $property->getValue() : null;
        }
        (new ReflectionProperty(Config::class, 'appRootDirectories'))->setValue(null, []);
        (new ReflectionProperty(Config::class, 'moduleRootDirectories'))->setValue(null, []);
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, []);

        $temporaryBase = sys_get_temp_dir() . '/ddd-config-file-beside-folder-' . getmypid() . '-' . uniqid();
        $this->applicationRoot = $temporaryBase . '/application';
        $this->moduleRoot = $temporaryBase . '/module';
        @mkdir($this->applicationRoot . '/Area', 0777, true);
        @mkdir($this->moduleRoot . '/Area', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->stateBefore as $propertyName => $value) {
            if ($value !== null) {
                (new ReflectionProperty(Config::class, $propertyName))->setValue(null, $value);
            }
        }
        $this->removeDirectory(dirname($this->applicationRoot));
    }

    public function testAFileAndAFolderInOneRootAreBothFound(): void
    {
        $this->writeConfigFile($this->applicationRoot . '/Area/Texts.php', ['language' => 'de']);
        $this->writeConfigFile($this->applicationRoot . '/Area/Texts/AppTexts.php', ['areas' => ['APP']]);
        $this->registerRoots();

        self::assertSame(['APP'], Config::get('Area.Texts.AppTexts.areas'));
        self::assertSame('de', Config::get('Area.Texts.language'));
        $node = Config::get('Area.Texts');
        self::assertSame('de', $node['language'], 'the node read after its folder entry still holds the file');
        self::assertSame(['areas' => ['APP']], $node['AppTexts']);
    }

    public function testAnApplicationFileBesideAModuleFolder(): void
    {
        $this->writeConfigFile($this->applicationRoot . '/Area/Texts.php', ['language' => 'de']);
        $this->writeConfigFile($this->moduleRoot . '/Area/Texts/AppTexts.php', ['areas' => ['SERVER']]);
        $this->registerRoots();

        self::assertSame('de', Config::get('Area.Texts.language'));
        self::assertSame(['SERVER'], Config::get('Area.Texts.AppTexts.areas'), 'the application file does not hide the module folder');
    }

    public function testAModuleFileBesideAnApplicationFolder(): void
    {
        $this->writeConfigFile($this->moduleRoot . '/Area/Texts.php', ['language' => 'en', 'AppTexts' => ['areas' => ['MODULE']]]);
        $this->writeConfigFile($this->applicationRoot . '/Area/Texts/AppTexts.php', ['areas' => ['APP']]);
        $this->registerRoots();

        self::assertSame(['APP'], Config::get('Area.Texts.AppTexts.areas'), 'the application folder outranks the module file');
        self::assertSame('en', Config::get('Area.Texts.language'), 'a key only the module file defines is found');
        self::assertSame(['areas' => ['APP']], Config::get('Area.Texts')['AppTexts'], 'reading the node keeps the application entry');
    }

    public function testAKeyTheFileDefinesWinsOverTheFolderOfTheSameRoot(): void
    {
        $this->writeConfigFile($this->applicationRoot . '/Area/Texts.php', ['AppTexts' => ['areas' => ['FROM_FILE']]]);
        $this->writeConfigFile($this->applicationRoot . '/Area/Texts/AppTexts.php', ['areas' => ['FROM_FOLDER']]);
        $this->registerRoots();

        self::assertSame(['FROM_FILE'], Config::get('Area.Texts.AppTexts.areas'));
        self::assertSame(['FROM_FILE'], Config::get('Area.Texts')['AppTexts']['areas']);
    }

    public function testAnApplicationFileStillReplacesTheModuleFileWhole(): void
    {
        $this->writeConfigFile($this->moduleRoot . '/Area/Texts.php', ['language' => 'en', 'fallback' => 'en']);
        $this->writeConfigFile($this->applicationRoot . '/Area/Texts.php', ['language' => 'de']);
        $this->registerRoots();

        self::assertSame('de', Config::get('Area.Texts.language'));
        self::assertNull(Config::get('Area.Texts.fallback'), 'a key the application file leaves out is not taken from the module file');
        self::assertSame(['language' => 'de'], Config::get('Area.Texts'));
    }

    protected function registerRoots(): void
    {
        Config::addConfigDirectory($this->moduleRoot, true);
        Config::addConfigDirectory($this->applicationRoot);
    }

    protected function writeConfigFile(string $filePath, array $value): void
    {
        @mkdir(dirname($filePath), 0777, true);
        file_put_contents($filePath, "<?php\nreturn " . var_export($value, true) . ";\n");
    }

    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $directory . '/' . $item;
            is_dir($itemPath) ? $this->removeDirectory($itemPath) : unlink($itemPath);
        }
        rmdir($directory);
    }
}
