<?php

declare(strict_types=1);

namespace DDD\Testing\MediaItems;

use UnexpectedValueException;

/**
 * The tests' own JPEG-segment / PNG-chunk structure walker. It is independent of the production classes it checks
 * (ImageMetadata and the structure walk next to it): every assertion about "carries X", "X comes directly after Y" or
 * "removing X gives back the target" goes through this walker, never through a byte search and never through the
 * code under test.
 *
 * JPEG: the segments from SOI up to and including the first SOS, each with a consistent length (a length that runs
 * past the end of the file, or a missing marker where one must be, throws). PNG: every chunk up to and including
 * IEND, each with its CRC checked.
 */
class ImageStructureWalker
{
    /** @var string An APP0 segment (JFIF, JFXX) */
    public const string JPEG_KIND_APP0 = 'APP0';

    /** @var string An APP1 segment whose payload starts with `Exif\0\0` */
    public const string JPEG_KIND_EXIF = 'Exif APP1';

    /** @var string An APP1 segment whose payload starts with the main XMP namespace identifier */
    public const string JPEG_KIND_XMP = 'main XMP APP1';

    /** @var string An APP1 segment whose payload starts with the extended XMP identifier */
    public const string JPEG_KIND_EXTENDED_XMP = 'extended XMP APP1';

    /** @var string An APP13 segment whose payload starts with `Photoshop 3.0\0` (where IPTC IIM lives) */
    public const string JPEG_KIND_IPTC = 'Photoshop APP13';

    /** @var string Any APP11 segment (the JUMBF boxes a C2PA manifest is carried in) */
    public const string JPEG_KIND_APP11 = 'APP11';

    /** @var string A COM segment */
    public const string JPEG_KIND_COMMENT = 'COM';

    /** @var string The first SOS segment (the walk stops there) */
    public const string JPEG_KIND_START_OF_SCAN = 'SOS';

    /** @var string Any other segment (DQT, SOF, DHT, APP2 ...) */
    public const string JPEG_KIND_OTHER = 'other';

    /** @var string The eight bytes every PNG starts with */
    public const string PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    /**
     * Lists the JPEG segments from SOI up to and including the first SOS.
     *
     * @return array<int, array{marker: int, offset: int, length: int, payload: string, kind: string}> One entry per
     *     segment in file order; `offset` is the position of its `FF`, `length` counts the marker, the length field
     *     and the payload.
     * @throws UnexpectedValueException When the bytes are not a JPEG or a segment length is inconsistent.
     */
    public static function listJpegSegmentsUpToFirstScan(string $jpegBytes): array
    {
        if (!str_starts_with($jpegBytes, "\xFF\xD8")) {
            throw new UnexpectedValueException('Not a JPEG: the bytes do not start with SOI');
        }
        $byteLength = strlen($jpegBytes);
        $segments = [];
        $offset = 2;
        while (true) {
            if ($offset + 4 > $byteLength) {
                throw new UnexpectedValueException("A segment header at offset {$offset} runs past the end of the file");
            }
            if ($jpegBytes[$offset] !== "\xFF") {
                throw new UnexpectedValueException("No marker at offset {$offset}");
            }
            $marker = ord($jpegBytes[$offset + 1]);
            $lengthField = unpack('n', substr($jpegBytes, $offset + 2, 2))[1];
            if ($lengthField < 2 || $offset + 2 + $lengthField > $byteLength) {
                throw new UnexpectedValueException(
                    sprintf('The segment FF%02X at offset %d has an inconsistent length %d', $marker, $offset, $lengthField)
                );
            }
            $payload = substr($jpegBytes, $offset + 4, $lengthField - 2);
            $segments[] = [
                'marker' => $marker,
                'offset' => $offset,
                'length' => 2 + $lengthField,
                'payload' => $payload,
                'kind' => self::getJpegSegmentKind($marker, $payload),
            ];
            if ($marker === 0xDA) {
                return $segments;
            }
            $offset += 2 + $lengthField;
        }
    }

