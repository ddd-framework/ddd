<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use DDD\Infrastructure\Cache\Predis\Client;
use Symfony\Component\Cache\Adapter\RedisAdapter;

class RedisSentinel extends Cache
{
    use RedisAtomicCounterTrait;

    protected RedisAdapter $adapter;

    public function getCacheAdapter(): RedisAdapter
    {
        if (!isset($this->adapter)) {
            $options = [
                'replication' => 'redis-sentinel',
                'service' => 'mymaster',
                'parameters' => $this->buildSentinelConnectionParameters(),
                'ttl' => $this->ttl,
            ];
            // Keep the Predis client for atomic counter ops (INCRBY/EXPIRE) — the PSR-6 adapter does not expose them.
            $this->nativeRedisConnection = new Client($this->config['sentinels'], $options);
            $this->adapter = new RedisAdapter($this->nativeRedisConnection, (string)($this->config['namespace'] ?? ''), $this->ttl);
        }
        return $this->adapter;
    }

    /**
     * The connection parameters of the replicas from the cache configuration: `database` (default 0) and `password`
     * only when one is set. A configuration without `password` used to raise "Undefined array key" on every connect.
     *
     * @return array<string, int|string> Predis connection parameters
     */
    public function buildSentinelConnectionParameters(): array
    {
        $connectionParameters = ['database' => (int)($this->config['database'] ?? 0)];
        $password = (string)($this->config['password'] ?? '');
        if ($password !== '') {
            $connectionParameters['password'] = $password;
        }
        return $connectionParameters;
    }
}
