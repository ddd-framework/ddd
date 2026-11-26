<?php

declare(strict_types=1);

namespace DDD\Testing\Cache;

use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Cache\RedisSentinel;
use DDD\Infrastructure\Libs\Config;
use LogicException;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * A {@see RedisSentinel} cache that keeps its values in this process and never opens a connection, for the tests of an
 * application. Where the test environment's `redisSentinel` cache group points at real sentinels
 * (`CACHE_REDIS_SENTINEL_SENTINELS`), a write path a test drives can send commands there (a cache DEL after a write).
 *
 * {@see installForCacheGroup()} puts the double in place of the group's instance in `Cache::$instances`, so
 * `Cache::instance(Cache::CACHE_GROUP_REDIS_SENTINEL)` answers it; {@see restore()} puts back what was there (or
 * nothing: the next call builds the real instance again). It records every deleted key and every written key.
 *
 * `Cache::instance()` picks the class that serves a group from `CACHE_<GROUP>_CACHE_GROUP`. When the environment names
 * no type there, or another one, that class's `getInstance()` cannot return this double (a TypeError), so the install
 * pins the group's cache type in `$_ENV` to redisSentinel and {@see restore()} puts back what the environment had.
 *
 * Counters live in memory. Locks ({@see Cache::acquireLock()}) are not doubled: they reach for the connection, and
 * {@see getCacheAdapter()} throws, so a lock on the group fails loudly instead of reaching the sentinels.
 */
class InMemoryRedisSentinelCache extends RedisSentinel
{
    /** @var string[] Every key a delete was asked for, in call order */
    public array $deletedKeys = [];

    /** @var string[] Every key a set was asked for, in call order */
    public array $writtenKeys = [];

    /** @var array<string, mixed> The stored values by key */
    protected array $values = [];

    /** @var array<string, int> The atomic counters by key */
    protected array $counters = [];

    /** @var string The cache group this double stands in for */
    protected string $replacedCacheGroup = Cache::CACHE_GROUP_REDIS_SENTINEL;

    /** @var Cache|null The instance this double replaced; null when the group had none yet */
    protected ?Cache $replacedCacheInstance = null;

    /** @var array<string, mixed> The group's cache-type entry $_ENV held before the install: one key, or none */
    protected array $replacedCacheTypeEnv = [];

    public function __construct()
    {
        parent::__construct(['defaultTTL' => 3600, 'namespace' => 'test']);
    }

    /**
     * Installs a new double as the instance of $cacheGroup and returns it.
     *
     * @param string $cacheGroup
     *
     * @return self
     */
    public static function installForCacheGroup(string $cacheGroup = Cache::CACHE_GROUP_REDIS_SENTINEL): self
    {
        $inMemoryRedisSentinelCache = new self();
        $inMemoryRedisSentinelCache->replacedCacheGroup = $cacheGroup;
        $cacheTypeEnvKey = self::getCacheTypeEnvKey($cacheGroup);
        if (array_key_exists($cacheTypeEnvKey, $_ENV)) {
            $inMemoryRedisSentinelCache->replacedCacheTypeEnv = [$cacheTypeEnvKey => $_ENV[$cacheTypeEnvKey]];
        }
        $_ENV[$cacheTypeEnvKey] = self::CACHE_TYPE_REDIS_SENTINEL;
        if (Config::getEnv($cacheTypeEnvKey) !== self::CACHE_TYPE_REDIS_SENTINEL) {
            $inMemoryRedisSentinelCache->restoreCacheTypeEnv();
            throw new LogicException(
                'Config::$env sets ' . $cacheTypeEnvKey . ', so the cache type pinned in $_ENV does not apply.'
            );
        }
        $cacheInstancesProperty = new ReflectionProperty(Cache::class, 'instances');
        $cacheInstancesByGroup = $cacheInstancesProperty->getValue() ?? [];
        $inMemoryRedisSentinelCache->replacedCacheInstance = $cacheInstancesByGroup[$cacheGroup] ?? null;
        $cacheInstancesByGroup[$cacheGroup] = $inMemoryRedisSentinelCache;
        $cacheInstancesProperty->setValue(null, $cacheInstancesByGroup);
        if (Cache::instance($cacheGroup) !== $inMemoryRedisSentinelCache) {
            $inMemoryRedisSentinelCache->restore();
            throw new LogicException('The cache group ' . $cacheGroup . ' does not resolve to the installed double.');
        }

        return $inMemoryRedisSentinelCache;
    }

    /**
     * Puts back the instance this double replaced (or none) and the group's cache type the environment had (or none).
     *
     * @return void
     */
    public function restore(): void
    {
        $cacheInstancesProperty = new ReflectionProperty(Cache::class, 'instances');
        $cacheInstancesByGroup = $cacheInstancesProperty->getValue() ?? [];
        if ($this->replacedCacheInstance === null) {
            unset($cacheInstancesByGroup[$this->replacedCacheGroup]);
        } else {
            $cacheInstancesByGroup[$this->replacedCacheGroup] = $this->replacedCacheInstance;
        }
        $cacheInstancesProperty->setValue(null, $cacheInstancesByGroup);
        $this->restoreCacheTypeEnv();
    }

    /**
     * The environment key that names the cache type of $cacheGroup, as {@see Cache::getCacheConfig()} reads it.
     *
     * @param string $cacheGroup
     *
     * @return string
     */
    protected static function getCacheTypeEnvKey(string $cacheGroup): string
    {
        $cacheGroupIndex = self::CACHE_GROUP_TO_ENV_ALLOCATION[$cacheGroup] ?? strtoupper($cacheGroup);

        return 'CACHE_' . $cacheGroupIndex . '_CACHE_GROUP';
    }

    /**
     * Puts back the group's cache-type entry $_ENV held before the install, or removes it when there was none.
     *
     * @return void
     */
    protected function restoreCacheTypeEnv(): void
    {
        unset($_ENV[self::getCacheTypeEnvKey($this->replacedCacheGroup)]);
        foreach ($this->replacedCacheTypeEnv as $cacheTypeEnvKey => $cacheTypeEnvValue) {
            $_ENV[$cacheTypeEnvKey] = $cacheTypeEnvValue;
        }
    }

    public function get(string ...$keys): mixed
    {
        if (count($keys) === 1) {
            return $this->values[$keys[0]] ?? false;
        }
        $valuesByKey = [];
        foreach ($keys as $key) {
            $valuesByKey[$key] = $this->values[$key] ?? false;
        }

        return $valuesByKey;
    }

    public function set(string $key, mixed $value, ?int $ttl = null, bool $deferred = false): void
    {
        $this->writtenKeys[] = $key;
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        $this->deletedKeys[] = $key;
        unset($this->values[$key]);
    }

    public function commit()
    {
        // nothing is deferred in memory
    }

    public function clear(): void
    {
        $this->values = [];
        $this->counters = [];
    }

    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        return $this->counters[$key] = ($this->counters[$key] ?? 0) + $by;
    }

    public function decrement(string $key, int $by = 1): int
    {
        return $this->counters[$key] = ($this->counters[$key] ?? 0) - $by;
    }

    public function getCounter(string $key): int
    {
        return $this->counters[$key] ?? 0;
    }

    public function deleteCounter(string $key): void
    {
        unset($this->counters[$key]);
    }

    public function getCacheAdapter(): RedisAdapter
    {
        throw new LogicException('InMemoryRedisSentinelCache opens no connection and has no adapter.');
    }
}
