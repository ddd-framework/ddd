<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A multipart upload that was started and neither completed nor aborted.
 */
class ObjectStorageMultipartUpload extends ValueObject
{
    /** @var string The key the upload writes; empty when the store does not know it (an upload of an older driver) */
    public string $key = '';

    public string $uploadId = '';

    /** @var DateTime|null When the upload was started, as far as the store says */
    public ?DateTime $initiatedAt = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->uploadId);
    }
}
