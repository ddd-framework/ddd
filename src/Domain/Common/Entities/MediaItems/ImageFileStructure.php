<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems;

/**
 * The byte structure of a JPEG or PNG file, read in pure PHP (no decoder, no Imagick).
 *
 * Two uses: listing the JPEG marker segments before the first scan and the PNG chunks (what {@see ImageMetadata}
 * reads marks from and inserts marks into), and the structural end check of the post image fit check: does the file
 * end where its image ends? That check rejects files carrying anything a re-encode would drop silently or that a
 * platform may read differently: a second image after the first EOI (MPO, Ultra HDR gain maps, Motion Photo videos),
 * padding after the end, a truncated file, a PNG with a damaged chunk or an animation.
 */
class ImageFileStructure
{
    /** @var string The two bytes every JPEG starts with (SOI) */
    public const string JPEG_START_OF_IMAGE = "\xFF\xD8";

    /** @var string The eight bytes every PNG starts with */
    public const string PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    /** @var int JPEG marker: start of scan; entropy-coded data follows its segment */
    public const int JPEG_MARKER_START_OF_SCAN = 0xDA;

    /** @var int JPEG marker: end of image */
    public const int JPEG_MARKER_END_OF_IMAGE = 0xD9;

    /** @var int JPEG marker: start of image */
    public const int JPEG_MARKER_START_OF_IMAGE = 0xD8;

    /** @var int JPEG marker: temporary use, the only standalone marker allowed outside entropy-coded data */
    public const int JPEG_MARKER_TEMPORARY = 0x01;

    /** @var int First JPEG restart marker (RST0); RST0..RST7 appear inside entropy-coded data only */
    public const int JPEG_MARKER_FIRST_RESTART = 0xD0;

    /** @var int Last JPEG restart marker (RST7) */
    public const int JPEG_MARKER_LAST_RESTART = 0xD7;

    public static function isJpeg(string $imageBytes): bool
    {
        return str_starts_with($imageBytes, self::JPEG_START_OF_IMAGE);
    }

    public static function isPng(string $imageBytes): bool
    {
        return str_starts_with($imageBytes, self::PNG_SIGNATURE);
    }

    /**
     * True when the file ends where its image ends: for a JPEG see {@see isJpegEndingAtItsFirstEndOfImage()}, for a
     * PNG see {@see isSingleFramePngEndingAtItsEndChunk()}; false for every other format.
     */
    public static function isEndingWhereItsImageEnds(string $imageBytes): bool
    {
        if (static::isJpeg($imageBytes)) {
            return static::isJpegEndingAtItsFirstEndOfImage($imageBytes);
        }
        if (static::isPng($imageBytes)) {
            return static::isSingleFramePngEndingAtItsEndChunk($imageBytes);
        }
        return false;
    }

    /**
     * Walks a JPEG from SOI through every marker segment (by its length, so an EXIF thumbnail's own `FFD8 ... FFD9`
     * inside an APP1 payload is stepped over) and through every scan's entropy-coded data (several scans with DHT or
     * DQT between them in a progressive file; inside the data `FF00` is a stuffed byte and `FFD0`-`FFD7` are restart
     * markers; `FF` fill bytes may precede any marker). True when the walk reaches the first EOI after at least one
     * scan and that EOI's two bytes are the last two bytes of the file. False for anything else: bytes after the EOI
     * (a second image, a video, padding), no EOI (a truncated file), a segment length that runs past the end, or a
     * marker that cannot stand where it stands.
     */
    public static function isJpegEndingAtItsFirstEndOfImage(string $jpegBytes): bool
    {
        if (!static::isJpeg($jpegBytes)) {
            return false;
        }
        $byteLength = strlen($jpegBytes);
        $scanCount = 0;
        $offset = 2;
        while (true) {
            $markerOffset = static::getMarkerOffsetAfterFillBytes($jpegBytes, $offset);
            if ($markerOffset === null) {
                return false;
            }
            $marker = ord($jpegBytes[$markerOffset + 1]);
            if ($marker === self::JPEG_MARKER_END_OF_IMAGE) {
                return $scanCount > 0 && $markerOffset + 2 === $byteLength;
            }
            if ($marker === self::JPEG_MARKER_TEMPORARY) {
                $offset = $markerOffset + 2;
                continue;
            }
            if (!static::isJpegMarkerWithLength($marker) || $markerOffset + 4 > $byteLength) {
                return false;
            }
            $lengthField = static::readUint16BigEndian($jpegBytes, $markerOffset + 2);
            if ($lengthField < 2 || $markerOffset + 2 + $lengthField > $byteLength) {
                return false;
            }
            $offset = $markerOffset + 2 + $lengthField;
            if ($marker !== self::JPEG_MARKER_START_OF_SCAN) {
                continue;
            }
            $scanCount++;
            $offset = static::getOffsetOfMarkerEndingEntropyCodedData($jpegBytes, $offset);
            if ($offset === null) {
                return false;
            }
        }
    }

