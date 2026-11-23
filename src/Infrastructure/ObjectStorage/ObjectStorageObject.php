<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One object of a listing or a head request: its key (without the store's prefix), size and last modification; a head
 * request also fills content type, ETag and the SHA-256 checksum the store keeps (null when it keeps none).
 */
class ObjectStorageObject extends ValueObject
{
    public string $key = '';

    /** @var int|null Size in bytes */
    public ?int $size = null;

    public ?DateTime $lastModified = null;

    /** @var string|null Head only: the stored content type */
    public ?string $contentType = null;

    /** @var string|null Head only: the store's ETag, without quotes */
    public ?string $etag = null;

    /** @var string|null Head only: the SHA-256 checksum the store keeps (base64, as S3 answers it); null = none kept */
    public ?string $checksumSha256 = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->key);
    }
}