    /**
     * @return array<int, array{marker: int, offset: int, length: int, payload: string, kind: string}> The segments of
     *     one kind (see the JPEG_KIND_* constants) before the first scan, in file order.
     */
    public static function listJpegSegmentsOfKind(string $jpegBytes, string $kind): array
    {
        return array_values(
            array_filter(
                self::listJpegSegmentsUpToFirstScan($jpegBytes),
                static fn(array $segment): bool => $segment['kind'] === $kind,
            )
        );
    }

    /**
     * @return string[] The kind of every segment before the first scan, in file order.
     */
    public static function listJpegSegmentKinds(string $jpegBytes): array
    {
        return array_column(self::listJpegSegmentsUpToFirstScan($jpegBytes), 'kind');
    }

    /**
     * Removes the given segments (as listed by {@see listJpegSegmentsUpToFirstScan()} on the same bytes).
     *
     * @param array<int, array{marker: int, offset: int, length: int, payload: string, kind: string}> $segmentsToRemove
     */
    public static function buildJpegWithoutSegments(string $jpegBytes, array $segmentsToRemove): string
    {
        usort($segmentsToRemove, static fn(array $first, array $second): int => $second['offset'] <=> $first['offset']);
        foreach ($segmentsToRemove as $segment) {
            $jpegBytes = substr($jpegBytes, 0, $segment['offset']) . substr($jpegBytes, $segment['offset'] + $segment['length']);
        }
        return $jpegBytes;
    }

    /**
     * Counts the SOS segments of a JPEG by walking every marker segment and every scan's entropy-coded data (stuffed
     * `FF00`, restart markers and fill bytes are stepped over) up to EOI.
     *
     * @throws UnexpectedValueException When the walk runs past the end of the file before EOI.
     */
    public static function countJpegScans(string $jpegBytes): int
    {
        $byteLength = strlen($jpegBytes);
        $scanCount = 0;
        $offset = 2;
        while ($offset + 2 <= $byteLength) {
            if ($jpegBytes[$offset] !== "\xFF") {
                throw new UnexpectedValueException("No marker at offset {$offset}");
            }
            $marker = ord($jpegBytes[$offset + 1]);
            if ($marker === 0xFF) {
                $offset++;
                continue;
            }
            if ($marker === 0xD9) {
                return $scanCount;
            }
            if ($offset + 4 > $byteLength) {
                break;
            }
            $lengthField = unpack('n', substr($jpegBytes, $offset + 2, 2))[1];
            $offset += 2 + $lengthField;
            if ($marker !== 0xDA) {
                continue;
            }
            $scanCount++;
            while (true) {
                $fillOrMarkerOffset = strpos($jpegBytes, "\xFF", $offset);
                if ($fillOrMarkerOffset === false || $fillOrMarkerOffset + 1 >= $byteLength) {
                    throw new UnexpectedValueException('The entropy-coded data runs past the end of the file');
                }
                $byteAfterFF = ord($jpegBytes[$fillOrMarkerOffset + 1]);
                if ($byteAfterFF === 0x00 || ($byteAfterFF >= 0xD0 && $byteAfterFF <= 0xD7)) {
                    $offset = $fillOrMarkerOffset + 2;
                    continue;
                }
                $offset = $fillOrMarkerOffset;
                break;
            }
        }
        throw new UnexpectedValueException('The JPEG has no EOI');
    }

