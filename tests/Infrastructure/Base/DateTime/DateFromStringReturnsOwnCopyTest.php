<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Base\DateTime;

use DDD\Infrastructure\Base\DateTime\Date;
use PHPUnit\Framework\TestCase;

/**
 * `Date::fromString()` caches a parsed string, but every caller gets its own object: changing one date never changes
 * another date parsed from the same string.
 */
class DateFromStringReturnsOwnCopyTest extends TestCase
{
    public function testChangingOneParsedDateLeavesTheNextParseOfTheSameStringAlone(): void
    {
        $first = Date::fromString('2026-11-01');
        $first->modify('+5 days');

        $second = Date::fromString('2026-11-01');

        self::assertSame('2026-11-01', $second->format('Y-m-d'));
        self::assertNotSame($first, $second);
        self::assertSame('2026-11-06', $first->format('Y-m-d'));
    }

    public function testTheCacheKeepsAStringOfAnotherFormatApart(): void
    {
        $dayMonthYear = Date::fromString('01.11.2026', 'd.m.Y');
        self::assertSame('2026-11-01', $dayMonthYear->format('Y-m-d'));
        self::assertFalse(Date::fromString('01.11.2026'));
    }
}
