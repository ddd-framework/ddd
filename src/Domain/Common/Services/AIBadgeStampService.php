<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeGeometry;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeGlyph;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeGlyphPolygons;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeStampResult;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeStampSettings;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeTint;
use DDD\Domain\Common\Entities\MediaItems\ExifOrientation;
use DDD\Domain\Common\Entities\MediaItems\AIImageIptcTag;
use DDD\Domain\Common\Entities\MediaItems\AIImageKind;
use DDD\Domain\Common\Entities\MediaItems\ImageMetadata;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use Imagick;
use ImagickDraw;
use ImagickException;
use ImagickPixel;
use InvalidArgumentException;
use LengthException;
use UnexpectedValueException;

/**
 * Stamps a small round "AI" badge (frosted glass, the letters drawn as a vector shape, an optional ring) into a raster
 * image (JPEG, PNG, WebP) for one target display. An application calls it where it decides an image needs the mark,
 * for example right before it publishes an AI image on a social platform; nothing in the framework calls it. Which
 * images need the mark (a jurisdiction rule) is the application's decision; a website shows its badge as a CSS overlay
 * instead of stamping the file.
 *
 * - Size and place come from {@see AIBadgeGeometry}: CSS pixels of the target display, scaled by the image width, on
 *   the image as it displays; with an EXIF orientation the badge is turned so it displays upright in its corner.
 * - The pipeline follows the CSS model `backdrop-filter: blur() saturate()` + tint + ring + text: the background around
 *   the badge is blurred (Gaussian, sigma = blur) and saturated (the CSS saturate matrix), tinted, cut to a disc with
 *   an anti-aliased edge, ringed, and the glyph with its soft shadow drawn on top; drawing runs at 4× and is scaled down.
 * - The tint alpha is raised in steps until the glyph reaches the minimum WCAG contrast on the mean colour of the badge,
 *   up to the configured maximum (a very light corner gets a darker glass).
 * - Only the badge square changes; the image is written in its own format, a JPEG at its own quality (estimated from
 *   its quantization tables) and its own chroma subsampling. EXIF, IPTC and XMP of the input are put back where the
 *   re-encode dropped them ({@see ImageMetadata}, JPEG and PNG). A C2PA manifest cannot survive the changed pixels and
 *   is dropped; the IPTC digital source type of `$aiImageKind` is written when the result carries no XMP
 *   ({@see AIImageIptcTag}).
 * - Every failure throws; the service never returns the unstamped image as if it were stamped.
 */
class AIBadgeStampService extends Service
{
    /** @var string The config the shipped settings are read from, whole */
    public const string STAMP_CONFIG_PATH = 'Common.AIBadgeStamp';

    /** @var int The factor the disc mask, the ring and the glyph are drawn at before they are scaled down */
    public const int SUPERSAMPLING_FACTOR = 4;

    /** @var string[] The formats a badge is stamped into (raster only; a vector image is never stamped) */
    public const array STAMPABLE_FORMATS = [
        RasterImageDecoder::FORMAT_JPEG,
        RasterImageDecoder::FORMAT_PNG,
        RasterImageDecoder::FORMAT_WEBP,
    ];

    /** @var string The image property ImageMagick reports a JPEG's chroma subsampling in, and the write option */
    public const string JPEG_SAMPLING_FACTOR_PROPERTY = 'jpeg:sampling-factor';

    /** @var AIBadgeStampSettings|null The settings of the shipped config, built once per process */
    protected static ?AIBadgeStampSettings $shippedSettings = null;

