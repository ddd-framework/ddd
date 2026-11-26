<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\MediaItems;

use DDD\Domain\Common\Entities\MediaItems\ImageMetadata;
use DDD\Testing\MediaItems\ImageStructureWalker;
use DDD\Testing\MediaItems\ImageMarkFixtureBuilder;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use LengthException;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

/**
 * ImageMetadata reads a source image's EXIF, IPTC and XMP from its bytes and inserts every kind the target lacks
 * (JPEG segments; PNG `eXIf` and XMP `iTXt` chunks), also across formats; C2PA is never copied. Pixels come from GD
 * (no Imagick), marks are injected as bytes, and every result is checked with the test's own
 * {@see ImageStructureWalker} and decoded with getimagesizefromstring().
 *
 * The "criterion N" labels number the cases of the specification these classes were written against.
 */
class ImageMetadataTest extends TestCase
{
    /** @var int Width of the GD fixtures */
    protected const int WIDTH = 64;

    /** @var int Height of the GD fixtures */
    protected const int HEIGHT = 48;

    /** @var int The cap on an inflated XMP packet, 16 MiB, written out here instead of read from ImageMetadata */
    protected const int SIXTEEN_MIB = 16 * 1024 * 1024;

    public function testImageWithoutMarksLeavesTheTargetUnchanged(): void
    {
        $jpegTarget = self::buildGdJpeg();
        $pngTarget = self::buildGdPng();
        $this->assertCarriesNoJpegMark($jpegTarget);
        self::assertSame([], ImageStructureWalker::listPngChunksOfType($pngTarget, 'eXIf'), 'PRECONDITION: the PNG has no eXIf');
        self::assertSame([], ImageStructureWalker::listPngXmpChunks($pngTarget), 'PRECONDITION: the PNG has no XMP');

        $jpegMetadata = ImageMetadata::fromImageBytes($jpegTarget);
        $pngMetadata = ImageMetadata::fromImageBytes($pngTarget);

        self::assertTrue($jpegMetadata->isEmpty(), 'criterion 25: a JPEG without marks reads as empty');
        self::assertTrue($pngMetadata->isEmpty(), 'criterion 25: a PNG without marks reads as empty');
        self::assertSame($jpegTarget, $jpegMetadata->buildImageBytesWithMissingMetadataInserted($jpegTarget), 'criterion 25: the JPEG target is unchanged');
        self::assertSame($pngTarget, $pngMetadata->buildImageBytesWithMissingMetadataInserted($pngTarget), 'criterion 25: the PNG target is unchanged');
        self::assertSame($pngTarget, $jpegMetadata->buildImageBytesWithMissingMetadataInserted($pngTarget), 'criterion 25: across formats too');
    }

    public function testOtherFormatsReadAsEmpty(): void
    {
        $gifBytes = self::buildGdGif();
        $webpBytes = 'RIFF' . pack('V', 20) . 'WEBPVP8L' . pack('V', 8) . "\x2F\x3F\xC0\x0B\x00\x07\x10\x11";
        self::assertSame('GIF', substr($gifBytes, 0, 3), 'PRECONDITION: GD wrote a GIF');

        self::assertTrue(ImageMetadata::fromImageBytes($gifBytes)->isEmpty(), 'criterion 26: a GIF reads as empty');
        self::assertTrue(ImageMetadata::fromImageBytes($webpBytes)->isEmpty(), 'criterion 26: a WebP reads as empty');
    }

