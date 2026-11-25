<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage\Fixtures;

use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use DDD\Infrastructure\Services\ObjectStorageService;
use Psr\Log\LoggerInterface;

/** The service with the test's committed configuration and a recording logger; the logic under test is unchanged */
class FixtureConfigObjectStorageService extends ObjectStorageService
{
    public RecordingLogger $recordingLogger;

    public function __construct()
    {
        $this->recordingLogger = new RecordingLogger();
    }

    public function getObjectStorageSettings(string $storeName): ObjectStorageSettings
    {
        return FixtureConfigObjectStorageSettings::fromEnvironment($storeName);
    }

    public function getObjectStorageNames(): array
    {
        return FixtureConfigObjectStorageSettings::getCommittedStoreNames();
    }

    protected function getObjectStorageLogger(): ?LoggerInterface
    {
        return $this->recordingLogger;
    }
}