    /**
     * The image with the badge stamped in for `$targetName`.
     *
     * @param string $aiImageKind {@see AIImageKind::GENERATED} or {@see AIImageKind::EDITED}: the IPTC digital source type
     * @param string|null $targetName A target of the settings; null: the default target
     * @param AIBadgeStampSettings|null $settings Null: the settings of the shipped `Common.AIBadgeStamp`
     * @throws InvalidArgumentException When the kind is not an AI kind, the format cannot be stamped, the image is
     *     animated, or the target is unknown.
     * @throws LengthException When the badge does not fit the image.
     * @throws UnexpectedValueException When the shipped config is missing or a metadata block of the input cannot be read.
     * @throws ImagickException When Imagick cannot decode or encode the image.
     */
    public function buildImageBytesWithAIBadge(
        string $imageBytes,
        string $aiImageKind,
        ?string $targetName = null,
        ?AIBadgeStampSettings $settings = null,
    ): AIBadgeStampResult {
        if (!AIImageKind::isAIKind($aiImageKind)) {
            throw new InvalidArgumentException('Only an AI-generated or AI-edited image gets the AI badge');
        }
        $settings ??= $this->getShippedSettings();
        $target = $settings->getTarget($targetName);
        // the format from the magic bytes, before any decoder sees them
        $format = RasterImageDecoder::detectFormat($imageBytes);
        if (!in_array($format, self::STAMPABLE_FORMATS, true)) {
            throw new InvalidArgumentException(sprintf(
                'The AI badge is stamped into %s only, not %s',
                implode(', ', self::STAMPABLE_FORMATS),
                $format ?? RasterImageDecoder::describeContentType($imageBytes),
            ));
        }
        $warnings = [];
        $image = RasterImageDecoder::readBlob($imageBytes);
        try {
            if ($image->getNumberImages() > 1) {
                throw new InvalidArgumentException('An animated image is not stamped');
            }
            $exifOrientation = ExifOrientation::getKnownOrientationForReadValue($image->getImageOrientation());
            // read before any operation changes the image: the quality is estimated from the input's tables
            $jpegQuality = $format === 'JPEG' ? $this->getJpegQualityOfInput($image, $settings) : null;
            $jpegSamplingFactor = $format === 'JPEG' ? (string)$image->getImageProperty(self::JPEG_SAMPLING_FACTOR_PROPERTY) : '';
            $geometry = AIBadgeGeometry::forImage($image->getImageWidth(), $image->getImageHeight(), $settings, $target, $exifOrientation);
            $frostedSquare = $this->buildFrostedSquare($image, $geometry, $settings);
            try {
                $badgeTint = $this->findTintReachingMinimumContrast($frostedSquare, $settings);
                if ($badgeTint->contrast < $settings->minimumContrast) {
                    $warnings[] = sprintf('The glyph reaches a contrast of %.2f only, below %.2f', $badgeTint->contrast, $settings->minimumContrast);
                }
                // built as it displays, then turned onto the stored pixels
                $this->orientSquareForDisplay($frostedSquare, $exifOrientation);
                $badge = $this->buildBadge($frostedSquare, $geometry, $settings, $badgeTint->tintAlpha);
                $this->orientSquareForStorage($badge, $exifOrientation);
            } finally {
                $frostedSquare->clear();
            }
            try {
                // composited unconverted onto CMYK, the badge's channels would be read as ink
                if ($image->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                    $badge->transformImageColorspace(Imagick::COLORSPACE_CMYK);
                }
                $image->compositeImage($badge, Imagick::COMPOSITE_OVER, $geometry->left, $geometry->top);
            } finally {
                $badge->clear();
            }
            if ($jpegQuality !== null) {
                $image->setImageCompressionQuality($jpegQuality);
                if ($jpegSamplingFactor !== '') {
                    $image->setOption(self::JPEG_SAMPLING_FACTOR_PROPERTY, $jpegSamplingFactor);
                }
            }
            RasterImageDecoder::setOutputFormat($image, $format);
            $stampedImageBytes = $image->getImageBlob();
        } finally {
            $image->clear();
        }
        $isC2paManifestDropped = AIImageIptcTag::isCarryingC2paManifest($imageBytes);
        if ($isC2paManifestDropped) {
            $warnings[] = 'The C2PA manifest of the input was dropped: the pixels changed, its signature would no longer hold';
        }
        if ($format === RasterImageDecoder::FORMAT_JPEG || $format === RasterImageDecoder::FORMAT_PNG) {
            $stampedImageBytes = ImageMetadata::fromImageBytes($imageBytes)->buildImageBytesWithMissingMetadataInserted($stampedImageBytes);
            $stampedImageBytes = AIImageIptcTag::buildImageBytesWithTagIfCarryingNeitherC2paNorXmp($stampedImageBytes, $aiImageKind);
        } else {
            $warnings[] = 'WebP: the input metadata is kept only as far as ImageMagick keeps it; the IPTC digital source type is not written';
        }
        return new AIBadgeStampResult(
            $stampedImageBytes,
            $format,
            $target->name,
            $geometry,
            $badgeTint->tintAlpha,
            $badgeTint->contrast,
            $jpegQuality,
            $isC2paManifestDropped,
            str_contains($stampedImageBytes, 'Iptc4xmpExt:DigitalSourceType'),
            $warnings,
        );
    }