    /**
     * Walks the PNG chunks. True when every chunk's CRC (over type and payload) is valid, the first chunk is IHDR,
     * there is no `acTL` chunk (an animated PNG pings as one frame, and a re-encode would drop the animation), and IEND
     * is the last chunk, ending at the last byte of the file.
     */
    public static function isSingleFramePngEndingAtItsEndChunk(string $pngBytes): bool
    {
        if (!static::isPng($pngBytes)) {
            return false;
        }
        $byteLength = strlen($pngBytes);
        $offset = strlen(self::PNG_SIGNATURE);
        $isFirstChunk = true;
        while ($offset + 12 <= $byteLength) {
            $payloadLength = static::readUint32BigEndian($pngBytes, $offset);
            if ($payloadLength > $byteLength - $offset - 12) {
                return false;
            }
            $chunkType = substr($pngBytes, $offset + 4, 4);
            if (($isFirstChunk && $chunkType !== 'IHDR') || $chunkType === 'acTL') {
                return false;
            }
            $storedCrc = static::readUint32BigEndian($pngBytes, $offset + 8 + $payloadLength);
            if (crc32(substr($pngBytes, $offset + 4, 4 + $payloadLength)) !== $storedCrc) {
                return false;
            }
            $offset += 12 + $payloadLength;
            if ($chunkType === 'IEND') {
                return $offset === $byteLength;
            }
            $isFirstChunk = false;
        }
        return false;
    }

    /**
     * Lists the marker segments of a JPEG from SOI up to, not including, the first SOS: the segments metadata lives
     * in. The walk stops quietly at the first SOS, at EOI, at a marker without a length, or at a length that runs past
     * the end of the file, and returns what it found until there; for bytes that are not a JPEG it returns nothing.
     *
     * @return array<int, array{marker: int, offset: int, length: int, payload: string}> One entry per segment in file
     *     order: `offset` is the position of the `FF` of its marker, `length` counts the marker, the length field and
     *     the payload (so `offset + length` is where the next segment or fill byte starts).
     */
    public static function listJpegSegmentsBeforeFirstScan(string $jpegBytes): array
    {
        $segments = [];
        if (!static::isJpeg($jpegBytes)) {
            return $segments;
        }
        $byteLength = strlen($jpegBytes);
        $offset = 2;
        while (true) {
            $markerOffset = static::getMarkerOffsetAfterFillBytes($jpegBytes, $offset);
            if ($markerOffset === null || $markerOffset + 4 > $byteLength) {
                return $segments;
            }
            $marker = ord($jpegBytes[$markerOffset + 1]);
            if ($marker === self::JPEG_MARKER_START_OF_SCAN || !static::isJpegMarkerWithLength($marker)) {
                return $segments;
            }
            $lengthField = static::readUint16BigEndian($jpegBytes, $markerOffset + 2);
            if ($lengthField < 2 || $markerOffset + 2 + $lengthField > $byteLength) {
                return $segments;
            }
            $segments[] = [
                'marker' => $marker,
                'offset' => $markerOffset,
                'length' => 2 + $lengthField,
                'payload' => substr($jpegBytes, $markerOffset + 4, $lengthField - 2),
            ];
            $offset = $markerOffset + 2 + $lengthField;
        }
    }

