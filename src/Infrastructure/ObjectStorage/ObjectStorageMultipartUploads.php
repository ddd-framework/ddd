<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Open multipart uploads of an object store.
 *
 * @property ObjectStorageMultipartUpload[] $elements
 * @method ObjectStorageMultipartUpload|null first()
 * @method ObjectStorageMultipartUpload|null getByUniqueKey(string $uniqueKey)
 * @method ObjectStorageMultipartUpload[] getElements()
 */
class ObjectStorageMultipartUploads extends ObjectSet
{
}
