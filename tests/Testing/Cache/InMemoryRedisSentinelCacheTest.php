<?php

declare(strict_types=1);

namespace DDD\Tests\Testing\Cache;

use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Cache\RedisSentinel;
use DDD\Infrastructure\Libs\Config;
use DDD\Testing\Cache\InMemoryRedisSentinelCache;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The double must answer for the `redisSentinel` group whatever the environment says the group's cache type is.
 * `Cache::instance()` picks the class from `CACHE_REDIS_SENTINEL_CACHE_GROUP`; without it (or with another type) the
 * group is served by a class whose `getInstance()` cannot return a {@see InMemoryRedisSentinelCache}.
 */
class InMemoryRedisSentinelCacheTest extends TestCase
{
    protected const string CACHE_TYPE_ENV_KEY = 'CACHE_REDIS_SENTINEL_CACHE_GROUP';

    /** @var array<string, string> The cache-type entry $_ENV held before the test: one key, or none */
    protected array $cacheTypeEnvBefore = [];

    /** @var InMemoryRedisSentinelCache|null The double a test installed and left for tearDown() to restore */
    protected ?InMemoryRedisSentinelCache $installedCache = null;

    /** @var array<string, Cache>|null `Cache::$instances` as it was before the test */
    protected ?array $cacheInstancesBefore = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Cache::getConfig() replaces %APP_PREFIX% and %APP_ROOT_DIR% in the environment values; an application
        // defines both constants.
        if (!defined('APP_PREFIX')) {
            define('APP_PREFIX', 'ddd_test');
        }
        if (!defined('APP_ROOT_DIR')) {
            define('APP_ROOT_DIR', sys_get_temp_dir());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (array_key_exists(self::CACHE_TYPE_ENV_KEY, $_ENV)) {
            $this->cacheTypeEnvBefore = [self::CACHE_TYPE_ENV_KEY => $_ENV[self::CACHE_TYPE_ENV_KEY]];
        }
        $this->cacheInstancesBefore = (new ReflectionProperty(Cache::class, 'instances'))->getValue();
    }

    protected function tearDown(): void
    {
        $this->installedCache?->restore();
        (new ReflectionProperty(Cache::class, 'instances'))->setValue(null, $this->cacheInstancesBefore);
        unset($_ENV[self::CACHE_TYPE_ENV_KEY]);
        $_ENV = array_merge($_ENV, $this->cacheTypeEnvBefore);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function cacheTypeEnvValues(): array
    {
        return [
            'the environment names no cache type' => [null],
            'the environment names redisSentinel' => ['redisSentinel'],
            'the environment names apc' => ['apc'],
        ];
    }

    /** @dataProvider cacheTypeEnvValues */
    #[DataProvider('cacheTypeEnvValues')]
    public function testTheGroupResolvesToTheDoubleWhateverCacheTypeTheEnvNames(?string $cacheTypeEnvValue): void
    {
        $this->setCacheTypeEnv($cacheTypeEnvValue);

        $this->installedCache = InMemoryRedisSentinelCache::installForCacheGroup();

        self::assertSame($this->installedCache, Cache::instance(Cache::CACHE_GROUP_REDIS_SENTINEL));
    }

    /** @dataProvider cacheTypeEnvValues */
    #[DataProvider('cacheTypeEnvValues')]
    public function testRestorePutsBackTheCacheTypeTheEnvironmentHad(?string $cacheTypeEnvValue): void
    {
        $this->setCacheTypeEnv($cacheTypeEnvValue);
        $installedCache = InMemoryRedisSentinelCache::installForCacheGroup();

        $installedCache->restore();

        if ($cacheTypeEnvValue === null) {
            self::assertArrayNotHasKey(self::CACHE_TYPE_ENV_KEY, $_ENV);
        } else {
            self::assertSame($cacheTypeEnvValue, $_ENV[self::CACHE_TYPE_ENV_KEY] ?? null);
        }
    }

    public function testTheInstallRefusesWhenConfigEnvOverridesTheCacheTypeAndLeavesEnvAsItWas(): void
    {
        $configEnvProperty = new ReflectionProperty(Config::class, 'env');
        $configEnvBefore = $configEnvProperty->getValue();
        $this->setCacheTypeEnv(null);
        Config::setEnv([self::CACHE_TYPE_ENV_KEY => 'apc']);
        try {
            InMemoryRedisSentinelCache::installForCacheGroup();
            self::fail('The install must refuse a cache type it cannot pin.');
        } catch (LogicException $logicException) {
            self::assertStringContainsString(
                'Config::$env sets ' . self::CACHE_TYPE_ENV_KEY,
                $logicException->getMessage()
            );
        } finally {
            $configEnvProperty->setValue(null, $configEnvBefore);
        }

        self::assertArrayNotHasKey(self::CACHE_TYPE_ENV_KEY, $_ENV);
    }

    public function testALockOnTheGroupFailsWithoutOpeningAConnection(): void
    {
        $this->installedCache = InMemoryRedisSentinelCache::installForCacheGroup();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('opens no connection');

        Cache::instance(Cache::CACHE_GROUP_REDIS_SENTINEL)->acquireLock('eaal-11-lock', 10);
    }

    public function testRestorePutsBackTheInstanceTheGroupHad(): void
    {
        $realInstance = new RedisSentinel(['defaultTTL' => 3600, 'namespace' => 'test']);
        $this->setGroupInstance($realInstance);
        $installedCache = InMemoryRedisSentinelCache::installForCacheGroup();

        $installedCache->restore();

        self::assertSame($realInstance, $this->getGroupInstances()[Cache::CACHE_GROUP_REDIS_SENTINEL] ?? null);
    }

    public function testRestoreLeavesTheGroupEmptyWhenItHadNoInstance(): void
    {
        $this->setGroupInstance(null);
        $installedCache = InMemoryRedisSentinelCache::installForCacheGroup();

        $installedCache->restore();

        self::assertArrayNotHasKey(Cache::CACHE_GROUP_REDIS_SENTINEL, $this->getGroupInstances());
    }

    /**
     * Sets the cache-type entry of the group in $_ENV, or removes it for null.
     *
     * @param string|null $cacheTypeEnvValue
     *
     * @return void
     */
    protected function setCacheTypeEnv(?string $cacheTypeEnvValue): void
    {
        unset($_ENV[self::CACHE_TYPE_ENV_KEY]);
        if ($cacheTypeEnvValue !== null) {
            $_ENV[self::CACHE_TYPE_ENV_KEY] = $cacheTypeEnvValue;
        }
    }

    /**
     * Puts $cacheInstance in the group's slot of `Cache::$instances`, or empties the slot for null.
     *
     * @param Cache|null $cacheInstance
     *
     * @return void
     */
    protected function setGroupInstance(?Cache $cacheInstance): void
    {
        $cacheInstancesByGroup = $this->getGroupInstances();
        unset($cacheInstancesByGroup[Cache::CACHE_GROUP_REDIS_SENTINEL]);
        if ($cacheInstance !== null) {
            $cacheInstancesByGroup[Cache::CACHE_GROUP_REDIS_SENTINEL] = $cacheInstance;
        }
        (new ReflectionProperty(Cache::class, 'instances'))->setValue(null, $cacheInstancesByGroup);
    }

    /**
     * @return array<string, Cache> `Cache::$instances` by cache group
     */
    protected function getGroupInstances(): array
    {
        return (new ReflectionProperty(Cache::class, 'instances'))->getValue() ?? [];
    }
}
