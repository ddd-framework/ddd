<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One uploaded part of a multipart upload: its number (1 to 10,000), the ETag the store answered and its size.
 */
class ObjectStorageUploadedPart extends ValueObject
{
    public int $partNumber = 0;

    public string $etag = '';

    /** @var int|null Size in bytes */
    public ?int $size = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->partNumber);
    }
}
