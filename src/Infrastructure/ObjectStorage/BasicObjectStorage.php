<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * The smallest object store: write, read, delete and check one object by key. Enough for a consumer that keeps its own
 * files by key (pictures, exports, cached renders) and needs no listing, presigned URL, multipart or range. A driver
 * of the application may implement only this; {@see ObjectStorage} extends it with everything the S3 and filesystem
 * drivers offer. Obtain a store by name from
 * {@see \DDD\Infrastructure\Services\ObjectStorageService::getBasicObjectStorage()}.
 *
 * Keys are `/`-separated paths without a leading slash; a store's configured prefix is applied by the driver. Every
 * method throws {@see InternalErrorException} when the backend fails; a missing object is not an error.
 */
interface BasicObjectStorage
{
    /**
     * Writes the object, replacing an existing one.
     *
     * @throws InternalErrorException
     */
    public function putObject(string $key, string $content, ?string $contentType = null): void;

    /**
     * The object's content; null when there is no object under the key.
     *
     * @throws InternalErrorException
     */
    public function getObjectContent(string $key): ?string;

    /**
     * Removes the object; a missing object is fine.
     *
     * @throws InternalErrorException
     */
    public function deleteObject(string $key): void;

    /** @throws InternalErrorException */
    public function objectExists(string $key): bool;
}