    /**
     * Lists the chunks of a PNG up to and including IEND. CRCs are not checked here (a damaged ancillary chunk is still
     * listed); the walk stops quietly at a chunk that runs past the end of the file; for bytes that are not a PNG it
     * returns nothing.
     *
     * @return array<int, array{type: string, offset: int, length: int, payload: string}> One entry per chunk in file
     *     order: `offset` is the position of its length field, `length` counts the length field, the type, the
     *     payload and the CRC.
     */
    public static function listPngChunks(string $pngBytes): array
    {
        $chunks = [];
        if (!static::isPng($pngBytes)) {
            return $chunks;
        }
        $byteLength = strlen($pngBytes);
        $offset = strlen(self::PNG_SIGNATURE);
        while ($offset + 12 <= $byteLength) {
            $payloadLength = static::readUint32BigEndian($pngBytes, $offset);
            if ($payloadLength > $byteLength - $offset - 12) {
                return $chunks;
            }
            $chunkType = substr($pngBytes, $offset + 4, 4);
            $chunks[] = [
                'type' => $chunkType,
                'offset' => $offset,
                'length' => 12 + $payloadLength,
                'payload' => substr($pngBytes, $offset + 8, $payloadLength),
            ];
            $offset += 12 + $payloadLength;
            if ($chunkType === 'IEND') {
                return $chunks;
            }
        }
        return $chunks;
    }

    /**
     * From an offset where a marker must start, steps over `FF` fill bytes and returns the offset of the `FF` directly
     * before the marker byte; null when there is no `FF` at the offset or the file ends before the marker byte.
     */
    protected static function getMarkerOffsetAfterFillBytes(string $jpegBytes, int $offset): ?int
    {
        $byteLength = strlen($jpegBytes);
        if ($offset >= $byteLength || $jpegBytes[$offset] !== "\xFF") {
            return null;
        }
        while ($offset + 1 < $byteLength && $jpegBytes[$offset + 1] === "\xFF") {
            $offset++;
        }
        return $offset + 1 < $byteLength ? $offset : null;
    }

    /**
     * Scans entropy-coded data from $offset (hopping from `FF` to `FF` with strpos, never byte by byte) and returns the
     * offset of the first `FF` that starts a real marker (fill bytes included); stuffed `FF00` bytes and restart
     * markers belong to the data. Null when the file ends inside the data.
     */
    protected static function getOffsetOfMarkerEndingEntropyCodedData(string $jpegBytes, int $offset): ?int
    {
        $byteLength = strlen($jpegBytes);
        while (true) {
            $firstFFOffset = strpos($jpegBytes, "\xFF", $offset);
            if ($firstFFOffset === false) {
                return null;
            }
            $byteAfterFFOffset = $firstFFOffset + 1;
            while ($byteAfterFFOffset < $byteLength && $jpegBytes[$byteAfterFFOffset] === "\xFF") {
                $byteAfterFFOffset++;
            }
            if ($byteAfterFFOffset >= $byteLength) {
                return null;
            }
            $byteAfterFF = ord($jpegBytes[$byteAfterFFOffset]);
            $isStuffedByte = $byteAfterFF === 0x00;
            $isRestartMarker = $byteAfterFF >= self::JPEG_MARKER_FIRST_RESTART && $byteAfterFF <= self::JPEG_MARKER_LAST_RESTART;
            if (!$isStuffedByte && !$isRestartMarker) {
                return $firstFFOffset;
            }
            $offset = $byteAfterFFOffset + 1;
        }
    }

    /** True for a marker followed by a 16-bit length (every marker but SOI, EOI, TEM, RST0-RST7 and the 00/FF bytes). */
    protected static function isJpegMarkerWithLength(int $marker): bool
    {
        return $marker !== 0x00
            && $marker !== 0xFF
            && $marker !== self::JPEG_MARKER_TEMPORARY
            && $marker !== self::JPEG_MARKER_START_OF_IMAGE
            && $marker !== self::JPEG_MARKER_END_OF_IMAGE
            && ($marker < self::JPEG_MARKER_FIRST_RESTART || $marker > self::JPEG_MARKER_LAST_RESTART);
    }

    protected static function readUint16BigEndian(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
    }

    protected static function readUint32BigEndian(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 24)
            | (ord($bytes[$offset + 1]) << 16)
            | (ord($bytes[$offset + 2]) << 8)
            | ord($bytes[$offset + 3]);
    }
}