    public function testJpegMarksAreInsertedAfterApp0InOrder(): void
    {
        $sourceJpeg = self::buildJpegSourceWithEveryMark();
        $target = self::buildGdJpeg();
        self::assertSame(ImageStructureWalker::JPEG_KIND_APP0, ImageStructureWalker::listJpegSegmentKinds($target)[0], 'PRECONDITION: the target starts with APP0');
        $this->assertCarriesNoJpegMark($target);

        $targetWithMarks = ImageMetadata::fromImageBytes($sourceJpeg)->buildImageBytesWithMissingMetadataInserted($target);

        $segmentsOfTargetWithMarks = ImageStructureWalker::listJpegSegmentsUpToFirstScan($targetWithMarks);
        $insertedSegments = array_slice($segmentsOfTargetWithMarks, 1, 6);
        self::assertSame(
            [
                ImageStructureWalker::JPEG_KIND_EXIF,
                ImageStructureWalker::JPEG_KIND_XMP,
                ImageStructureWalker::JPEG_KIND_EXTENDED_XMP,
                ImageStructureWalker::JPEG_KIND_EXTENDED_XMP,
                ImageStructureWalker::JPEG_KIND_IPTC,
                ImageStructureWalker::JPEG_KIND_IPTC,
            ],
            array_column($insertedSegments, 'kind'),
            'criterion 32: Exif, main XMP, extended XMP, APP13 directly after APP0',
        );
        self::assertSame(
            self::listMarkPayloadsInOrder($sourceJpeg),
            array_column($insertedSegments, 'payload'),
            'criterion 32: the source payloads byte for byte',
        );
        self::assertSame($target, ImageStructureWalker::buildJpegWithoutSegments($targetWithMarks, $insertedSegments), 'criterion 32: removing them gives back the target');
        self::assertSame([self::WIDTH, self::HEIGHT], array_slice((array)getimagesizefromstring($targetWithMarks), 0, 2), 'the result decodes');
    }

    public function testJpegMarksAreInsertedAfterSoiWithoutApp0(): void
    {
        $sourceJpeg = self::buildJpegSourceWithEveryMark();
        $gdJpeg = self::buildGdJpeg();
        $target = ImageStructureWalker::buildJpegWithoutSegments(
            $gdJpeg,
            ImageStructureWalker::listJpegSegmentsOfKind($gdJpeg, ImageStructureWalker::JPEG_KIND_APP0),
        );
        self::assertNotContains(ImageStructureWalker::JPEG_KIND_APP0, ImageStructureWalker::listJpegSegmentKinds($target), 'PRECONDITION: no APP0');

        $targetWithMarks = ImageMetadata::fromImageBytes($sourceJpeg)->buildImageBytesWithMissingMetadataInserted($target);

        $insertedSegments = array_slice(ImageStructureWalker::listJpegSegmentsUpToFirstScan($targetWithMarks), 0, 6);
        self::assertSame(2, $insertedSegments[0]['offset'], 'criterion 33: the first inserted segment starts directly after SOI');
        self::assertSame(
            self::listMarkPayloadsInOrder($sourceJpeg),
            array_column($insertedSegments, 'payload'),
            'criterion 33: the marks in order directly after SOI',
        );
        self::assertSame($target, ImageStructureWalker::buildJpegWithoutSegments($targetWithMarks, $insertedSegments), 'removing them gives back the target');
        self::assertSame([self::WIDTH, self::HEIGHT], array_slice((array)getimagesizefromstring($targetWithMarks), 0, 2), 'the result decodes');
    }

    public function testOnlyTheKindsTheTargetLacksAreInserted(): void
    {
        $sourceJpeg = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
            self::buildGdJpeg(),
            self::buildExifSegment() . self::buildXmpSegment() . self::buildIptcSegment(),
        );
        // ordered as ImageMagick writes it: APP0, APP13, Exif APP1
        $target = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(self::buildGdJpeg(), self::buildIptcSegment() . self::buildExifSegment());
        self::assertSame(
            [ImageStructureWalker::JPEG_KIND_APP0, ImageStructureWalker::JPEG_KIND_IPTC, ImageStructureWalker::JPEG_KIND_EXIF],
            array_slice(ImageStructureWalker::listJpegSegmentKinds($target), 0, 3),
            'PRECONDITION: the target is ordered APP0, APP13, Exif',
        );

        $targetWithMarks = ImageMetadata::fromImageBytes($sourceJpeg)->buildImageBytesWithMissingMetadataInserted($target);

