<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Cache;

use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Cache\Redis;

/** The batch contract on Redis or Valkey over phpredis (the TTL extension is one EXPIRE pipeline). */
class RedisCacheBatchTest extends CacheBatchContractTestCase
{
    protected function createCache(): Cache
    {
        if (!getenv('DDD_TEST_REDIS_HOST')) {
            self::markTestSkipped('DDD_TEST_REDIS_HOST is not set (start the harness redis service)');
        }
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not loaded');
        }
        return new Redis(RedisCacheCounterAndLockTest::redisConfig());
    }
}