    /**
     * Forgets the settings held for the process: the next stamp reads the config again (tests, a changed config).
     */
    public static function forgetShippedSettings(): void
    {
        static::$shippedSettings = null;
    }

    /**
     * Turns a square of the stored pixels the way a viewer displays the image (the EXIF orientation).
     *
     * @throws ImagickException
     */
    protected function orientSquareForDisplay(Imagick $square, int $exifOrientation): void
    {
        match ($exifOrientation) {
            ExifOrientation::MIRRORED => $square->flopImage(),
            ExifOrientation::UPSIDE_DOWN => $square->rotateImage(new ImagickPixel('transparent'), 180),
            ExifOrientation::FLIPPED => $square->flipImage(),
            ExifOrientation::TRANSPOSED => $square->transposeImage(),
            ExifOrientation::TURNED_RIGHT => $square->rotateImage(new ImagickPixel('transparent'), 90),
            ExifOrientation::TRANSVERSED => $square->transverseImage(),
            ExifOrientation::TURNED_LEFT => $square->rotateImage(new ImagickPixel('transparent'), 270),
            default => true,
        };
        $square->setImagePage(0, 0, 0, 0);
    }

    /**
     * Turns a square built as it displays back onto the stored pixels (the inverse of {@see orientSquareForDisplay()}).
     *
     * @throws ImagickException
     */
    protected function orientSquareForStorage(Imagick $square, int $exifOrientation): void
    {
        $inverseOrientation = match ($exifOrientation) {
            ExifOrientation::TURNED_RIGHT => ExifOrientation::TURNED_LEFT,
            ExifOrientation::TURNED_LEFT => ExifOrientation::TURNED_RIGHT,
            default => $exifOrientation,
        };
        $this->orientSquareForDisplay($square, $inverseOrientation);
    }

    /**
     * The settings of the shipped config, held for the process.
     *
     * @throws UnexpectedValueException When the config is missing.
     * @throws InvalidArgumentException When the config is invalid.
     */
    public function getShippedSettings(): AIBadgeStampSettings
    {
        if (static::$shippedSettings === null) {
            $stampConfig = Config::get(self::STAMP_CONFIG_PATH);
            if (!is_array($stampConfig)) {
                throw new UnexpectedValueException('The config ' . self::STAMP_CONFIG_PATH . ' is missing');
            }
            static::$shippedSettings = AIBadgeStampSettings::fromConfigArray($stampConfig);
        }
        return static::$shippedSettings;
    }