        $segmentsOfTargetWithMarks = ImageStructureWalker::listJpegSegmentsUpToFirstScan($targetWithMarks);
        self::assertSame(
            [
                ImageStructureWalker::JPEG_KIND_APP0,
                ImageStructureWalker::JPEG_KIND_IPTC,
                ImageStructureWalker::JPEG_KIND_EXIF,
                ImageStructureWalker::JPEG_KIND_XMP,
            ],
            array_slice(array_column($segmentsOfTargetWithMarks, 'kind'), 0, 4),
            'criterion 34: only the main XMP APP1 is inserted, directly after the Exif APP1',
        );
        self::assertSame(ImageMarkFixtureBuilder::buildXmpApp1Payload(), $segmentsOfTargetWithMarks[3]['payload'], 'criterion 34: the source XMP payload');
        foreach ([ImageStructureWalker::JPEG_KIND_EXIF, ImageStructureWalker::JPEG_KIND_XMP, ImageStructureWalker::JPEG_KIND_IPTC] as $kind) {
            self::assertCount(1, ImageStructureWalker::listJpegSegmentsOfKind($targetWithMarks, $kind), "criterion 34: exactly one {$kind}");
        }
        self::assertSame($target, ImageStructureWalker::buildJpegWithoutSegments($targetWithMarks, [$segmentsOfTargetWithMarks[3]]), 'nothing else changed');
    }

    /** @dataProvider provideSingleMissingKinds */
    #[DataProvider('provideSingleMissingKinds')]
    public function testEachMissingKindIsInsertedAtItsPlace(string $missingKind): void
    {
        $segmentsByKind = [
            ImageStructureWalker::JPEG_KIND_EXIF => self::buildExifSegment(),
            ImageStructureWalker::JPEG_KIND_XMP => self::buildXmpSegment(),
            ImageStructureWalker::JPEG_KIND_IPTC => self::buildIptcSegment(),
        ];
        $sourceJpeg = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(self::buildGdJpeg(), implode('', $segmentsByKind));
        $segmentsTheTargetCarries = $segmentsByKind;
        unset($segmentsTheTargetCarries[$missingKind]);
        $target = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(self::buildGdJpeg(), implode('', $segmentsTheTargetCarries));
        self::assertCount(0, ImageStructureWalker::listJpegSegmentsOfKind($target, $missingKind), "PRECONDITION: the target lacks {$missingKind}");

        $targetWithMarks = ImageMetadata::fromImageBytes($sourceJpeg)->buildImageBytesWithMissingMetadataInserted($target);

        $segmentsOfTargetWithMarks = ImageStructureWalker::listJpegSegmentsUpToFirstScan($targetWithMarks);
        self::assertSame(
            [
                ImageStructureWalker::JPEG_KIND_APP0,
                ImageStructureWalker::JPEG_KIND_EXIF,
                ImageStructureWalker::JPEG_KIND_XMP,
                ImageStructureWalker::JPEG_KIND_IPTC,
            ],
            array_slice(array_column($segmentsOfTargetWithMarks, 'kind'), 0, 4),
            "criterion 35: {$missingKind} is inserted at its place in the order",
        );
        $insertedSegments = ImageStructureWalker::listJpegSegmentsOfKind($targetWithMarks, $missingKind);
        self::assertCount(1, $insertedSegments, "criterion 35: exactly one {$missingKind}");
        self::assertSame($target, ImageStructureWalker::buildJpegWithoutSegments($targetWithMarks, $insertedSegments), 'criterion 35: exactly that kind is inserted');
    }

    /** @return iterable<string, array{string}> */
    public static function provideSingleMissingKinds(): iterable
    {
        yield 'Exif' => [ImageStructureWalker::JPEG_KIND_EXIF];
        yield 'main XMP' => [ImageStructureWalker::JPEG_KIND_XMP];
        yield 'APP13' => [ImageStructureWalker::JPEG_KIND_IPTC];
    }

    public function testExtendedXmpIsNotDuplicated(): void
    {
        $sourceJpeg = self::buildJpegSourceWithEveryMark();
        // ImageMagick keeps extended XMP segments and drops the main XMP APP1
        $target = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(self::buildGdJpeg(), self::buildExtendedXmpSegments());
        self::assertCount(2, ImageStructureWalker::listJpegSegmentsOfKind($target, ImageStructureWalker::JPEG_KIND_EXTENDED_XMP), 'PRECONDITION: two extended XMP');
        self::assertCount(0, ImageStructureWalker::listJpegSegmentsOfKind($target, ImageStructureWalker::JPEG_KIND_XMP), 'PRECONDITION: no main XMP');

        $targetWithMarks = ImageMetadata::fromImageBytes($sourceJpeg)->buildImageBytesWithMissingMetadataInserted($target);

        self::assertCount(1, ImageStructureWalker::listJpegSegmentsOfKind($targetWithMarks, ImageStructureWalker::JPEG_KIND_XMP), 'criterion 36: the main XMP is inserted');
        self::assertCount(
            2,
            ImageStructureWalker::listJpegSegmentsOfKind($targetWithMarks, ImageStructureWalker::JPEG_KIND_EXTENDED_XMP),
            'criterion 36: the extended XMP segments are not duplicated',
        );
    }

    /** @dataProvider providePngXmpChunkLayouts */
    #[DataProvider('providePngXmpChunkLayouts')]
    public function testPngMarksAreWrittenIntoAJpeg(string $chunkType, bool $isCompressed): void
    {
        $tiffBlock = ImageMarkFixtureBuilder::buildExifTiffBlock();
        $xmpPacket = ImageMarkFixtureBuilder::buildXmpPacket();
        $sourcePng = ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(
            self::buildGdPng(),
            ImageMarkFixtureBuilder::buildPngChunk('eXIf', $tiffBlock)
            . ImageMarkFixtureBuilder::buildPngXmpChunk($xmpPacket, $chunkType, $isCompressed),
        );
        self::assertSame([$xmpPacket], ImageStructureWalker::listPngXmpPackets($sourcePng), 'PRECONDITION: the source carries the XMP');
        $target = self::buildGdJpeg();

        $targetWithMarks = ImageMetadata::fromImageBytes($sourcePng)->buildImageBytesWithMissingMetadataInserted($target);

        self::assertSame(
            [ImageMarkFixtureBuilder::EXIF_IDENTIFIER . $tiffBlock],
            array_column(ImageStructureWalker::listJpegSegmentsOfKind($targetWithMarks, ImageStructureWalker::JPEG_KIND_EXIF), 'payload'),
            'criterion 37: an APP1 of Exif\0\0 + the eXIf data',
        );
        self::assertSame(
            [ImageMarkFixtureBuilder::XMP_IDENTIFIER . $xmpPacket],
            array_column(ImageStructureWalker::listJpegSegmentsOfKind($targetWithMarks, ImageStructureWalker::JPEG_KIND_XMP), 'payload'),
            'criterion 37: a main XMP APP1 of the identifier + the packet',
        );
        self::assertSame([self::WIDTH, self::HEIGHT], array_slice((array)getimagesizefromstring($targetWithMarks), 0, 2), 'the result decodes');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function providePngXmpChunkLayouts(): iterable
    {
        yield 'uncompressed iTXt' => ['iTXt', false];
        yield 'compressed iTXt' => ['iTXt', true];
        yield 'zTXt' => ['zTXt', true];
        yield 'tEXt' => ['tEXt', false];
    }

    public function testMarksAreInsertedIntoAPngAfterIhdr(): void
    {
        $tiffBlock = ImageMarkFixtureBuilder::buildExifTiffBlock();
        $xmpPacket = ImageMarkFixtureBuilder::buildXmpPacket();
        $sourceJpeg = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(self::buildGdJpeg(), self::buildExifSegment() . self::buildXmpSegment());
        $target = self::buildGdPng();
        self::assertSame([], ImageStructureWalker::listPngChunksOfType($target, 'eXIf'), 'PRECONDITION: the target has no eXIf');
        self::assertSame([], ImageStructureWalker::listPngXmpChunks($target), 'PRECONDITION: the target has no XMP');
        $imageMetadata = ImageMetadata::fromImageBytes($sourceJpeg);

        $targetWithMarks = $imageMetadata->buildImageBytesWithMissingMetadataInserted($target);

        $chunksOfTargetWithMarks = ImageStructureWalker::listPngChunks($targetWithMarks);
        self::assertSame(['IHDR', 'eXIf', 'iTXt'], array_slice(array_column($chunksOfTargetWithMarks, 'type'), 0, 3), 'criterion 38: eXIf and iTXt directly after IHDR');
        self::assertSame($tiffBlock, $chunksOfTargetWithMarks[1]['data'], 'criterion 38: the eXIf data');
        self::assertSame(
            ImageMarkFixtureBuilder::XMP_PNG_KEYWORD . "\0\x00\x00\0\0" . $xmpPacket,
            $chunksOfTargetWithMarks[2]['data'],
            'criterion 38: an uncompressed iTXt XML:com.adobe.xmp',
        );
        self::assertTrue($chunksOfTargetWithMarks[1]['hasValidCrc'] && $chunksOfTargetWithMarks[2]['hasValidCrc'], 'criterion 38: CRC = crc32(type . data)');
        self::assertTrue(ImageStructureWalker::isWellFormedStillPng($targetWithMarks), 'the result is a well-formed PNG');
        self::assertSame([self::WIDTH, self::HEIGHT], array_slice((array)getimagesizefromstring($targetWithMarks), 0, 2), 'criterion 38: the result decodes');
        self::assertSame($target, ImageStructureWalker::buildPngWithoutChunks($targetWithMarks, [$chunksOfTargetWithMarks[1], $chunksOfTargetWithMarks[2]]), 'criterion 38: removing them gives back the target');

        $targetWithExif = ImageMarkFixtureBuilder::insertIntoPngAfterIhdr($target, ImageMarkFixtureBuilder::buildPngChunk('eXIf', 'other exif'));
        $targetWithExifAndInsertedXmp = $imageMetadata->buildImageBytesWithMissingMetadataInserted($targetWithExif);
        self::assertSame(
            ['IHDR', 'iTXt', 'eXIf'],
            array_slice(ImageStructureWalker::listPngChunkTypes($targetWithExifAndInsertedXmp), 0, 3),
            'criterion 38: into a PNG that has eXIf only the XMP is inserted',
        );
        self::assertSame(['other exif'], array_column(ImageStructureWalker::listPngChunksOfType($targetWithExifAndInsertedXmp, 'eXIf'), 'data'), 'the target eXIf is kept');
    }

    public function testJpegMarksAreWrittenIntoAPng(): void
    {
        $sourceJpeg = self::buildJpegSourceWithEveryMark();
        $target = self::buildGdPng();

        $targetWithMarks = ImageMetadata::fromImageBytes($sourceJpeg)->buildImageBytesWithMissingMetadataInserted($target);

        $chunksOfTargetWithMarks = ImageStructureWalker::listPngChunks($targetWithMarks);
        self::assertSame(['IHDR', 'eXIf', 'iTXt'], array_slice(array_column($chunksOfTargetWithMarks, 'type'), 0, 3), 'criterion 39: eXIf and the XMP iTXt');
        self::assertSame(ImageMarkFixtureBuilder::buildExifTiffBlock(), $chunksOfTargetWithMarks[1]['data'], 'criterion 39: eXIf is the TIFF data without Exif\0\0');
        self::assertSame([ImageMarkFixtureBuilder::buildXmpPacket()], ImageStructureWalker::listPngXmpPackets($targetWithMarks), 'criterion 39: the XMP packet');
        self::assertSame(
            count(ImageStructureWalker::listPngChunks($target)) + 2,
            count($chunksOfTargetWithMarks),
            'criterion 39: no IPTC or extended XMP is written into a PNG',
        );
        self::assertSame($target, ImageStructureWalker::buildPngWithoutChunks($targetWithMarks, [$chunksOfTargetWithMarks[1], $chunksOfTargetWithMarks[2]]), 'nothing else changed');
    }

    public function testC2paIsNeverCopied(): void
    {
        $jpegSourceWithOnlyC2pa = ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
            self::buildGdJpeg(),
            ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP11, ImageMarkFixtureBuilder::buildJumbfApp11Payload()),
        );
        $pngSourceWithOnlyC2pa = ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(
            self::buildGdPng(),
            ImageMarkFixtureBuilder::buildPngChunk('caBX', ImageMarkFixtureBuilder::buildJumbfBox()),
        );
        self::assertTrue(ImageMetadata::fromImageBytes($jpegSourceWithOnlyC2pa)->isEmpty(), 'criterion 40: APP11 is not read');
        self::assertTrue(ImageMetadata::fromImageBytes($pngSourceWithOnlyC2pa)->isEmpty(), 'criterion 40: caBX is not read');

        $jpegTargetWithMarks = ImageMetadata::fromImageBytes(self::buildJpegSourceWithEveryMark())->buildImageBytesWithMissingMetadataInserted(self::buildGdJpeg());
        self::assertCount(0, ImageStructureWalker::listJpegSegmentsOfKind($jpegTargetWithMarks, ImageStructureWalker::JPEG_KIND_APP11), 'criterion 40: no APP11 inserted');
        $pngSourceWithEveryMark = ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(self::buildGdPng(), ImageMarkFixtureBuilder::buildExifXmpAndCaBxPngChunks());
        $pngTargetWithMarks = ImageMetadata::fromImageBytes($pngSourceWithEveryMark)->buildImageBytesWithMissingMetadataInserted(self::buildGdPng());
        self::assertNotContains('caBX', ImageStructureWalker::listPngChunkTypes($pngTargetWithMarks), 'criterion 40: no caBX inserted');
    }

    public function testReadingStopsAtTheFirstScan(): void
    {
        $gdJpeg = self::buildGdJpeg();
        // an XMP APP1 between the entropy-coded data and EOI
        $sourceWithXmpAfterTheScanData = substr($gdJpeg, 0, -2) . self::buildXmpSegment() . "\xFF\xD9";
        // an XMP APP1 directly after the SOS segment, before the scan data: only a walk that stops at SOS misses it
        $startOfScanSegment = ImageStructureWalker::listJpegSegmentsOfKind($gdJpeg, ImageStructureWalker::JPEG_KIND_START_OF_SCAN)[0];
        $endOfStartOfScanSegment = $startOfScanSegment['offset'] + $startOfScanSegment['length'];
        $sourceWithXmpDirectlyAfterTheScanHeader = substr($gdJpeg, 0, $endOfStartOfScanSegment) . self::buildXmpSegment()
            . substr($gdJpeg, $endOfStartOfScanSegment);
        foreach ([$sourceWithXmpAfterTheScanData, $sourceWithXmpDirectlyAfterTheScanHeader] as $sourceJpeg) {
            self::assertSame(1, substr_count($sourceJpeg, ImageMarkFixtureBuilder::XMP_IDENTIFIER), 'PRECONDITION: the source holds an XMP APP1');
            self::assertCount(0, ImageStructureWalker::listJpegSegmentsOfKind($sourceJpeg, ImageStructureWalker::JPEG_KIND_XMP), 'PRECONDITION: it sits after the first SOS');
        }

        self::assertTrue(
            ImageMetadata::fromImageBytes($sourceWithXmpAfterTheScanData)->isEmpty(),
            'criterion 40: an XMP APP1 after the scan data is not read',
        );
        self::assertTrue(
            ImageMetadata::fromImageBytes($sourceWithXmpDirectlyAfterTheScanHeader)->isEmpty(),
            'criterion 40: an XMP APP1 directly after the first SOS segment is not read',
        );
    }

    public function testSegmentLimitsAtTheBoundAndOneOver(): void
    {
        $target = self::buildGdJpeg();
        $xmpAtTheBound = ImageMetadata::fromImageBytes(self::buildPngSourceWithXmp(ImageMarkFixtureBuilder::buildXmpPacket(65504)));
        $exifAtTheBound = ImageMetadata::fromImageBytes(self::buildPngSourceWithExif(ImageMarkFixtureBuilder::buildExifTiffBlockOfLength(65527)));

        $targetWithXmpAtTheBound = $xmpAtTheBound->buildImageBytesWithMissingMetadataInserted($target);
        $targetWithExifAtTheBound = $exifAtTheBound->buildImageBytesWithMissingMetadataInserted($target);

        self::assertSame(
            65535,
            ImageStructureWalker::listJpegSegmentsOfKind($targetWithXmpAtTheBound, ImageStructureWalker::JPEG_KIND_XMP)[0]['length'] - 2,
            'criterion 40: a 65,504-byte XMP packet fills one segment',
        );
        self::assertSame(
            65535,
            ImageStructureWalker::listJpegSegmentsOfKind($targetWithExifAtTheBound, ImageStructureWalker::JPEG_KIND_EXIF)[0]['length'] - 2,
            'criterion 40: a 65,527-byte EXIF block fills one segment',
        );
        $this->assertThrowsLengthException(
            ImageMetadata::fromImageBytes(self::buildPngSourceWithXmp(ImageMarkFixtureBuilder::buildXmpPacket(65505))),
            $target,
            'criterion 40: a 65,505-byte XMP packet throws',
        );
        $this->assertThrowsLengthException(
            ImageMetadata::fromImageBytes(self::buildPngSourceWithExif(ImageMarkFixtureBuilder::buildExifTiffBlockOfLength(65528))),
            $target,
            'criterion 40: a 65,528-byte EXIF block throws',
        );
    }

    public function testUnknownTargetFormatThrows(): void
    {
        $imageMetadata = ImageMetadata::fromImageBytes(self::buildJpegSourceWithEveryMark());
        self::assertFalse($imageMetadata->isEmpty(), 'PRECONDITION: the metadata is not empty');

        $this->expectException(InvalidArgumentException::class);

        $imageMetadata->buildImageBytesWithMissingMetadataInserted(self::buildGdGif());
    }

    public function testCorruptCompressedXmpThrowsOnRead(): void
    {
        $sourcePng = ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(
            self::buildGdPng(),
            ImageMarkFixtureBuilder::buildPngXmpITxtChunkWithCorruptZlibStream(),
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('not a valid zlib stream');

        ImageMetadata::fromImageBytes($sourcePng);
    }

    public function testCompressedXmpInflatingToExactlyTheCapIsRead(): void
    {
        $xmpPacket = ImageMarkFixtureBuilder::buildXmpPacket(self::SIXTEEN_MIB);
        $sourcePng = self::buildPngSourceWithCompressedXmp($xmpPacket);
        $this->assertCarriesOneCompressedXmpPacket($sourcePng, $xmpPacket, self::SIXTEEN_MIB);

        $imageMetadata = ImageMetadata::fromImageBytes($sourcePng);

        self::assertSame(self::SIXTEEN_MIB, strlen((string)$imageMetadata->xmpPacket), 'the inflate cap: a packet of exactly 16 MiB is read whole');
        self::assertSame(hash('sha256', $xmpPacket), hash('sha256', (string)$imageMetadata->xmpPacket), 'the inflate cap: the packet read is the source packet');
    }

    public function testCompressedXmpInflatingToOneByteOverTheCapThrows(): void
    {
        $xmpPacket = ImageMarkFixtureBuilder::buildXmpPacket(self::SIXTEEN_MIB + 1);
        $sourcePng = self::buildPngSourceWithCompressedXmp($xmpPacket);
        $this->assertCarriesOneCompressedXmpPacket($sourcePng, $xmpPacket, self::SIXTEEN_MIB + 1);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf('The inflated XMP exceeds %d bytes', self::SIXTEEN_MIB));

        ImageMetadata::fromImageBytes($sourcePng);
    }

    public function testCompressedXmpInflatingFarOverTheCapThrows(): void
    {
        // twice the cap: gzuncompress() stops at its limit and returns false instead of returning the text
        $xmpPacket = ImageMarkFixtureBuilder::buildXmpPacket(2 * self::SIXTEEN_MIB);
        $sourcePng = self::buildPngSourceWithCompressedXmp($xmpPacket);
        $this->assertCarriesOneCompressedXmpPacket($sourcePng, $xmpPacket, 2 * self::SIXTEEN_MIB);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf('or inflates to more than %d bytes', self::SIXTEEN_MIB));

        ImageMetadata::fromImageBytes($sourcePng);
    }

    protected function assertThrowsLengthException(ImageMetadata $imageMetadata, string $target, string $label): void
    {
        $caughtThrowable = null;
        try {
            $imageMetadata->buildImageBytesWithMissingMetadataInserted($target);
        } catch (LengthException $lengthException) {
            $caughtThrowable = $lengthException;
        }
        self::assertInstanceOf(LengthException::class, $caughtThrowable, $label);
    }

    protected function assertCarriesNoJpegMark(string $jpegBytes): void
    {
        foreach ([
            ImageStructureWalker::JPEG_KIND_EXIF,
            ImageStructureWalker::JPEG_KIND_XMP,
            ImageStructureWalker::JPEG_KIND_EXTENDED_XMP,
            ImageStructureWalker::JPEG_KIND_IPTC,
            ImageStructureWalker::JPEG_KIND_APP11,
        ] as $kind) {
            self::assertCount(0, ImageStructureWalker::listJpegSegmentsOfKind($jpegBytes, $kind), "PRECONDITION: the JPEG carries no {$kind}");
        }
    }

    /** @return string[] The payloads of the source's marks in the order they must be inserted. */
    protected static function listMarkPayloadsInOrder(string $sourceJpeg): array
    {
        $payloads = [];
        foreach ([
            ImageStructureWalker::JPEG_KIND_EXIF,
            ImageStructureWalker::JPEG_KIND_XMP,
            ImageStructureWalker::JPEG_KIND_EXTENDED_XMP,
            ImageStructureWalker::JPEG_KIND_IPTC,
        ] as $kind) {
            $payloads = [...$payloads, ...array_column(ImageStructureWalker::listJpegSegmentsOfKind($sourceJpeg, $kind), 'payload')];
        }
        return $payloads;
    }

    /** A GD JPEG carrying Exif, main XMP, two extended XMP, two APP13 and an APP11, in a shuffled order. */
    protected static function buildJpegSourceWithEveryMark(): string
    {
        return ImageMarkFixtureBuilder::insertIntoJpegAfterApp0OrSoi(
            self::buildGdJpeg(),
            self::buildIptcSegment()
            . ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP11, ImageMarkFixtureBuilder::buildJumbfApp11Payload())
            . self::buildExtendedXmpSegments()
            . self::buildXmpSegment()
            . ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP13, ImageMarkFixtureBuilder::buildIptcPhotoshopPayload('second caption'))
            . self::buildExifSegment(),
        );
    }

    protected static function buildExifSegment(): string
    {
        return ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, ImageMarkFixtureBuilder::buildExifApp1Payload());
    }

    protected static function buildXmpSegment(): string
    {
        return ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, ImageMarkFixtureBuilder::buildXmpApp1Payload());
    }

    protected static function buildIptcSegment(): string
    {
        return ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP13, ImageMarkFixtureBuilder::buildIptcPhotoshopPayload());
    }

    protected static function buildExtendedXmpSegments(): string
    {
        return ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, ImageMarkFixtureBuilder::buildExtendedXmpApp1Payload('first part', 0))
            . ImageMarkFixtureBuilder::buildJpegSegment(ImageMarkFixtureBuilder::MARKER_APP1, ImageMarkFixtureBuilder::buildExtendedXmpApp1Payload('second part', 10));
    }

    protected static function buildPngSourceWithXmp(string $xmpPacket): string
    {
        return ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(self::buildGdPng(), ImageMarkFixtureBuilder::buildPngXmpChunk($xmpPacket));
    }

    /** A GD PNG with one zlib-compressed XMP `iTXt` chunk: a packet padded with spaces compresses to a few KiB. */
    protected static function buildPngSourceWithCompressedXmp(string $xmpPacket): string
    {
        return ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(
            self::buildGdPng(),
            ImageMarkFixtureBuilder::buildPngXmpChunk($xmpPacket, 'iTXt', true),
        );
    }

    /**
     * Preconditions of an inflate-cap fixture: the packet has the given length, the PNG carries exactly one XMP chunk,
     * a compressed `iTXt` that stays small, and the test's own walker inflates it to the packet.
     */
    protected function assertCarriesOneCompressedXmpPacket(string $sourcePng, string $xmpPacket, int $xmpPacketLength): void
    {
        self::assertSame($xmpPacketLength, strlen($xmpPacket), "PRECONDITION: the packet is {$xmpPacketLength} bytes");
        $xmpChunks = ImageStructureWalker::listPngXmpChunks($sourcePng);
        self::assertCount(1, $xmpChunks, 'PRECONDITION: the PNG carries one XMP chunk');
        self::assertSame('iTXt', $xmpChunks[0]['type'], 'PRECONDITION: the XMP chunk is an iTXt');
        self::assertSame("\x01", $xmpChunks[0]['data'][strlen(ImageMarkFixtureBuilder::XMP_PNG_KEYWORD) + 1], 'PRECONDITION: the iTXt is compressed');
        self::assertLessThan(65536, $xmpChunks[0]['length'], 'PRECONDITION: the compressed chunk stays small');
        self::assertSame(
            hash('sha256', $xmpPacket),
            hash('sha256', ImageStructureWalker::decodePngXmpPacket($xmpChunks[0])),
            'PRECONDITION: the chunk inflates to the packet',
        );
    }

    protected static function buildPngSourceWithExif(string $tiffBlock): string
    {
        return ImageMarkFixtureBuilder::insertIntoPngAfterIhdr(self::buildGdPng(), ImageMarkFixtureBuilder::buildPngChunk('eXIf', $tiffBlock));
    }

    protected static function buildGdJpeg(): string
    {
        ob_start();
        imagejpeg(self::buildGdImage(), null, 90);
        return (string)ob_get_clean();
    }

    protected static function buildGdPng(): string
    {
        ob_start();
        imagepng(self::buildGdImage());
        return (string)ob_get_clean();
    }

    protected static function buildGdGif(): string
    {
        ob_start();
        imagegif(self::buildGdImage());
        return (string)ob_get_clean();
    }

    protected static function buildGdImage(): \GdImage
    {
        $gdImage = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        for ($x = 0; $x < self::WIDTH; $x++) {
            imageline($gdImage, $x, 0, $x, self::HEIGHT - 1, imagecolorallocate($gdImage, $x * 4, 255 - $x * 4, 128));
        }
        return $gdImage;
    }
}
