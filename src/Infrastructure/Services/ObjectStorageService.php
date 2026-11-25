<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\BasicObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorageDriver;
use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The named object stores of the application ({@see ObjectStorageSettings}): a consumer asks for its store by name —
 * the issues log's source maps use `sourcemaps`, media uploads or exports their own — and gets null when the store is
 * not configured, so it can degrade instead of failing at boot.
 *
 * Two levels: {@see self::getBasicObjectStorage()} answers every configured store as {@see BasicObjectStorage} (put,
 * get, delete, exists), {@see self::getObjectStorage()} the full {@see ObjectStorage} contract and refuses a store
 * whose driver implements only the narrow one. The driver of a store is a class registered by name in
 * `Common.ObjectStorage.drivers` ({@see ObjectStorageDriver}); an application adds a driver by configuration, never by
 * extending this service.
 */
class ObjectStorageService extends Service
{
    /** @var BasicObjectStorage[] The stores built in this process, by name */
    protected array $objectStoragesByName = [];

    /** @var bool[] Store name => true once its "not configured" reason was logged in this process */
    protected array $loggedNotConfiguredStoreNames = [];

    /**
     * The store with the full contract; null when it is not configured.
     *
     * @throws InternalErrorException The store is configured but incomplete, its driver is unknown or its package is
     *                                missing, or its driver implements only {@see BasicObjectStorage}
     */
    public function getObjectStorage(string $storeName): ?ObjectStorage
    {
        $basicObjectStorage = $this->getBasicObjectStorage($storeName);
        if ($basicObjectStorage === null || $basicObjectStorage instanceof ObjectStorage) {
            return $basicObjectStorage;
        }
        throw new InternalErrorException(sprintf(
            'Object storage "%s": its driver %s implements only %s (put, get, delete, exists); this consumer needs %s',
            $storeName,
            $basicObjectStorage::class,
            BasicObjectStorage::class,
            ObjectStorage::class
        ));
    }

    /**
     * The store for a consumer that only writes, reads, deletes and checks objects; null when it is not configured.
     * Serves every driver, narrow or wide.
     *
     * @throws InternalErrorException The store is configured but incomplete, its driver is unknown or its package is missing
     */
    public function getBasicObjectStorage(string $storeName): ?BasicObjectStorage
    {
        if (isset($this->objectStoragesByName[$storeName])) {
            return $this->objectStoragesByName[$storeName];
        }
        $objectStorageSettings = $this->getObjectStorageSettings($storeName);
        if ($objectStorageSettings->hasInvalidDriver()) {
            throw new InternalErrorException((string)$objectStorageSettings->getNotConfiguredReason());
        }
        if (!$objectStorageSettings->isConfigured()) {
            $this->logNotConfiguredReasonOnce($objectStorageSettings);
            return null;
        }
        $driverClass = (string)$objectStorageSettings->driverClass;
        return $this->objectStoragesByName[$storeName] = is_a($driverClass, ObjectStorage::class, true)
            ? $this->createObjectStorage($objectStorageSettings)
            : $this->createBasicObjectStorage($objectStorageSettings);
    }

    public function isObjectStorageConfigured(string $storeName): bool
    {
        return isset($this->objectStoragesByName[$storeName]) || $this->getObjectStorageSettings($storeName)->isConfigured();
    }

    /** Why the store is not configured (names the settings to set); null when it is configured or was set in this process */
    public function getObjectStorageNotConfiguredReason(string $storeName): ?string
    {
        if (isset($this->objectStoragesByName[$storeName])) {
            return null;
        }
        return $this->getObjectStorageSettings($storeName)->getNotConfiguredReason();
    }

    /**
     * The store names of the committed configuration (`Common.ObjectStorage.stores`), configured or not.
     *
     * @return string[]
     */
    public function getObjectStorageNames(): array
    {
        return ObjectStorageSettings::getCommittedStoreNames();
    }

    /** Replaces a store (tests, or a store an application builds itself) */
    public function setObjectStorage(string $storeName, ?ObjectStorage $objectStorage): void
    {
        $this->setBasicObjectStorage($storeName, $objectStorage);
    }

    /** Replaces a store with one that may implement only the narrow contract (tests) */
    public function setBasicObjectStorage(string $storeName, ?BasicObjectStorage $basicObjectStorage): void
    {
        if ($basicObjectStorage === null) {
            unset($this->objectStoragesByName[$storeName]);
            return;
        }
        $this->objectStoragesByName[$storeName] = $basicObjectStorage;
    }

    public function getObjectStorageSettings(string $storeName): ObjectStorageSettings
    {
        return ObjectStorageSettings::fromEnvironment($storeName);
    }

    /**
     * A store whose driver implements {@see ObjectStorage}, built by its driver's factory.
     *
     * @throws InternalErrorException
     */
    protected function createObjectStorage(ObjectStorageSettings $objectStorageSettings): ObjectStorage
    {
        $objectStorage = $this->createBasicObjectStorage($objectStorageSettings);
        if (!$objectStorage instanceof ObjectStorage) {
            throw new InternalErrorException(sprintf(
                'Object storage "%s": its driver %s does not implement %s',
                $objectStorageSettings->name,
                $objectStorage::class,
                ObjectStorage::class
            ));
        }
        return $objectStorage;
    }

    /**
     * A store built by the factory of the class its driver name is registered for.
     *
     * @throws InternalErrorException
     */
    protected function createBasicObjectStorage(ObjectStorageSettings $objectStorageSettings): BasicObjectStorage
    {
        if ($objectStorageSettings->hasInvalidDriver()) {
            throw new InternalErrorException((string)$objectStorageSettings->getNotConfiguredReason());
        }
        /** @var class-string<ObjectStorageDriver> $driverClass */
        $driverClass = $objectStorageSettings->driverClass;
        return $driverClass::fromSettings($objectStorageSettings, $this->getObjectStorageLogger());
    }

    /**
     * Logs once per store and process why a store that names a driver is not configured (a store without a driver is
     * an intended state and stays silent).
     */
    protected function logNotConfiguredReasonOnce(ObjectStorageSettings $objectStorageSettings): void
    {
        if ($objectStorageSettings->driver === null || isset($this->loggedNotConfiguredStoreNames[$objectStorageSettings->name])) {
            return;
        }
        $this->loggedNotConfiguredStoreNames[$objectStorageSettings->name] = true;
        $this->getObjectStorageLogger()?->warning((string)$objectStorageSettings->getNotConfiguredReason());
    }

    /**
     * @return LoggerInterface|null the application logger for the stores (async-aws logs its retries there); null
     *                              without a container, e.g. in a unit test
     */
    protected function getObjectStorageLogger(): ?LoggerInterface
    {
        try {
            return DDDService::instance()->getLogger();
        } catch (Throwable) {
            return null;
        }
    }
}
