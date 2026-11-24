<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Libs;

use DDD\Infrastructure\Libs\Config;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Reading a config file keeps the files read before it below the same path in the process cache */
class ConfigTreeCacheTest extends TestCase
{
    /** @var array<string, mixed> the static state before the test */
    protected array $stateBefore = [];

    protected string $configRoot;

    protected function setUp(): void
    {
        foreach (['appRootDirectories', 'moduleRootDirectories', 'configTree'] as $propertyName) {
            $property = new ReflectionProperty(Config::class, $propertyName);
            $this->stateBefore[$propertyName] = $property->isInitialized() ? $property->getValue() : null;
        }
        (new ReflectionProperty(Config::class, 'appRootDirectories'))->setValue(null, []);
        (new ReflectionProperty(Config::class, 'moduleRootDirectories'))->setValue(null, []);
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, []);

        $this->configRoot = sys_get_temp_dir() . '/ddd-config-tree-cache-' . getmypid();
        @mkdir($this->configRoot . '/Area/Skills', 0777, true);
        file_put_contents(
            $this->configRoot . '/Area/first.php',
            "<?php\n\$GLOBALS['dddConfigTreeCacheReads'] = (\$GLOBALS['dddConfigTreeCacheReads'] ?? 0) + 1;\nreturn ['value' => 'first'];\n"
        );
        file_put_contents($this->configRoot . '/Area/second.php', "<?php\nreturn ['value' => 'second'];\n");
        file_put_contents($this->configRoot . '/Area/Skills/Topic.md', "a skill text\n");
        Config::addConfigDirectory($this->configRoot);
        $GLOBALS['dddConfigTreeCacheReads'] = 0;
    }

    protected function tearDown(): void
    {
        foreach ($this->stateBefore as $propertyName => $value) {
            if ($value !== null) {
                (new ReflectionProperty(Config::class, $propertyName))->setValue(null, $value);
            }
        }
        foreach (['Area/first.php', 'Area/second.php', 'Area/Skills/Topic.md'] as $file) {
            @unlink($this->configRoot . '/' . $file);
        }
        @rmdir($this->configRoot . '/Area/Skills');
        @rmdir($this->configRoot . '/Area');
        @rmdir($this->configRoot);
        unset($GLOBALS['dddConfigTreeCacheReads']);
    }

    public function testASecondFileOfTheSameAreaKeepsTheFirstInTheCache(): void
    {
        self::assertSame('first', Config::get('Area.first.value'));
        self::assertSame('second', Config::get('Area.second.value'));
        self::assertSame("a skill text\n", Config::get('Area.Skills.Topic'));

        self::assertSame('first', Config::get('Area.first.value'));
        self::assertSame(1, $GLOBALS['dddConfigTreeCacheReads'], 'the first file was read once, not again after its siblings');
    }
}
