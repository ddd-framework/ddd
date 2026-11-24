<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * An inclusive byte range of an object: from `firstByte` to `lastByte` (null = to the end), as in HTTP `Range`.
 */
class ObjectStorageByteRange extends ValueObject
{
    public int $firstByte = 0;

    /** @var int|null Inclusive; null = to the end of the object */
    public ?int $lastByte = null;

    public static function fromFirstAndLastByte(int $firstByte, ?int $lastByte = null): static
    {
        $byteRange = new static();
        $byteRange->firstByte = max(0, $firstByte);
        $byteRange->lastByte = $lastByte;
        return $byteRange;
    }

    /** The range cut to an object of `$objectSize` bytes; null when it starts at or beyond the end */
    public function cutToObjectSize(int $objectSize): ?static
    {
        if ($this->firstByte >= $objectSize || ($this->lastByte !== null && $this->lastByte < $this->firstByte)) {
            return null;
        }
        $lastByte = $this->lastByte === null ? $objectSize - 1 : min($this->lastByte, $objectSize - 1);
        return static::fromFirstAndLastByte($this->firstByte, $lastByte);
    }

    /** Number of bytes; null for an open range */
    public function getLength(): ?int
    {
        return $this->lastByte === null ? null : $this->lastByte - $this->firstByte + 1;
    }

    /** The value of an HTTP `Range` request header (`bytes=0-99`, `bytes=100-`) */
    public function toRangeHeaderValue(): string
    {
        return 'bytes=' . $this->firstByte . '-' . ($this->lastByte ?? '');
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->firstByte . '-' . ($this->lastByte ?? ''));
    }
}
