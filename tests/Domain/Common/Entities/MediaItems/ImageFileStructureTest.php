<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\MediaItems;

use DDD\Domain\Common\Entities\MediaItems\ImageFileStructure;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The structural end check of the post image fit check: a JPEG walked from SOI through every marker segment and
 * every scan's entropy-coded data reaches its first EOI at the last two bytes of the file; a PNG starts with IHDR,
 * has every CRC valid, no acTL chunk, and IEND as the last chunk ending at the last byte. Hand-built bytes only: no
 * decoder, no Imagick.
 *
 * The "criterion N" labels number the cases of the specification these classes were written against.
 */
class ImageFileStructureTest extends TestCase
{
    /** @dataProvider provideJpegStructures */
    #[DataProvider('provideJpegStructures')]
    public function testJpegStructure(string $jpegBytes, bool $isEndingWhereItsImageEnds): void
    {
        self::assertSame($isEndingWhereItsImageEnds, ImageFileStructure::isJpegEndingAtItsFirstEndOfImage($jpegBytes));
        self::assertSame($isEndingWhereItsImageEnds, ImageFileStructure::isEndingWhereItsImageEnds($jpegBytes));
    }

    /** @dataProvider providePngStructures */
    #[DataProvider('providePngStructures')]
    public function testPngStructure(string $pngBytes, bool $isEndingWhereItsImageEnds): void
    {
        self::assertSame($isEndingWhereItsImageEnds, ImageFileStructure::isSingleFramePngEndingAtItsEndChunk($pngBytes));
        self::assertSame($isEndingWhereItsImageEnds, ImageFileStructure::isEndingWhereItsImageEnds($pngBytes));
    }

    public function testOtherFormatsDoNotEndWhereAnImageEnds(): void
    {
        self::assertFalse(ImageFileStructure::isEndingWhereItsImageEnds('GIF89a' . str_repeat("\0", 20)));
        self::assertFalse(ImageFileStructure::isEndingWhereItsImageEnds(''));
    }

    /** @return iterable<string, array{string, bool}> criterion 30 */
    public static function provideJpegStructures(): iterable
    {
        yield 'baseline with restart markers and stuffed bytes in the entropy-coded data' => [
            self::buildJpeg([self::buildScan("\x12\x34\xFF\x00\x56\xFF\xD0\x78\xFF\xD7\x9A\xFF\x00")]),
            true,
        ];
        yield 'fill bytes before a marker in the header and before EOI' => [
            "\xFF\xD8" . self::buildApp0Segment() . "\xFF\xFF" . self::buildFrameHeaderSegments()
            . self::buildScan("\x12\x34") . "\xFF\xFF\xFF\xD9",
            true,
        ];
        yield 'several scans separated by DHT' => [
            self::buildJpeg([
                self::buildScan("\x11\x22\xFF\x00"),
                self::buildHuffmanTableSegment() . self::buildScan("\x33\x44\xFF\xD1\x55"),
                self::buildHuffmanTableSegment() . self::buildScan("\x66"),
            ]),
            true,
        ];
        yield 'an Exif APP1 holding a thumbnail with its own SOI and EOI' => [
            "\xFF\xD8" . self::buildApp0Segment()
            . self::buildSegment(0xE1, "Exif\0\0" . "II\x2A\x00" . pack('V', 8) . "\xFF\xD8\xFF\xDB\x00\x02\xFF\xD9")
            . self::buildFrameHeaderSegments() . self::buildScan("\x12") . "\xFF\xD9",
            true,
        ];
        yield 'bytes after the first EOI' => [
            self::buildJpeg([self::buildScan("\x12\x34")]) . 'trailing',
            false,
        ];
        yield 'a second JPEG after the first EOI' => [
            self::buildJpeg([self::buildScan("\x12\x34")]) . self::buildJpeg([self::buildScan("\x56")]),
            false,
        ];
        yield 'no EOI (cut inside the entropy-coded data)' => [
            substr(self::buildJpeg([self::buildScan("\x12\x34\x56\x78")]), 0, -3),
            false,
        ];
        yield 'no EOI (cut inside a marker segment)' => [
            "\xFF\xD8" . self::buildApp0Segment() . substr(self::buildFrameHeaderSegments(), 0, 20),
            false,
        ];
        yield 'EOI without any scan' => [
            "\xFF\xD8" . self::buildApp0Segment() . self::buildFrameHeaderSegments() . "\xFF\xD9",
            false,
        ];
        yield 'not a JPEG' => [
            "\x89PNG\r\n\x1A\n" . str_repeat("\0", 20),
            false,
        ];
    }

