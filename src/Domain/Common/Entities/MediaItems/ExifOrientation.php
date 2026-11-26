<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems;

/**
 * The EXIF orientation values (tag 0x0112): how a viewer turns or mirrors the stored pixels of an image to display it
 * upright. Pure: no Imagick, no service.
 *
 * Holds constants only, is never serialized or persisted, and is therefore not a DDD value object (the same ruling as
 * {@see ImageMetadata}).
 */
class ExifOrientation
{
    /** @var int EXIF orientation 1: stored as it displays */
    public const int UPRIGHT = 1;

    /** @var int EXIF orientation 2: a viewer mirrors the stored image left to right */
    public const int MIRRORED = 2;

    /** @var int EXIF orientation 3: a viewer turns the stored image by 180° */
    public const int UPSIDE_DOWN = 3;

    /** @var int EXIF orientation 4: a viewer mirrors the stored image top to bottom */
    public const int FLIPPED = 4;

    /** @var int EXIF orientation 5: a viewer mirrors the stored image across its top-left to bottom-right diagonal */
    public const int TRANSPOSED = 5;

    /** @var int EXIF orientation 6: a viewer turns the stored image 90° clockwise */
    public const int TURNED_RIGHT = 6;

    /** @var int EXIF orientation 7: a viewer mirrors the stored image across its top-right to bottom-left diagonal */
    public const int TRANSVERSED = 7;

    /** @var int EXIF orientation 8: a viewer turns the stored image 90° anticlockwise */
    public const int TURNED_LEFT = 8;

    /**
     * The known orientation a read value stands for: the value itself from 1 to 8; {@see UPRIGHT} for 0 (undefined)
     * and for any value outside 1 to 8.
     */
    public static function getKnownOrientationForReadValue(int $readOrientationValue): int
    {
        return match ($readOrientationValue) {
            self::MIRRORED,
            self::UPSIDE_DOWN,
            self::FLIPPED,
            self::TRANSPOSED,
            self::TURNED_RIGHT,
            self::TRANSVERSED,
            self::TURNED_LEFT => $readOrientationValue,
            default => self::UPRIGHT,
        };
    }

    /**
     * Whether a viewer displays the stored image sideways (orientations 5 to 8): its displayed width is the stored
     * height and its displayed height the stored width.
     */
    public static function isSideways(int $orientation): bool
    {
        return in_array($orientation, [self::TRANSPOSED, self::TURNED_RIGHT, self::TRANSVERSED, self::TURNED_LEFT], true);
    }
}