    /**
     * Lists the PNG chunks up to and including IEND.
     *
     * @return array<int, array{type: string, offset: int, length: int, data: string, hasValidCrc: bool}> One entry
     *     per chunk in file order; `length` counts the length field, the type, the data and the CRC.
     * @throws UnexpectedValueException When the bytes are not a PNG or a chunk runs past the end of the file.
     */
    public static function listPngChunks(string $pngBytes): array
    {
        if (!str_starts_with($pngBytes, self::PNG_SIGNATURE)) {
            throw new UnexpectedValueException('Not a PNG: the signature is missing');
        }
        $byteLength = strlen($pngBytes);
        $chunks = [];
        $offset = 8;
        while ($offset + 12 <= $byteLength) {
            $dataLength = unpack('N', substr($pngBytes, $offset, 4))[1];
            if ($offset + 12 + $dataLength > $byteLength) {
                throw new UnexpectedValueException("The chunk at offset {$offset} runs past the end of the file");
            }
            $chunkType = substr($pngBytes, $offset + 4, 4);
            $chunkData = substr($pngBytes, $offset + 8, $dataLength);
            $storedCrc = unpack('N', substr($pngBytes, $offset + 8 + $dataLength, 4))[1];
            $chunks[] = [
                'type' => $chunkType,
                'offset' => $offset,
                'length' => 12 + $dataLength,
                'data' => $chunkData,
                'hasValidCrc' => $storedCrc === crc32($chunkType . $chunkData),
            ];
            $offset += 12 + $dataLength;
            if ($chunkType === 'IEND') {
                return $chunks;
            }
        }
        throw new UnexpectedValueException('The PNG has no IEND chunk');
    }

    /**
     * @return array<int, array{type: string, offset: int, length: int, data: string, hasValidCrc: bool}> The chunks
     *     of one type, in file order.
     */
    public static function listPngChunksOfType(string $pngBytes, string $chunkType): array
    {
        return array_values(
            array_filter(
                self::listPngChunks($pngBytes),
                static fn(array $chunk): bool => $chunk['type'] === $chunkType,
            )
        );
    }

    /**
     * @return string[] The type of every chunk, in file order.
     */
    public static function listPngChunkTypes(string $pngBytes): array
    {
        return array_column(self::listPngChunks($pngBytes), 'type');
    }

    /**
     * True when every chunk's CRC is valid, there is no `acTL` chunk, and IEND is the last chunk ending at the last
     * byte of the file.
     */
    public static function isWellFormedStillPng(string $pngBytes): bool
    {
        try {
            $chunks = self::listPngChunks($pngBytes);
        } catch (UnexpectedValueException) {
            return false;
        }
        foreach ($chunks as $chunk) {
            if (!$chunk['hasValidCrc'] || $chunk['type'] === 'acTL') {
                return false;
            }
        }
        $lastChunk = $chunks[count($chunks) - 1];
        return $lastChunk['offset'] + $lastChunk['length'] === strlen($pngBytes);
    }

    /**
     * @return array<int, array{type: string, offset: int, length: int, data: string, hasValidCrc: bool}> Every
     *     `iTXt`, `zTXt` or `tEXt` chunk whose keyword is `XML:com.adobe.xmp`, in file order.
     */
    public static function listPngXmpChunks(string $pngBytes): array
    {
        return array_values(
            array_filter(
                self::listPngChunks($pngBytes),
                static fn(array $chunk): bool => in_array($chunk['type'], ['iTXt', 'zTXt', 'tEXt'], true)
                    && str_starts_with($chunk['data'], ImageMarkFixtureBuilder::XMP_PNG_KEYWORD . "\0"),
            )
        );
    }

