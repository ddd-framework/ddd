<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use Symfony\Component\Cache\Adapter\RedisAdapter;

class Redis extends Cache
{
    use RedisAtomicCounterTrait;

    protected RedisAdapter $adapter;

    public function getCacheAdapter(): RedisAdapter
    {
        if (!isset($this->adapter)) {
            // Capture the native connection (kept in $nativeRedisConnection for atomic counter ops) instead of
            // discarding it — the PSR-6 RedisAdapter does not expose INCRBY/EXPIRE.
            $this->nativeRedisConnection = RedisAdapter::createConnection($this->buildRedisConnectionDsn());

            $this->adapter = new RedisAdapter(
                $this->nativeRedisConnection,
                (string)($this->config['namespace'] ?? ''),
                $this->ttl,
                $this->marshaller
            );
        }
        return $this->adapter;
    }

    /**
     * The connection address from the cache configuration. Every key is optional: no password (or an empty one) means
     * none, host, port and database default to Redis's own (`127.0.0.1`, `6379`, `0`). A configuration without
     * `password` used to raise "Undefined array key" on every connect.
     *
     * @return string
     */
    public function buildRedisConnectionDsn(): string
    {
        $password = (string)($this->config['password'] ?? '');
        $host = (string)($this->config['host'] ?? '127.0.0.1');
        $port = (int)($this->config['port'] ?? 6379);
        $database = (int)($this->config['database'] ?? 0);
        return sprintf('redis://%s%s:%d/%d', $password !== '' ? $password . '@' : '', $host, $port, $database);
    }
}