    /**
     * The badge square of the image, blurred and saturated: the region around it (3 sigma wider on every side, so the
     * blur runs out with real neighbours; at the image edge the edge pixels repeat) is blurred and saturated, then cut
     * to the square.
     *
     * @throws ImagickException
     */
    protected function buildFrostedSquare(Imagick $image, AIBadgeGeometry $geometry, AIBadgeStampSettings $settings): Imagick
    {
        $padding = (int)ceil(3 * $geometry->blurSigmaInPixels);
        $diameter = $geometry->badgeDiameterInPixels;
        $regionLeft = max(0, $geometry->left - $padding);
        $regionTop = max(0, $geometry->top - $padding);
        $regionRight = min($image->getImageWidth(), $geometry->left + $diameter + $padding);
        $regionBottom = min($image->getImageHeight(), $geometry->top + $diameter + $padding);
        $region = $image->getImageRegion($regionRight - $regionLeft, $regionBottom - $regionTop, $regionLeft, $regionTop);
        try {
            $region->setImagePage(0, 0, 0, 0);
            $region->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            $region->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_EDGE);
            if ($geometry->blurSigmaInPixels > 0) {
                $region->gaussianBlurImage(0, $geometry->blurSigmaInPixels);
            }
            $region->colorMatrixImage(static::buildSaturationMatrix($settings->saturation));
            $region->clampImage();
            $frostedSquare = $region->getImageRegion($diameter, $diameter, $geometry->left - $regionLeft, $geometry->top - $regionTop);
            $frostedSquare->setImagePage(0, 0, 0, 0);
            return $frostedSquare;
        } finally {
            $region->clear();
        }
    }

    /**
     * The tint alpha the badge gets and the contrast the glyph reaches with it: the configured alpha, raised in steps
     * of {@see AIBadgeStampSettings::TINT_ALPHA_STEP} until the contrast is reached or the maximum alpha is.
     *
     * @throws ImagickException
     */
    protected function findTintReachingMinimumContrast(Imagick $frostedSquare, AIBadgeStampSettings $settings): AIBadgeTint
    {
        $meanColour = $this->readMeanColourOverWhite($frostedSquare);
        $step = 0;
        while (true) {
            $tintAlpha = round(min($settings->tintAlpha + $step * AIBadgeStampSettings::TINT_ALPHA_STEP, $settings->maximumTintAlpha), 4);
            $contrast = static::computeGlyphContrast($meanColour, $tintAlpha, $settings);
            if ($contrast >= $settings->minimumContrast || $tintAlpha >= $settings->maximumTintAlpha) {
                return new AIBadgeTint($tintAlpha, $contrast);
            }
            $step++;
        }
    }

    /**
     * The mean colour of the square in sRGB 0–1 (red, green, blue), a transparent part counting as white.
     *
     * @return float[]
     * @throws ImagickException
     */
    protected function readMeanColourOverWhite(Imagick $frostedSquare): array
    {
        $meanImage = clone $frostedSquare;
        try {
            $meanImage->resizeImage(1, 1, Imagick::FILTER_BOX, 1);
            $meanPixel = $meanImage->getImagePixelColor(0, 0)->getColor(1);
        } finally {
            $meanImage->clear();
        }
        $alpha = (float)($meanPixel['a'] ?? 1.0);
        return [
            (float)$meanPixel['r'] * $alpha + (1 - $alpha),
            (float)$meanPixel['g'] * $alpha + (1 - $alpha),
            (float)$meanPixel['b'] * $alpha + (1 - $alpha),
        ];
    }

    /**
     * The badge: the frosted square tinted, cut to an anti-aliased disc, with the ring and the glyph on top.
     *
     * @throws ImagickException
     */
    protected function buildBadge(Imagick $frostedSquare, AIBadgeGeometry $geometry, AIBadgeStampSettings $settings, float $tintAlpha): Imagick
    {
        $diameter = $geometry->badgeDiameterInPixels;
        $badge = clone $frostedSquare;
        if (!$badge->getImageAlphaChannel()) {
            $badge->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
        }
        $tint = new Imagick();
        try {
            $tint->newImage($diameter, $diameter, new ImagickPixel(static::buildRgbaColour($settings->tintRgb, $tintAlpha)));
            $badge->compositeImage($tint, Imagick::COMPOSITE_OVER, 0, 0);
        } finally {
            $tint->clear();
        }
        $discMask = $this->buildDiscMask($diameter);
        try {
            $badge->compositeImage($discMask, Imagick::COMPOSITE_DSTIN, 0, 0);
        } finally {
            $discMask->clear();
        }
        $ringAndGlyph = $this->buildRingAndGlyph($geometry, $settings);
        try {
            $badge->compositeImage($ringAndGlyph, Imagick::COMPOSITE_OVER, 0, 0);
        } finally {
            $ringAndGlyph->clear();
        }
        return $badge;
    }

    /**
     * An opaque disc on a transparent square of `$diameter`, drawn at the supersampling factor and scaled down, so its
     * edge is anti-aliased.
     *
     * @throws ImagickException
     */
    protected function buildDiscMask(int $diameter): Imagick
    {
        $side = $diameter * self::SUPERSAMPLING_FACTOR;
        $centre = $side / 2;
        $discMask = new Imagick();
        $discMask->newImage($side, $side, new ImagickPixel('transparent'));
        $draw = new ImagickDraw();
        try {
            $draw->setFillColor(new ImagickPixel('white'));
            $draw->circle($centre, $centre, $centre, 0);
            $discMask->drawImage($draw);
        } finally {
            $draw->clear();
        }
        $discMask->resizeImage($diameter, $diameter, Imagick::FILTER_BOX, 1);
        return $discMask;
    }

    /**
     * The ring (inside the disc's edge) and the glyph with its soft shadow on a transparent square, drawn at the
     * supersampling factor and scaled down.
     *
     * @throws ImagickException
     */
    protected function buildRingAndGlyph(AIBadgeGeometry $geometry, AIBadgeStampSettings $settings): Imagick
    {
        $factor = self::SUPERSAMPLING_FACTOR;
        $diameter = $geometry->badgeDiameterInPixels;
        $side = $diameter * $factor;
        $centre = $side / 2;
        $ringAndGlyph = new Imagick();
        $ringAndGlyph->newImage($side, $side, new ImagickPixel('transparent'));
        $ringWidth = $geometry->borderWidthInPixels * $factor;
        if ($ringWidth > 0 && (float)$settings->borderRgba[3] > 0) {
            $ringDraw = new ImagickDraw();
            try {
                $ringDraw->setFillColor(new ImagickPixel('transparent'));
                $ringDraw->setStrokeColor(new ImagickPixel(static::buildRgbaColour($settings->borderRgba, (float)$settings->borderRgba[3])));
                $ringDraw->setStrokeWidth($ringWidth);
                $ringDraw->circle($centre, $centre, $centre, $ringWidth / 2);
                $ringAndGlyph->drawImage($ringDraw);
            } finally {
                $ringDraw->clear();
            }
        }
        $glyphPolygons = AIBadgeGlyph::buildPolygonsCentredInSquare($side, $geometry->glyphCapHeightInPixels * $factor);
        if ($geometry->glyphShadowSigmaInPixels > 0 && (float)$settings->glyphShadowRgba[3] > 0) {
            $glyphShadow = new Imagick();
            try {
                $glyphShadow->newImage($side, $side, new ImagickPixel('transparent'));
                $this->drawGlyph($glyphShadow, $glyphPolygons, $settings->glyphShadowRgba);
                $glyphShadow->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);
                $glyphShadow->gaussianBlurImage(0, $geometry->glyphShadowSigmaInPixels * $factor);
                $ringAndGlyph->compositeImage($glyphShadow, Imagick::COMPOSITE_OVER, 0, 0);
            } finally {
                $glyphShadow->clear();
            }
        }
        $this->drawGlyph($ringAndGlyph, $glyphPolygons, $settings->glyphRgba);
        $ringAndGlyph->resizeImage($diameter, $diameter, Imagick::FILTER_BOX, 1);
        return $ringAndGlyph;
    }

    /**
     * Fills the glyph's polygons in one path with the even-odd rule (the A's counter stays open).
     *
     * @param array<int, int|float> $rgba Red, green, blue 0–255 and alpha 0–1
     * @throws ImagickException
     */
    protected function drawGlyph(Imagick $canvas, AIBadgeGlyphPolygons $glyphPolygons, array $rgba): void
    {
        $draw = new ImagickDraw();
        try {
            $draw->setFillColor(new ImagickPixel(static::buildRgbaColour($rgba, (float)$rgba[3])));
            $draw->setFillRule(Imagick::FILLRULE_EVENODD);
            $draw->pathStart();
            foreach ($glyphPolygons->getElements() as $glyphPolygon) {
                $draw->pathMoveToAbsolute($glyphPolygon->xCoordinates[0], $glyphPolygon->yCoordinates[0]);
                for ($pointIndex = 1; $pointIndex < $glyphPolygon->countPoints(); $pointIndex++) {
                    $draw->pathLineToAbsolute($glyphPolygon->xCoordinates[$pointIndex], $glyphPolygon->yCoordinates[$pointIndex]);
                }
                $draw->pathClose();
            }
            $draw->pathFinish();
            $canvas->drawImage($draw);
        } finally {
            $draw->clear();
        }
    }

    /**
     * The quality the input JPEG was written at, estimated by ImageMagick from its quantization tables (exact for the
     * standard tables of libjpeg-based encoders); the configured fallback when it cannot be estimated.
     */
    protected function getJpegQualityOfInput(Imagick $image, AIBadgeStampSettings $settings): int
    {
        $estimatedQuality = $image->getImageCompressionQuality();
        return $estimatedQuality > 0 ? min(100, $estimatedQuality) : $settings->fallbackJpegQuality;
    }

    /**
     * The WCAG contrast between the glyph (its colour composited onto the tinted badge) and the tinted badge, for the
     * mean colour of the frosted square.
     *
     * @param float[] $meanColour sRGB 0–1: red, green, blue
     */
    protected static function computeGlyphContrast(array $meanColour, float $tintAlpha, AIBadgeStampSettings $settings): float
    {
        $glyphAlpha = (float)$settings->glyphRgba[3];
        $tintedColour = [];
        $glyphColour = [];
        for ($channel = 0; $channel < 3; $channel++) {
            $tintedColour[$channel] = $meanColour[$channel] * (1 - $tintAlpha) + ($settings->tintRgb[$channel] / 255) * $tintAlpha;
            $glyphColour[$channel] = ($settings->glyphRgba[$channel] / 255) * $glyphAlpha + $tintedColour[$channel] * (1 - $glyphAlpha);
        }
        $tintedLuminance = static::computeRelativeLuminance($tintedColour);
        $glyphLuminance = static::computeRelativeLuminance($glyphColour);
        return (max($tintedLuminance, $glyphLuminance) + 0.05) / (min($tintedLuminance, $glyphLuminance) + 0.05);
    }

    /**
     * The WCAG relative luminance of an sRGB colour (0–1 per channel).
     *
     * @param float[] $colour red, green, blue
     */
    protected static function computeRelativeLuminance(array $colour): float
    {
        return 0.2126 * static::linearizeSrgbChannel($colour[0])
            + 0.7152 * static::linearizeSrgbChannel($colour[1])
            + 0.0722 * static::linearizeSrgbChannel($colour[2]);
    }

    protected static function linearizeSrgbChannel(float $channelValue): float
    {
        return $channelValue <= 0.04045 ? $channelValue / 12.92 : (($channelValue + 0.055) / 1.055) ** 2.4;
    }

    /**
     * The CSS `saturate()` matrix (Filter Effects spec) as Imagick's 5×5 colour matrix; the fourth and fifth rows keep
     * the other channels as they are.
     *
     * @return float[]
     */
    protected static function buildSaturationMatrix(float $saturation): array
    {
        return [
            0.213 + 0.787 * $saturation, 0.715 - 0.715 * $saturation, 0.072 - 0.072 * $saturation, 0, 0,
            0.213 - 0.213 * $saturation, 0.715 + 0.285 * $saturation, 0.072 - 0.072 * $saturation, 0, 0,
            0.213 - 0.213 * $saturation, 0.715 - 0.715 * $saturation, 0.072 + 0.928 * $saturation, 0, 0,
            0, 0, 0, 1, 0,
            0, 0, 0, 0, 1,
        ];
    }

    /**
     * @param array<int, int|float> $rgb Red, green and blue 0–255 (a fourth value is ignored)
     */
    protected static function buildRgbaColour(array $rgb, float $alpha): string
    {
        return sprintf('rgba(%d,%d,%d,%.4F)', $rgb[0], $rgb[1], $rgb[2], $alpha);
    }
}
