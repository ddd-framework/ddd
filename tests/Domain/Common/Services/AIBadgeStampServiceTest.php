<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services;

use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeStampResult;
use DDD\Domain\Common\Entities\MediaItems\AIBadges\AIBadgeStampSettings;
use DDD\Domain\Common\Entities\MediaItems\AIImageIptcTag;
use DDD\Domain\Common\Entities\MediaItems\AIImageKind;
use DDD\Domain\Common\Entities\MediaItems\ExifOrientation;
use DDD\Domain\Common\Entities\MediaItems\ImageMetadata;
use DDD\Domain\Common\Services\AIBadgeStampService;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Testing\MediaItems\ImageMarkFixtureBuilder;
use DDD\Testing\MediaItems\ImageStructureWalker;
use Imagick;
use ImagickPixel;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The badge stamped into synthetic images: the JPEG's quality and subsampling kept, nothing outside the badge square
 * changed, the tint raised on a light corner, the C2PA manifest dropped with a warning, the input's metadata put back
 * and the IPTC digital source type written, an EXIF orientation honoured, refused inputs.
 */
class AIBadgeStampServiceTest extends TestCase
{
    protected AIBadgeStampService $aIBadgeStampService;

    protected AIBadgeStampSettings $settings;

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('needs ext-imagick');
        }
        $this->aIBadgeStampService = new AIBadgeStampService();
        $this->settings = AIBadgeStampSettings::fromConfigArray(require dirname(__DIR__, 4) . '/config/app/Common/AIBadgeStamp.php');
    }

    public function testAJpegKeepsItsQualityAndSubsampling(): void
    {
        foreach ([[95, '2x2,1x1,1x1'], [100, '1x1,1x1,1x1']] as [$quality, $samplingFactor]) {
            $result = $this->stamp($this->buildJpeg(1080, 1350, $quality, $samplingFactor));

            $stampedImage = RasterImageDecoder::readBlob($result->imageBytes);
            self::assertSame($quality, $result->jpegQuality);
            self::assertSame($quality, $stampedImage->getImageCompressionQuality(), "quality $quality");
            self::assertSame($samplingFactor, $stampedImage->getImageProperty('jpeg:sampling-factor'), "subsampling $samplingFactor");
            self::assertSame(66, $result->geometry->badgeDiameterInPixels);
        }
    }

    public function testNoPixelOutsideTheBadgeSquareOfAPngChanges(): void
    {
        $pngBytes = $this->buildImageBytes(400, 300, 'PNG');

        $result = $this->stamp($pngBytes);

        $geometry = $result->geometry;
        $originalImage = RasterImageDecoder::readBlob($pngBytes);
        $stampedImage = RasterImageDecoder::readBlob($result->imageBytes);
        $originalSquare = $originalImage->getImageRegion($geometry->badgeDiameterInPixels, $geometry->badgeDiameterInPixels, $geometry->left, $geometry->top);
        self::assertGreaterThan(0.0, $stampedImage->compareImages($originalImage, Imagick::METRIC_ABSOLUTEERRORMETRIC)[1], 'the badge is there');
        $stampedImage->compositeImage($originalSquare, Imagick::COMPOSITE_COPY, $geometry->left, $geometry->top);
        self::assertSame(0.0, (float)$stampedImage->compareImages($originalImage, Imagick::METRIC_ABSOLUTEERRORMETRIC)[1]);
    }

    public function testALightCornerRaisesTheTintAndADarkOneKeepsIt(): void
    {
        $whiteResult = $this->stamp($this->buildImageBytes(1080, 1080, 'PNG', 'white'));
        self::assertGreaterThan(0.35, $whiteResult->tintAlpha);
        self::assertGreaterThanOrEqual(3.0, $whiteResult->contrast);

        $darkResult = $this->stamp($this->buildImageBytes(1080, 1080, 'PNG', 'rgb(20,30,40)'));
        self::assertSame(0.35, $darkResult->tintAlpha);
    }

    public function testAC2paManifestIsDroppedWithAWarning(): void
    {
        $jpegBytes = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
            $this->buildJpeg(800, 800, 92, '2x2,1x1,1x1'),
            ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP11, ImageMarkFixtureBuilder::buildJumbfApp11Payload()),
        );
        self::assertTrue(AIImageIptcTag::isCarryingC2paManifest($jpegBytes));

        $result = $this->stamp($jpegBytes);

        self::assertTrue($result->isC2paManifestDropped);
        self::assertSame([], ImageStructureWalker::listJpegSegmentsOfKind($result->imageBytes, ImageStructureWalker::JPEG_KIND_APP11));
        self::assertStringContainsString('C2PA', implode(' ', $result->warnings));
    }

    public function testTheInputsExifAndXmpArePutBack(): void
    {
        $jpegBytes = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
            $this->buildJpeg(800, 800, 92, '2x2,1x1,1x1'),
            ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, ImageMarkFixtureBuilder::buildExifApp1Payload())
            . ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, ImageMarkFixtureBuilder::buildXmpApp1Payload()),
        );

        $result = $this->stamp($jpegBytes);

        $stampedMetadata = ImageMetadata::fromImageBytes($result->imageBytes);
        self::assertStringContainsString(ImageMarkFixtureBuilder::EXIF_MAKE, (string)$stampedMetadata->exifTiffBlock);
        self::assertSame(ImageMetadata::fromImageBytes($jpegBytes)->xmpPacket, $stampedMetadata->xmpPacket, 'the input XMP, no second packet');
    }

    public function testWithoutXmpTheDigitalSourceTypeOfTheKindIsWritten(): void
    {
        $expectedSourceTypesByKind = [
            AIImageKind::GENERATED => 'trainedAlgorithmicMedia',
            AIImageKind::EDITED => 'compositeWithTrainedAlgorithmicMedia',
        ];
        foreach ([$this->buildJpeg(600, 600, 92, '2x2,1x1,1x1'), $this->buildImageBytes(600, 600, 'PNG')] as $imageBytes) {
            foreach ($expectedSourceTypesByKind as $aiImageKind => $expectedSourceType) {
                $result = $this->stamp($imageBytes, $aiImageKind);

                self::assertTrue($result->isCarryingDigitalSourceType);
                self::assertStringContainsString(
                    'digitalsourcetype/' . $expectedSourceType . '"',
                    (string)ImageMetadata::fromImageBytes($result->imageBytes)->xmpPacket,
                );
            }
        }
    }

    public function testATurnedJpegGetsTheBadgeInTheCornerItDisplaysIn(): void
    {
        // stored 1350×1080 (landscape), displayed turned right as 1080×1350: the displayed bottom right is stored top right
        $jpegBytes = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
            $this->buildJpeg(1350, 1080, 92, '2x2,1x1,1x1', 'rgb(20,30,40)'),
            ImageMarkFixtureBuilder::buildJpegSegment(
                ImageMarkFixtureBuilder::MARKER_APP1,
                ImageMarkFixtureBuilder::buildExifApp1Payload(null, ExifOrientation::TURNED_RIGHT),
            ),
        );

        $result = $this->stamp($jpegBytes);

        self::assertSame([], array_filter($result->warnings, [$this, 'isOrientationWarning']));
        self::assertSame(ExifOrientation::TURNED_RIGHT, $result->geometry->exifOrientation);
        self::assertSame([1251, 33], [$result->geometry->left, $result->geometry->top]);
        $stampedImage = RasterImageDecoder::readBlob($result->imageBytes);
        self::assertSame(ExifOrientation::TURNED_RIGHT, $stampedImage->getImageOrientation(), 'the orientation is kept');
        $badgeCentre = $stampedImage->getImagePixelColor(1251 + 33, 33 + 33)->getColor();
        $storedBottomRight = $stampedImage->getImagePixelColor(1350 - 66, 1080 - 66)->getColor();
        self::assertGreaterThan(100, $badgeCentre['r'], 'the white glyph at the stored top right');
        self::assertLessThan(60, $storedBottomRight['r'], 'nothing at the stored bottom right');
    }

    public function testACmykJpegStaysCmyk(): void
    {
        $image = new Imagick();
        $image->newImage(600, 600, new ImagickPixel('rgb(200,120,40)'));
        $image->transformImageColorspace(Imagick::COLORSPACE_CMYK);
        RasterImageDecoder::setOutputFormat($image, 'JPEG');
        $image->setImageCompressionQuality(90);

        $result = $this->stamp($image->getImageBlob());

        self::assertSame(Imagick::COLORSPACE_CMYK, RasterImageDecoder::readBlob($result->imageBytes)->getImageColorspace());
    }

    public function testAWebpIsStampedWithItsWarning(): void
    {
        if (Imagick::queryFormats('WEBP') === []) {
            self::markTestSkipped('ImageMagick without WebP');
        }

        $result = $this->stamp($this->buildImageBytes(600, 600, 'WEBP'));

        self::assertSame(RasterImageDecoder::FORMAT_WEBP, $result->format);
        self::assertSame(RasterImageDecoder::FORMAT_WEBP, RasterImageDecoder::detectFormat($result->imageBytes));
        self::assertStringContainsString('WebP', implode(' ', $result->warnings));
    }

    public function testNotAIIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->stamp($this->buildImageBytes(400, 400, 'PNG'), AIImageKind::NOT_AI);
    }

    public function testAnSvgIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->stamp('<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><rect width="400" height="400"/></svg>');
    }

    public function testAnAnimatedGifIsRefused(): void
    {
        $animation = new Imagick();
        foreach (['red', 'blue'] as $frameColour) {
            $frame = new Imagick();
            $frame->newImage(400, 400, new ImagickPixel($frameColour));
            RasterImageDecoder::setOutputFormat($frame, 'GIF');
            $animation->addImage($frame);
        }
        RasterImageDecoder::setOutputFormat($animation, 'GIF');

        $this->expectException(InvalidArgumentException::class);
        $this->stamp($animation->getImagesBlob());
    }

    /** Whether a warning is about the EXIF orientation (the stamp honours it and warns no more). */
    public function isOrientationWarning(string $warning): bool
    {
        return str_contains($warning, 'orientation');
    }

    protected function stamp(string $imageBytes, string $aiImageKind = AIImageKind::GENERATED): AIBadgeStampResult
    {
        return $this->aIBadgeStampService->buildImageBytesWithAIBadge($imageBytes, $aiImageKind, null, $this->settings);
    }

    /** A JPEG with a gradient, written at `$quality` with `$samplingFactor`. */
    protected function buildJpeg(int $width, int $height, int $quality, string $samplingFactor, ?string $colour = null): string
    {
        $image = $this->buildCanvas($width, $height, $colour);
        RasterImageDecoder::setOutputFormat($image, 'JPEG');
        $image->setImageCompressionQuality($quality);
        $image->setOption('jpeg:sampling-factor', $samplingFactor);
        return $image->getImageBlob();
    }

    protected function buildImageBytes(int $width, int $height, string $format, ?string $colour = null): string
    {
        $image = $this->buildCanvas($width, $height, $colour);
        RasterImageDecoder::setOutputFormat($image, $format);
        return $image->getImageBlob();
    }

    /** A plain canvas of `$colour`, or a gradient from orange to blue. */
    protected function buildCanvas(int $width, int $height, ?string $colour): Imagick
    {
        $image = new Imagick();
        if ($colour !== null) {
            $image->newImage($width, $height, new ImagickPixel($colour));
        } else {
            $image->newPseudoImage($width, $height, 'gradient:orange-navy');
        }
        return $image;
    }
}
