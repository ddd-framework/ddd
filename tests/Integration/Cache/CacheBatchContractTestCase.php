<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Cache;

use DDD\Infrastructure\Cache\Cache;
use PHPUnit\Framework\TestCase;

/**
 * The batch contract of {@see Cache}, run against every backend (one subclass per backend): several values are read
 * and written in one call, a read answers only the hits keyed as asked, and a TTL is extended without moving a missing
 * key into existence.
 */
abstract class CacheBatchContractTestCase extends TestCase
{
    /** The backend under test; the test is skipped when it is not available */
    abstract protected function createCache(): Cache;

    /** How late after its TTL the backend may still report an entry (APCu compares whole seconds) */
    protected function getExpiryToleranceSeconds(): float
    {
        return 0.0;
    }

    protected Cache $cache;

    protected string $keyPrefix;

    protected function setUp(): void
    {
        $this->cache = $this->createCache();
        $this->keyPrefix = 'batch.' . bin2hex(random_bytes(4)) . '/';
    }

    public function testSeveralValuesAreWrittenAndReadInOneCallAndOnlyHitsAnswer(): void
    {
        $this->cache->setMultiple([$this->keyPrefix . 'a:1' => 'alpha', $this->keyPrefix . 'b' => str_repeat('x', 300_000)], 60);

        $valuesByKey = $this->cache->getMultiple([$this->keyPrefix . 'a:1', $this->keyPrefix . 'missing', $this->keyPrefix . 'b']);

        self::assertSame(['alpha', 300_000], [$valuesByKey[$this->keyPrefix . 'a:1'] ?? null, strlen($valuesByKey[$this->keyPrefix . 'b'] ?? '')]);
        self::assertArrayNotHasKey($this->keyPrefix . 'missing', $valuesByKey);
        self::assertSame([$this->keyPrefix . 'a:1' => 'alpha'], $this->cache->getMultiple([$this->keyPrefix . 'a:1']), 'the same shape for one key');
        self::assertSame([], $this->cache->getMultiple([]));
    }

    public function testAnExtendedTtlOutlivesTheFirstAndAMissingKeyStaysMissing(): void
    {
        $this->cache->setMultiple([$this->keyPrefix . 'kept' => 'value'], 2);
        usleep(1_000_000);
        $this->cache->extendTtlOfKeys([$this->keyPrefix . 'kept', $this->keyPrefix . 'absent'], 4);
        usleep((int)((2.0 + $this->getExpiryToleranceSeconds()) * 1_000_000));

        self::assertSame([$this->keyPrefix . 'kept' => 'value'], $this->cache->getMultiple([$this->keyPrefix . 'kept', $this->keyPrefix . 'absent']));
    }
}