    /**
     * Decodes the XMP packet of a chunk listed by {@see listPngXmpChunks()}, following the PNG specification's chunk
     * layouts (iTXt: keyword, NUL, compression flag, compression method, language tag, NUL, translated keyword, NUL,
     * text; zTXt: keyword, NUL, compression method, zlib data; tEXt: keyword, NUL, text).
     *
     * @param array{type: string, offset: int, length: int, data: string, hasValidCrc: bool} $xmpChunk
     * @throws UnexpectedValueException When a compressed text does not inflate.
     */
    public static function decodePngXmpPacket(array $xmpChunk): string
    {
        $afterKeyword = substr($xmpChunk['data'], strlen(ImageMarkFixtureBuilder::XMP_PNG_KEYWORD) + 1);
        if ($xmpChunk['type'] === 'tEXt') {
            return $afterKeyword;
        }
        if ($xmpChunk['type'] === 'zTXt') {
            return self::inflateZlibStream(substr($afterKeyword, 1));
        }
        $isCompressed = $afterKeyword[0] === "\x01";
        $afterCompressionFields = substr($afterKeyword, 2);
        $languageTagEnd = strpos($afterCompressionFields, "\0");
        $translatedKeywordEnd = strpos($afterCompressionFields, "\0", $languageTagEnd + 1);
        $text = substr($afterCompressionFields, $translatedKeywordEnd + 1);
        return $isCompressed ? self::inflateZlibStream($text) : $text;
    }

    /**
     * @return string[] The decoded XMP packet of every XMP chunk, in file order.
     */
    public static function listPngXmpPackets(string $pngBytes): array
    {
        return array_map(
            static fn(array $xmpChunk): string => self::decodePngXmpPacket($xmpChunk),
            self::listPngXmpChunks($pngBytes),
        );
    }

    /**
     * Removes the given chunks (as listed by {@see listPngChunks()} on the same bytes).
     *
     * @param array<int, array{type: string, offset: int, length: int, data: string, hasValidCrc: bool}> $chunksToRemove
     */
    public static function buildPngWithoutChunks(string $pngBytes, array $chunksToRemove): string
    {
        usort($chunksToRemove, static fn(array $first, array $second): int => $second['offset'] <=> $first['offset']);
        foreach ($chunksToRemove as $chunk) {
            $pngBytes = substr($pngBytes, 0, $chunk['offset']) . substr($pngBytes, $chunk['offset'] + $chunk['length']);
        }
        return $pngBytes;
    }

    /**
     * @return array{bitDepth: int, colourType: int} Bit depth and colour type from the IHDR chunk.
     */
    public static function getPngBitDepthAndColourType(string $pngBytes): array
    {
        $ihdrChunk = self::listPngChunksOfType($pngBytes, 'IHDR')[0];
        return ['bitDepth' => ord($ihdrChunk['data'][8]), 'colourType' => ord($ihdrChunk['data'][9])];
    }

    /**
     * @throws UnexpectedValueException When the data is not a zlib stream.
     */
    protected static function inflateZlibStream(string $zlibStream): string
    {
        $inflatedText = @gzuncompress($zlibStream);
        if ($inflatedText === false) {
            throw new UnexpectedValueException('The compressed text is not a valid zlib stream');
        }
        return $inflatedText;
    }

    protected static function getJpegSegmentKind(int $marker, string $payload): string
    {
        return match (true) {
            $marker === 0xE0 => self::JPEG_KIND_APP0,
            $marker === 0xE1 && str_starts_with($payload, ImageMarkFixtureBuilder::EXIF_IDENTIFIER) => self::JPEG_KIND_EXIF,
            $marker === 0xE1 && str_starts_with($payload, ImageMarkFixtureBuilder::XMP_IDENTIFIER) => self::JPEG_KIND_XMP,
            $marker === 0xE1 && str_starts_with($payload, ImageMarkFixtureBuilder::EXTENDED_XMP_IDENTIFIER) => self::JPEG_KIND_EXTENDED_XMP,
            $marker === 0xED && str_starts_with($payload, ImageMarkFixtureBuilder::PHOTOSHOP_IDENTIFIER) => self::JPEG_KIND_IPTC,
            $marker === 0xEB => self::JPEG_KIND_APP11,
            $marker === 0xFE => self::JPEG_KIND_COMMENT,
            $marker === 0xDA => self::JPEG_KIND_START_OF_SCAN,
            default => self::JPEG_KIND_OTHER,
        };
    }
}
