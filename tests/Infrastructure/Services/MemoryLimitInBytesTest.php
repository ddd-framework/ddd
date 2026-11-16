<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Services;

use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\TestCase;

/**
 * Every `memory_limit` value converts to bytes; no limit (`-1`) is PHP_INT_MAX, so the memory check never fails on it.
 */
class MemoryLimitInBytesTest extends TestCase
{
    public function testEveryMemoryLimitFormConvertsToBytes(): void
    {
        self::assertSame(PHP_INT_MAX, DDDService::convertMemoryLimitToBytes('-1'));
        self::assertSame(PHP_INT_MAX, DDDService::convertMemoryLimitToBytes(''));
        self::assertSame(134217728, DDDService::convertMemoryLimitToBytes('134217728'));
        self::assertSame(128 * 1024 * 1024, DDDService::convertMemoryLimitToBytes('128M'));
        self::assertSame(128 * 1024 * 1024, DDDService::convertMemoryLimitToBytes('128m'));
        self::assertSame(2 * 1024 * 1024 * 1024, DDDService::convertMemoryLimitToBytes('2G'));
        self::assertSame(512 * 1024, DDDService::convertMemoryLimitToBytes('512k'));
    }
}
