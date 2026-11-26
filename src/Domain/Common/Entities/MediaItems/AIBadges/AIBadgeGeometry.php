<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems\AIBadges;

use DDD\Domain\Common\Entities\MediaItems\ExifOrientation;
use LengthException;

/**
 * Where and how large the badge is drawn on one image for one target, in image pixels. Every length of the settings is
 * a CSS pixel of the target display and is multiplied by `scale = displayedImageWidth / displayWidthInCssPixels`, so
 * the badge looks the same size on the target display whatever the width of the file.
 *
 * - badge diameter: round(badgeSize × scale), at least the minimum in pixels, at most the maximum share of the shorter
 *   side (the upper bound wins on a tiny image);
 * - margin: round(diameter × marginShareOfBadge), taken from the bounded diameter so the proportion holds after bounding;
 * - a target's safe zone pushes the badge further in than the margin on its edges.
 *
 * Corner, safe zones and size are decided on the image as a viewer DISPLAYS it (`displayLeft`, `displayTop`). For an
 * image with an EXIF orientation other than upright, `left` and `top` are the same square on the STORED pixels, where
 * the badge is composited; the service turns the badge with the orientation's inverse, so it displays upright.
 *
 * Pure: no Imagick, no service; never serialized or persisted, and therefore not a DDD value object.
 */
class AIBadgeGeometry
{
    public function __construct(
        public readonly float $scale,
        public readonly int $badgeDiameterInPixels,
        public readonly int $marginInPixels,
        public readonly int $left,
        public readonly int $top,
        public readonly float $blurSigmaInPixels,
        public readonly int $borderWidthInPixels,
        public readonly float $glyphCapHeightInPixels,
        public readonly float $glyphShadowSigmaInPixels,
        public readonly int $displayLeft = 0,
        public readonly int $displayTop = 0,
        public readonly int $exifOrientation = ExifOrientation::UPRIGHT,
    ) {
    }

    /**
     * The geometry for an image of `$storedWidth` × `$storedHeight` stored pixels with `$exifOrientation`.
     *
     * @throws LengthException When the badge and its offsets do not fit the image.
     */
    public static function forImage(
        int $storedWidth,
        int $storedHeight,
        AIBadgeStampSettings $settings,
        AIBadgeStampTarget $target,
        int $exifOrientation = ExifOrientation::UPRIGHT,
    ): static {
        $exifOrientation = ExifOrientation::getKnownOrientationForReadValue($exifOrientation);
        $isSideways = ExifOrientation::isSideways($exifOrientation);
        $imageWidth = $isSideways ? $storedHeight : $storedWidth;
        $imageHeight = $isSideways ? $storedWidth : $storedHeight;
        $scale = $imageWidth / $target->displayWidthInCssPixels;
        $upperBound = (int)floor($settings->maximumBadgeShareOfShorterSide * min($imageWidth, $imageHeight));
        $diameter = min(
            max((int)round($settings->badgeSizeInCssPixels * $scale), $settings->minimumBadgeInPixels),
            $upperBound,
        );
        if ($diameter < 1) {
            throw new LengthException(sprintf('An image of %dx%d is too small for the AI badge', $imageWidth, $imageHeight));
        }
        $margin = (int)round($diameter * $settings->marginShareOfBadge);
        $offsetLeft = max($margin, (int)round($target->leftSafeZoneShare * $imageWidth));
        $offsetRight = max($margin, (int)round($target->rightSafeZoneShare * $imageWidth));
        $offsetTop = max($margin, (int)round($target->topSafeZoneShare * $imageHeight));
        $offsetBottom = max($margin, (int)round($target->bottomSafeZoneShare * $imageHeight));
        $isLeftCorner = in_array($settings->corner, [AIBadgeStampSettings::CORNER_TOP_LEFT, AIBadgeStampSettings::CORNER_BOTTOM_LEFT], true);
        $isTopCorner = in_array($settings->corner, [AIBadgeStampSettings::CORNER_TOP_LEFT, AIBadgeStampSettings::CORNER_TOP_RIGHT], true);
        $displayLeft = $isLeftCorner ? $offsetLeft : $imageWidth - $offsetRight - $diameter;
        $displayTop = $isTopCorner ? $offsetTop : $imageHeight - $offsetBottom - $diameter;
        if ($displayLeft < 0 || $displayTop < 0 || $displayLeft + $diameter > $imageWidth || $displayTop + $diameter > $imageHeight) {
            throw new LengthException(
                sprintf('The AI badge of %d px with its offsets does not fit an image of %dx%d', $diameter, $imageWidth, $imageHeight)
            );
        }
        return new static(
            $scale,
            $diameter,
            $margin,
            static::computeStoredLeft($displayLeft, $displayTop, $diameter, $storedWidth, $storedHeight, $exifOrientation),
            static::computeStoredTop($displayLeft, $displayTop, $diameter, $storedWidth, $storedHeight, $exifOrientation),
            $settings->blurInCssPixels * $scale,
            // no ring at width 0; any other width is at least one pixel
            $settings->borderWidthInCssPixels > 0 ? max(1, (int)round($settings->borderWidthInCssPixels * $scale)) : 0,
            $diameter * $settings->glyphCapHeightShareOfBadge,
            $settings->glyphShadowBlurInCssPixels * $scale,
            $displayLeft,
            $displayTop,
            $exifOrientation,
        );
    }

    /**
     * The left edge on the stored pixels of the square a viewer displays at (`$displayLeft`, `$displayTop`).
     */
    public static function computeStoredLeft(int $displayLeft, int $displayTop, int $diameter, int $storedWidth, int $storedHeight, int $exifOrientation): int
    {
        return match ($exifOrientation) {
            ExifOrientation::MIRRORED, ExifOrientation::UPSIDE_DOWN => $storedWidth - $displayLeft - $diameter,
            ExifOrientation::TRANSPOSED, ExifOrientation::TURNED_RIGHT => $displayTop,
            ExifOrientation::TRANSVERSED, ExifOrientation::TURNED_LEFT => $storedWidth - $displayTop - $diameter,
            default => $displayLeft,
        };
    }

    /**
     * The top edge on the stored pixels of the square a viewer displays at (`$displayLeft`, `$displayTop`).
     */
    public static function computeStoredTop(int $displayLeft, int $displayTop, int $diameter, int $storedWidth, int $storedHeight, int $exifOrientation): int
    {
        return match ($exifOrientation) {
            ExifOrientation::UPSIDE_DOWN, ExifOrientation::FLIPPED => $storedHeight - $displayTop - $diameter,
            ExifOrientation::TRANSPOSED, ExifOrientation::TURNED_LEFT => $displayLeft,
            ExifOrientation::TURNED_RIGHT, ExifOrientation::TRANSVERSED => $storedHeight - $displayLeft - $diameter,
            default => $displayTop,
        };
    }
}
