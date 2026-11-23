<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the bytes of an allowed raster image say about themselves ({@see RasterImageDecoder::probe()}): never what a
 * client declared.
 */
class RasterImageInfo extends ValueObject
{
    /** @var string One of RasterImageDecoder::ALLOWED_FORMATS */
    public string $format;

    /** @var string The MIME type of the format */
    public string $mimeType;

    /** @var int Width in pixels */
    public int $width = 0;

    /** @var int Height in pixels */
    public int $height = 0;

    /** @var int Size of the bytes */
    public int $sizeBytes = 0;
}
