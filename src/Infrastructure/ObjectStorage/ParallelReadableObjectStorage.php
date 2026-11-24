<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * An object store that reads several objects at once: the S3 driver sends every GET before it waits for the first
 * answer (one HTTP request each, all in flight together), the filesystem driver reads them one after another. A
 * caller checks `instanceof` and reads one by one through {@see ObjectStorage::getObjectContent()} otherwise.
 */
interface ParallelReadableObjectStorage extends ObjectStorage
{
    /**
     * The contents of several objects, key => content, with null for a missing object (every asked key answers).
     *
     * @param string[] $keys
     * @return array<string, string|null>
     * @throws InternalErrorException when the backend fails for one of the keys
     */
    public function getObjectContents(array $keys): array;
}
