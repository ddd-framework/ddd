<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Cache;

use DDD\Infrastructure\Cache\Redis;
use DDD\Infrastructure\Cache\RedisSentinel;
use PHPUnit\Framework\TestCase;

/**
 * The Redis cache backends read their connection settings with defaults: a configuration without `password` (or
 * without host, port, database) connects without raising "Undefined array key".
 */
class RedisConnectionSettingsTest extends TestCase
{
    public function testTheDsnOmitsAMissingOrEmptyPasswordAndDefaultsHostPortDatabase(): void
    {
        $this->assertSame('redis://127.0.0.1:6379/0', (new Redis([]))->buildRedisConnectionDsn());
        $this->assertSame('redis://cache:6380/2', (new Redis(['host' => 'cache', 'port' => 6380, 'database' => 2, 'password' => '']))->buildRedisConnectionDsn());
    }

    public function testTheDsnCarriesASetPassword(): void
    {
        $redis = new Redis(['password' => 'secret', 'host' => 'cache', 'port' => 6379, 'database' => 1]);
        $this->assertSame('redis://secret@cache:6379/1', $redis->buildRedisConnectionDsn());
    }

    public function testTheSentinelParametersCarryAPasswordOnlyWhenSet(): void
    {
        $this->assertSame(['database' => 0], (new RedisSentinel([]))->buildSentinelConnectionParameters());
        $this->assertSame(
            ['database' => 3, 'password' => 'secret'],
            (new RedisSentinel(['database' => 3, 'password' => 'secret']))->buildSentinelConnectionParameters()
        );
    }
}
