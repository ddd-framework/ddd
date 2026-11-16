<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Services;

use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Infrastructure\Services\Fixtures\ControllableMemoryDDDService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Code running inside a process it does not own (a message handler run in-process by a console command) raises the
 * memory limit and never lowers it: `ini_set('memory_limit', '512M')` lowered a 2048M command, and with the usage
 * already above 512M PHP warned "Failed to set memory limit", which the dev kernel turned into an exception.
 */
class RaiseMemoryLimitTest extends TestCase
{
    protected const int MEGABYTE = 1024 * 1024;

    protected ControllableMemoryDDDService $controllableMemoryDDDService;

    protected function setUp(): void
    {
        $this->controllableMemoryDDDService = new ControllableMemoryDDDService();
        $this->controllableMemoryDDDService->currentMemoryUsageInBytes = 100 * self::MEGABYTE;
    }

    public function testAnUnlimitedLimitStays(): void
    {
        $this->controllableMemoryDDDService->memoryLimitSetting = '-1';
        $this->assertFalse($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('512M'));
        $this->assertSame([], $this->controllableMemoryDDDService->memoryLimitSettingsSet);
    }

    public function testAHigherLimitStays(): void
    {
        $this->controllableMemoryDDDService->memoryLimitSetting = '2048M';
        $this->assertFalse($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('512M'));
        $this->assertSame([], $this->controllableMemoryDDDService->memoryLimitSettingsSet);
    }

    public function testAnEqualLimitStays(): void
    {
        $this->controllableMemoryDDDService->memoryLimitSetting = '512M';
        $this->assertFalse($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('512M'));
        $this->assertSame([], $this->controllableMemoryDDDService->memoryLimitSettingsSet);
    }

    public function testALowerLimitIsRaised(): void
    {
        $this->controllableMemoryDDDService->memoryLimitSetting = '128M';
        $this->assertTrue($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('512M'));
        $this->assertSame(['512M'], $this->controllableMemoryDDDService->memoryLimitSettingsSet);
        $this->assertSame(512 * self::MEGABYTE, $this->controllableMemoryDDDService->getMemoryLimitInBytes());
    }

    public function testALowerRequestAfterARaiseIsIgnored(): void
    {
        $this->controllableMemoryDDDService->memoryLimitSetting = '128M';
        $this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('1024M');
        $this->assertFalse($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('512M'));
        $this->assertSame(['1024M'], $this->controllableMemoryDDDService->memoryLimitSettingsSet);
    }

    public function testMinusOneLiftsALimit(): void
    {
        $this->controllableMemoryDDDService->memoryLimitSetting = '128M';
        $this->assertTrue($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('-1'));
        $this->assertSame(['-1'], $this->controllableMemoryDDDService->memoryLimitSettingsSet);
    }

    public function testARequestAtOrBelowTheCurrentUsageIsNotSet(): void
    {
        // usage above the reported limit can only come from the seams, but the guard must hold whatever they report
        $this->controllableMemoryDDDService->memoryLimitSetting = '256M';
        $this->controllableMemoryDDDService->currentMemoryUsageInBytes = 600 * self::MEGABYTE;
        $this->assertFalse($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('512M'));
        $this->assertFalse($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('600M'));
        $this->assertSame([], $this->controllableMemoryDDDService->memoryLimitSettingsSet);
    }

    public function testTheRealProcessLimitIsRaisedNeverLoweredAndNoWarningIsRaised(): void
    {
        $memoryLimitBefore = (string)ini_get('memory_limit');
        $dddService = new DDDService();
        $usageInBytes = memory_get_usage();
        $warnings = [];
        set_error_handler(static function (int $errorNumber, string $errorMessage) use (&$warnings): bool {
            $warnings[] = $errorMessage;
            return true;
        });
        try {
            ini_set('memory_limit', (string)($usageInBytes * 4));
            // below the usage of this process: a plain ini_set warns "Failed to set memory limit" here
            $belowUsageChanged = $dddService->raiseMemoryLimitToAtLeast((string)intdiv($usageInBytes, 2));
            $lowerChanged = $dddService->raiseMemoryLimitToAtLeast((string)($usageInBytes * 2));
            $limitAfterLowerRequests = (string)ini_get('memory_limit');
            $higherChanged = $dddService->raiseMemoryLimitToAtLeast((string)($usageInBytes * 8));
            $limitAfterHigherRequest = (string)ini_get('memory_limit');
        } finally {
            restore_error_handler();
            ini_set('memory_limit', $memoryLimitBefore);
        }
        $this->assertFalse($belowUsageChanged);
        $this->assertFalse($lowerChanged);
        $this->assertSame((string)($usageInBytes * 4), $limitAfterLowerRequests);
        $this->assertTrue($higherChanged);
        $this->assertSame((string)($usageInBytes * 8), $limitAfterHigherRequest);
        $this->assertSame([], $warnings);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function memoryLimitForms(): array
    {
        return [
            'upper-case M' => ['512M', 512 * self::MEGABYTE],
            'lower-case m' => ['512m', 512 * self::MEGABYTE],
            'upper-case G' => ['2G', 2048 * self::MEGABYTE],
            'lower-case g' => ['2g', 2048 * self::MEGABYTE],
            'lower-case k' => ['524288k', 512 * self::MEGABYTE],
            'plain bytes' => [(string)(512 * self::MEGABYTE), 512 * self::MEGABYTE],
            'surrounding blanks' => [' 512M ', 512 * self::MEGABYTE],
        ];
    }

    /**
     * @dataProvider memoryLimitForms
     */
    public function testTheMemoryLimitFormsAreUnderstood(string $requestedMemoryLimit, int $expectedMemoryLimitInBytes): void
    {
        $this->controllableMemoryDDDService->memoryLimitSetting = '128M';
        $this->assertTrue($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast($requestedMemoryLimit));
        $this->assertSame($expectedMemoryLimitInBytes, $this->controllableMemoryDDDService->getMemoryLimitInBytes());
        $this->assertFalse(
            $this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast($requestedMemoryLimit),
            'the same request again changes nothing'
        );
    }

    public function testAFormWithAHigherUnitStaysAboveALowerRequest(): void
    {
        $this->controllableMemoryDDDService->memoryLimitSetting = '1g';
        $this->assertFalse($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('1000m'));
        $this->assertTrue($this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('1025M'));
    }

    public function testANonLimitValueIsRefused(): void
    {
        $this->controllableMemoryDDDService->memoryLimitSetting = '128M';
        $this->expectException(InvalidArgumentException::class);
        $this->controllableMemoryDDDService->raiseMemoryLimitToAtLeast('512MB');
    }
}
