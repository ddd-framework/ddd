<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Cache;

use DDD\Infrastructure\Cache\Cache;

/** The batch contract on Redis or Valkey over Predis (the TTL extension is one EXPIRE per key). */
class PredisCacheBatchTest extends CacheBatchContractTestCase
{
    protected function createCache(): Cache
    {
        if (!getenv('DDD_TEST_REDIS_HOST')) {
            self::markTestSkipped('DDD_TEST_REDIS_HOST is not set (start the harness redis service)');
        }
        return new PredisBackedRedisCache(RedisCacheCounterAndLockTest::redisConfig());
    }
}
