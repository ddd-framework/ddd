<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Log\LoggerInterface;

/**
 * A driver of named object stores: the class a driver name stands for in `Common.ObjectStorage` (`'drivers' =>
 * ['<driver name>' => <class>]`; the built-in `s3` and `filesystem` are registered the same way). The class is the
 * store and builds itself from the store's settings; {@see \DDD\Infrastructure\Services\ObjectStorageService} calls
 * the factory once per store and process.
 *
 * A driver implements at least {@see BasicObjectStorage} (put, get, delete, exists); one that implements
 * {@see ObjectStorage} also serves {@see \DDD\Infrastructure\Services\ObjectStorageService::getObjectStorage()}.
 * Its own settings (a gateway URL, a token) come from {@see ObjectStorageSettings::getOption()}: the store's
 * committed key, overridden by `OBJECT_STORAGE_<NAME>_<OPTION>`.
 */
interface ObjectStorageDriver extends BasicObjectStorage
{
    /**
     * The store built from its settings; $logger is the application logger (null without a container).
     *
     * @throws InternalErrorException the settings are incomplete for this driver, or a package it needs is missing
     */
    public static function fromSettings(ObjectStorageSettings $objectStorageSettings, ?LoggerInterface $logger = null): static;
}
