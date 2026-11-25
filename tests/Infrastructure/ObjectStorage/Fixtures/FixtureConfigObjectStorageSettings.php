<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage\Fixtures;

use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;

/** Store settings read from a committed `Common.ObjectStorage` the test sets, instead of the application's config */
class FixtureConfigObjectStorageSettings extends ObjectStorageSettings
{
    /** @var array<string, mixed> What `config/app/Common/ObjectStorage.php` would return */
    public static array $committedObjectStorageConfig = [];

    protected static function readCommittedObjectStorageConfig(): array
    {
        return static::$committedObjectStorageConfig;
    }
}
