<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use ImagickException;

/**
 * The bytes are no raster image of an allowed format (JPEG, PNG, GIF, WebP, HEIC/HEIF), so they were never handed to
 * Imagick ({@see RasterImageDecoder}). An ImagickException on purpose: every caller that already turns an undecodable
 * image into its own error treats a refused format the same way.
 */
class UnsupportedImageFormatException extends ImagickException
{
    /** @var string|null What the magic bytes look like ('unknown' when nothing matched) */
    public ?string $detectedType = null;

    /**
     * @param string $detectedType
     * @return static
     */
    public static function forDetectedType(string $detectedType): static
    {
        $exception = new static(
            'Image format not allowed (detected: ' . $detectedType . '); allowed: ' . implode(', ', RasterImageDecoder::ALLOWED_FORMATS)
        );
        $exception->detectedType = $detectedType;
        return $exception;
    }
}
