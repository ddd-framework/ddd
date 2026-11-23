<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Untrusted;

use DDD\Infrastructure\Services\UntrustedInputRuntimeCheckService;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UnsupportedImageFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Output formats are set by their upper-case coder name (case-sensitive allow-list policies refuse `jpeg`).
 */
class RasterImageOutputFormatTest extends TestCase
{
    public function testFormatNamesAreUpperCaseCoderNames(): void
    {
        $this->assertSame('JPEG', RasterImageDecoder::normalizeFormatName('jpg'));
        $this->assertSame('JPEG', RasterImageDecoder::normalizeFormatName('jpeg'));
        $this->assertSame('WEBP', RasterImageDecoder::normalizeFormatName('webp'));
        $this->assertSame('PNG', RasterImageDecoder::normalizeFormatName(' Png '));
        $this->assertSame('HEIC', RasterImageDecoder::normalizeFormatName('heif'));
    }

    public function testInterpreterFormatIsNoOutputFormat(): void
    {
        $this->expectException(UnsupportedImageFormatException::class);
        RasterImageDecoder::normalizeFormatName('pdf');
    }

    public function testOutputFormatIsSetUpperCase(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('imagick extension not loaded');
        }
        $imagick = RasterImageDecoder::readBlob((string)base64_decode(UntrustedInputRuntimeCheckService::RASTER_PROBE_PNG_BASE64, true));
        $this->assertSame('JPEG', RasterImageDecoder::setOutputFormat($imagick, 'jpg'));
        $this->assertSame('JPEG', $imagick->getImageFormat());
        $this->assertSame(RasterImageDecoder::FORMAT_JPEG, RasterImageDecoder::detectFormat($imagick->getImageBlob()));
        $imagick->clear();
    }
}