    /** @return iterable<string, array{string, bool}> criterion 31 */
    public static function providePngStructures(): iterable
    {
        $ihdrChunk = self::buildPngChunk('IHDR', pack('NN', 4, 4) . "\x08\x02\x00\x00\x00");
        $idatChunk = self::buildPngChunk('IDAT', gzcompress(str_repeat("\x00" . str_repeat("\x80", 12), 4)));
        $iendChunk = self::buildPngChunk('IEND', '');
        $pngSignature = "\x89PNG\r\n\x1A\n";

        yield 'a valid PNG' => [$pngSignature . $ihdrChunk . $idatChunk . $iendChunk, true];
        yield 'a valid PNG with an ancillary chunk before IDAT' => [
            $pngSignature . $ihdrChunk . self::buildPngChunk('tEXt', "Comment\0fixture") . $idatChunk . $iendChunk,
            true,
        ];
        yield 'a wrong CRC' => [
            $pngSignature . $ihdrChunk . substr($idatChunk, 0, -4) . pack('N', 0x12345678) . $iendChunk,
            false,
        ];
        yield 'an acTL chunk' => [
            $pngSignature . $ihdrChunk . self::buildPngChunk('acTL', pack('NN', 1, 0)) . $idatChunk . $iendChunk,
            false,
        ];
        yield 'a first chunk that is not IHDR (tEXt before IHDR, every CRC valid)' => [
            $pngSignature . self::buildPngChunk('tEXt', "Comment\0fixture") . $ihdrChunk . $idatChunk . $iendChunk,
            false,
        ];
        yield 'bytes after IEND' => [$pngSignature . $ihdrChunk . $idatChunk . $iendChunk . 'trailing', false];
        yield 'no IEND' => [$pngSignature . $ihdrChunk . $idatChunk, false];
        yield 'a chunk running past the end of the file' => [$pngSignature . $ihdrChunk . substr($idatChunk, 0, -2), false];
    }

    /** SOI, APP0, the frame header segments, the given scans (each SOS + data, DHT allowed before), EOI. */
    protected static function buildJpeg(array $scansWithTheirTables): string
    {
        return "\xFF\xD8" . self::buildApp0Segment() . self::buildFrameHeaderSegments()
            . implode('', $scansWithTheirTables) . "\xFF\xD9";
    }

    protected static function buildSegment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    protected static function buildApp0Segment(): string
    {
        return self::buildSegment(0xE0, "JFIF\0\x01\x01\x00\x00\x01\x00\x01\x00\x00");
    }

    /** DQT, SOF0 (16x16, one component) and DHT. */
    protected static function buildFrameHeaderSegments(): string
    {
        return self::buildSegment(0xDB, "\x00" . str_repeat("\x01", 64))
            . self::buildSegment(0xC0, "\x08\x00\x10\x00\x10\x01\x01\x11\x00")
            . self::buildHuffmanTableSegment();
    }

    protected static function buildHuffmanTableSegment(): string
    {
        return self::buildSegment(0xC4, "\x00" . "\x01" . str_repeat("\x00", 15) . "\x00");
    }

    /** SOS for one component followed by the entropy-coded data. */
    protected static function buildScan(string $entropyCodedData): string
    {
        return self::buildSegment(0xDA, "\x01\x01\x00\x00\x3F\x00") . $entropyCodedData;
    }

    protected static function buildPngChunk(string $chunkType, string $chunkData): string
    {
        return pack('N', strlen($chunkData)) . $chunkType . $chunkData . pack('N', crc32($chunkType . $chunkData));
    }
}
