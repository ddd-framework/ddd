<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Cache;

use DDD\Infrastructure\Cache\Apc;
use DDD\Infrastructure\Cache\Cache;

/** The batch contract on APCu (run with `php -d apc.enable_cli=1 -d apc.use_request_time=0`). */
class ApcCacheBatchTest extends CacheBatchContractTestCase
{
    protected function createCache(): Cache
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled() || ini_get('apc.use_request_time')) {
            self::markTestSkipped('APCu is not enabled for the CLI with real-time TTLs (php -d apc.enable_cli=1 -d apc.use_request_time=0)');
        }
        return new Apc(['namespace' => 'ddd-test', 'defaultTTL' => 60]);
    }

    protected function getExpiryToleranceSeconds(): float
    {
        return 1.0;
    }
}
