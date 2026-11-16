<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Base\DateTime;

use DDD\Infrastructure\Base\DateTime\Date;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * A `Date` is the calendar day it names, at midnight UTC, whatever PHP's default zone is: east of UTC the day used to
 * move one back, because midnight of the default zone was converted to UTC.
 */
class DateKeepsItsCalendarDayInEveryDefaultZoneTest extends TestCase
{
    protected string $defaultZoneBefore;

    protected function setUp(): void
    {
        $this->defaultZoneBefore = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultZoneBefore);
    }

    public function testTheDayIsKeptInZonesEastAndWestOfUtc(): void
    {
        foreach (['Pacific/Auckland', 'Europe/Berlin', 'UTC', 'America/Los_Angeles'] as $defaultZone) {
            date_default_timezone_set($defaultZone);
            $constructed = new Date('2026-11-10');
            $parsed = Date::fromString('2026-11-' . (10 + array_search($defaultZone, ['Pacific/Auckland', 'Europe/Berlin', 'UTC', 'America/Los_Angeles'], true)));
            self::assertSame('2026-11-10 00:00:00 UTC', $constructed->format('Y-m-d H:i:s T'), $defaultZone);
            self::assertSame('00:00:00 UTC', $parsed->format('H:i:s T'), $defaultZone);
            self::assertSame('2026-11-' . (10 + array_search($defaultZone, ['Pacific/Auckland', 'Europe/Berlin', 'UTC', 'America/Los_Angeles'], true)), $parsed->format('Y-m-d'), $defaultZone);
        }
    }

    public function testAnExplicitZoneNamesTheDayOfThatZone(): void
    {
        date_default_timezone_set('UTC');
        $date = new Date('2026-11-10 23:30:00', new DateTimeZone('America/Los_Angeles'));
        self::assertSame('2026-11-10', $date->format('Y-m-d'));
    }
}
