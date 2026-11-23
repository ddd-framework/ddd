<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The uploaded parts of a multipart upload, in part number order.
 *
 * @property ObjectStorageUploadedPart[] $elements
 * @method ObjectStorageUploadedPart|null first()
 * @method ObjectStorageUploadedPart|null getByUniqueKey(string $uniqueKey)
 * @method ObjectStorageUploadedPart[] getElements()
 */
class ObjectStorageUploadedParts extends ObjectSet
{
    /** The sum of the parts' sizes; parts without a size count 0 */
    public function getTotalSize(): int
    {
        $totalSize = 0;
        foreach ($this->getElements() as $uploadedPart) {
            $totalSize += $uploadedPart->size ?? 0;
        }
        return $totalSize;
    }
}
