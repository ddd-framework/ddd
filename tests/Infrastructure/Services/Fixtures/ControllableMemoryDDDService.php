<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Services\Fixtures;

use DDD\Infrastructure\Services\DDDService;

/** A DDDService whose memory usage, memory limit setting and release step a test sets; setting the limit is recorded, never applied to the process */
class ControllableMemoryDDDService extends DDDService
{
    public int $currentMemoryUsageInBytes = 0;

    /** @var string|null The `memory_limit` text to report; null = the real setting of the process */
    public ?string $memoryLimitSetting = null;

    /** @var string[] The `memory_limit` values the service set, in order; the setting reported changes with them */
    public array $memoryLimitSettingsSet = [];

    /** @var float|null The release step in percent of the limit; null = the configured one */
    public ?float $releaseStepPercentOfLimit = null;

    protected function getCurrentMemoryUsageInBytes(): int
    {
        return $this->currentMemoryUsageInBytes;
    }

    protected function getMemoryLimitSetting(): string
    {
        return $this->memoryLimitSetting ?? parent::getMemoryLimitSetting();
    }

    protected function setMemoryLimitSetting(string $memoryLimit): bool
    {
        $this->memoryLimitSettingsSet[] = $memoryLimit;
        $this->memoryLimitSetting = $memoryLimit;
        return true;
    }

    protected function getMemoryReleaseStepPercentOfLimit(): float
    {
        return $this->releaseStepPercentOfLimit ?? parent::getMemoryReleaseStepPercentOfLimit();
    }
}
