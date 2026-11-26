<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems;

use InvalidArgumentException;
use LengthException;
use UnexpectedValueException;

/**
 * The machine-readable marks of an image file that a re-encode loses and that can be copied back on the bytes: EXIF,
 * IPTC (IIM inside Photoshop APP13) and XMP. Pure: no repository, no service, no Imagick; it works on the file bytes
 * of JPEG and PNG, also across the two formats. C2PA (JPEG APP11, PNG `caBX`) is never read: a manifest cannot survive
 * a re-encode, so copying it would only carry a broken signature.
 *
 * Holds raw binary, is never serialized or persisted, and is therefore not a DDD value object.
 */
class ImageMetadata
{
    /** @var string The identifier an Exif APP1 payload starts with */
    public const string EXIF_APP1_IDENTIFIER = "Exif\0\0";

    /** @var string The identifier the main XMP APP1 payload starts with */
    public const string XMP_APP1_IDENTIFIER = "http://ns.adobe.com/xap/1.0/\0";

    /** @var string The identifier every extended XMP APP1 payload starts with */
    public const string EXTENDED_XMP_APP1_IDENTIFIER = "http://ns.adobe.com/xmp/extension/\0";

    /** @var string The identifier of an APP13 payload holding Photoshop image resources (where IPTC IIM lives) */
    public const string PHOTOSHOP_APP13_IDENTIFIER = "Photoshop 3.0\0";

    /** @var string The keyword of the PNG text chunk (`iTXt`, `zTXt` or `tEXt`) carrying XMP */
    public const string XMP_PNG_KEYWORD = 'XML:com.adobe.xmp';

    /** @var int The most payload bytes one JPEG segment holds, identifier included: its 16-bit length counts itself */
    public const int MAX_JPEG_SEGMENT_PAYLOAD_LENGTH = 65533;

    /** @var int The longest XMP packet a compressed PNG text chunk may inflate to before the read gives up */
    public const int MAX_INFLATED_XMP_PACKET_LENGTH = 16777216;

    /** @var int JPEG marker APP0 */
    public const int JPEG_MARKER_APP0 = 0xE0;

    /** @var int JPEG marker APP1 (Exif, XMP) */
    public const int JPEG_MARKER_APP1 = 0xE1;

    /** @var int JPEG marker APP13 (Photoshop image resources) */
    public const int JPEG_MARKER_APP13 = 0xED;

    /** @var string Segment kind: APP0 (JFIF) */
    public const string JPEG_SEGMENT_KIND_APP0 = 'APP0';

    /** @var string Segment kind: Exif APP1 */
    public const string JPEG_SEGMENT_KIND_EXIF = 'EXIF';

    /** @var string Segment kind: main XMP APP1 */
    public const string JPEG_SEGMENT_KIND_XMP = 'XMP';

    /** @var string Segment kind: extended XMP APP1 */
    public const string JPEG_SEGMENT_KIND_EXTENDED_XMP = 'EXTENDED_XMP';

    /** @var string Segment kind: Photoshop APP13 (IPTC) */
    public const string JPEG_SEGMENT_KIND_IPTC = 'IPTC';

    /**
     * @var array<string, int> The order the metadata segments of a JPEG follow: an inserted kind goes directly after
     *     the last segment of an earlier kind (extended XMP travels with the main XMP)
     */
    public const array JPEG_SEGMENT_KIND_ORDER = [
        self::JPEG_SEGMENT_KIND_APP0 => 0,
        self::JPEG_SEGMENT_KIND_EXIF => 1,
        self::JPEG_SEGMENT_KIND_XMP => 2,
        self::JPEG_SEGMENT_KIND_EXTENDED_XMP => 2,
        self::JPEG_SEGMENT_KIND_IPTC => 3,
    ];

    /** @var string|null EXIF as a TIFF block (the Exif APP1 payload without `Exif\0\0`, or the PNG `eXIf` payload) */
    public ?string $exifTiffBlock = null;

    /** @var string|null The XMP packet (the main XMP APP1 payload without its identifier, or the PNG text) */
    public ?string $xmpPacket = null;

    /** @var string[] Every extended XMP APP1 payload of a JPEG source, identifier included, verbatim, in file order */
    public array $extendedXmpApp1Payloads = [];

    /** @var string[] Every Photoshop APP13 payload of a JPEG source, identifier included, verbatim, in file order */
    public array $photoshopApp13Payloads = [];

    /**
     * Empty; final so {@see fromImageBytes()} can build any subclass without arguments.
     */
    final public function __construct()
    {
    }

    /**
     * Reads the marks of a JPEG or PNG file.
     *
     * - JPEG: the segments from SOI to the first SOS only (so the second image of an MPO is never read): the first
     *   Exif APP1, the first main XMP APP1, every extended XMP APP1 and every Photoshop APP13.
     * - PNG: the first `eXIf` chunk and the XMP packet of the first `iTXt` (compressed or not), `zTXt` or `tEXt` chunk
     *   with keyword `XML:com.adobe.xmp`.
     * - Any other format (GIF, WebP, HEIC ...) or a file without marks: an empty object; never an exception.
     *
     * @throws UnexpectedValueException When a PNG XMP chunk cannot be parsed (a compressed text whose zlib stream is
     *     corrupt or inflates to more than {@see MAX_INFLATED_XMP_PACKET_LENGTH} bytes, a layout without its NUL
     *     separators): a mark that cannot be read fails the read as a whole.
     */
    public static function fromImageBytes(string $imageBytes): static
    {
        $imageMetadata = new static();
        if (ImageFileStructure::isJpeg($imageBytes)) {
            $imageMetadata->readFromJpegSegments($imageBytes);
        } elseif (ImageFileStructure::isPng($imageBytes)) {
            $imageMetadata->readFromPngChunks($imageBytes);
        }
        return $imageMetadata;
    }

    public function isEmpty(): bool
    {
        return $this->exifTiffBlock === null
            && $this->xmpPacket === null
            && $this->extendedXmpApp1Payloads === []
            && $this->photoshopApp13Payloads === [];
    }

    /**
     * Builds the target image bytes with each kind of mark this object carries inserted where the target does not
     * carry it yet; every other byte of the target stays as it is. An empty object returns the target unchanged.
     *
     * - JPEG target (segments walked from SOI to the first SOS): kinds follow the order APP0, Exif APP1, XMP APP1 (with
     *   its extended segments), APP13; each inserted kind goes directly after the last segment of an earlier kind,
     *   else directly after SOI. EXIF and XMP read from a PNG are wrapped into APP1 segments. Extended XMP goes in with
     *   the main XMP only when the target has no extended XMP segment (a re-encode keeps them, so they would be
     *   duplicated).
     * - PNG target: an `eXIf` chunk and an uncompressed `iTXt` `XML:com.adobe.xmp` chunk, with their CRCs, directly
     *   after IHDR. IPTC and extended XMP have no PNG chunk and are not written into a PNG.
     *
     * "Carries a kind" on the target: a JPEG has the Exif APP1 / main XMP APP1 / Photoshop APP13 segment; a PNG has
     * `eXIf` / an `iTXt`, `tEXt` or `zTXt` chunk with keyword `XML:com.adobe.xmp`.
     *
     * XMP is copied verbatim, including phone XMP that points at bytes the re-encode dropped (Google
     * `Container:Directory`, Motion Photo `MicroVideoOffset`, `hdrgm:` gain-map tags): accepted, a reader resolving
     * those pointers finds nothing and falls back to the primary image.
     *
     * @throws LengthException When a JPEG segment would hold more than 65,533 payload bytes (an XMP packet over 65,504
     *     bytes, an EXIF block over 65,527 bytes): its 16-bit length would be truncated silently. Nothing is inserted.
     * @throws InvalidArgumentException When there is something to insert and the target is neither JPEG nor PNG.
     * @throws UnexpectedValueException When a PNG target does not start with IHDR.
     */
    public function buildImageBytesWithMissingMetadataInserted(string $imageBytes): string
    {
        if ($this->isEmpty()) {
            return $imageBytes;
        }
        if (ImageFileStructure::isJpeg($imageBytes)) {
            return $this->buildJpegBytesWithMissingMetadataInserted($imageBytes);
        }
        if (ImageFileStructure::isPng($imageBytes)) {
            return $this->buildPngBytesWithMissingMetadataInserted($imageBytes);
        }
        throw new InvalidArgumentException('Image metadata can only be inserted into a JPEG or a PNG');
    }

    /**
     * The kind of a JPEG segment, or null for a segment that is none of the metadata kinds (DQT, COM, APP2 ...).
     */
    public static function getJpegSegmentKind(int $marker, string $payload): ?string
    {
        if ($marker === self::JPEG_MARKER_APP0) {
            return self::JPEG_SEGMENT_KIND_APP0;
        }
        if ($marker === self::JPEG_MARKER_APP13) {
            return str_starts_with($payload, self::PHOTOSHOP_APP13_IDENTIFIER) ? self::JPEG_SEGMENT_KIND_IPTC : null;
        }
        if ($marker !== self::JPEG_MARKER_APP1) {
            return null;
        }
        return match (true) {
            str_starts_with($payload, self::EXIF_APP1_IDENTIFIER) => self::JPEG_SEGMENT_KIND_EXIF,
            str_starts_with($payload, self::XMP_APP1_IDENTIFIER) => self::JPEG_SEGMENT_KIND_XMP,
            str_starts_with($payload, self::EXTENDED_XMP_APP1_IDENTIFIER) => self::JPEG_SEGMENT_KIND_EXTENDED_XMP,
            default => null,
        };
    }

    protected function readFromJpegSegments(string $jpegBytes): void
    {
        foreach (ImageFileStructure::listJpegSegmentsBeforeFirstScan($jpegBytes) as $segment) {
            $payload = $segment['payload'];
            switch (static::getJpegSegmentKind($segment['marker'], $payload)) {
                case self::JPEG_SEGMENT_KIND_EXIF:
                    $this->exifTiffBlock ??= substr($payload, strlen(self::EXIF_APP1_IDENTIFIER));
                    break;
                case self::JPEG_SEGMENT_KIND_XMP:
                    $this->xmpPacket ??= substr($payload, strlen(self::XMP_APP1_IDENTIFIER));
                    break;
                case self::JPEG_SEGMENT_KIND_EXTENDED_XMP:
                    $this->extendedXmpApp1Payloads[] = $payload;
                    break;
                case self::JPEG_SEGMENT_KIND_IPTC:
                    $this->photoshopApp13Payloads[] = $payload;
                    break;
            }
        }
    }

    /**
     * @throws UnexpectedValueException When an XMP chunk cannot be parsed.
     */
    protected function readFromPngChunks(string $pngBytes): void
    {
        foreach (ImageFileStructure::listPngChunks($pngBytes) as $chunk) {
            if ($chunk['type'] === 'eXIf') {
                $this->exifTiffBlock ??= $chunk['payload'];
            } elseif ($this->xmpPacket === null && static::isPngXmpChunk($chunk['type'], $chunk['payload'])) {
                $this->xmpPacket = static::readXmpPacketFromPngTextChunk($chunk['type'], $chunk['payload']);
            }
        }
    }

    /**
     * @throws LengthException When a segment would be too long.
     */
    protected function buildJpegBytesWithMissingMetadataInserted(string $jpegBytes): string
    {
        $kindsTheTargetCarries = array_map(
            static fn(array $segment): ?string => static::getJpegSegmentKind($segment['marker'], $segment['payload']),
            ImageFileStructure::listJpegSegmentsBeforeFirstScan($jpegBytes),
        );
        // every segment is built (and its length checked) before anything is inserted: the copy fails as a whole
        $segmentsBytesToInsertByKind = [];
        if ($this->exifTiffBlock !== null && !in_array(self::JPEG_SEGMENT_KIND_EXIF, $kindsTheTargetCarries, true)) {
            $segmentsBytesToInsertByKind[self::JPEG_SEGMENT_KIND_EXIF] = static::buildJpegSegment(
                self::JPEG_MARKER_APP1,
                self::EXIF_APP1_IDENTIFIER . $this->exifTiffBlock,
            );
        }
        if ($this->xmpPacket !== null && !in_array(self::JPEG_SEGMENT_KIND_XMP, $kindsTheTargetCarries, true)) {
            $xmpSegmentsBytes = static::buildJpegSegment(self::JPEG_MARKER_APP1, self::XMP_APP1_IDENTIFIER . $this->xmpPacket);
            if (!in_array(self::JPEG_SEGMENT_KIND_EXTENDED_XMP, $kindsTheTargetCarries, true)) {
                foreach ($this->extendedXmpApp1Payloads as $extendedXmpApp1Payload) {
                    $xmpSegmentsBytes .= static::buildJpegSegment(self::JPEG_MARKER_APP1, $extendedXmpApp1Payload);
                }
            }
            $segmentsBytesToInsertByKind[self::JPEG_SEGMENT_KIND_XMP] = $xmpSegmentsBytes;
        }
        if ($this->photoshopApp13Payloads !== [] && !in_array(self::JPEG_SEGMENT_KIND_IPTC, $kindsTheTargetCarries, true)) {
            $photoshopSegmentsBytes = '';
            foreach ($this->photoshopApp13Payloads as $photoshopApp13Payload) {
                $photoshopSegmentsBytes .= static::buildJpegSegment(self::JPEG_MARKER_APP13, $photoshopApp13Payload);
            }
            $segmentsBytesToInsertByKind[self::JPEG_SEGMENT_KIND_IPTC] = $photoshopSegmentsBytes;
        }
        // in the kind order, so a kind inserted here counts as present for the kinds after it
        foreach ($segmentsBytesToInsertByKind as $kind => $segmentsBytes) {
            $insertionOffset = static::getJpegInsertionOffsetForKind($jpegBytes, $kind);
            $jpegBytes = substr($jpegBytes, 0, $insertionOffset) . $segmentsBytes . substr($jpegBytes, $insertionOffset);
        }
        return $jpegBytes;
    }

    /**
     * @throws UnexpectedValueException When the PNG does not start with IHDR.
     */
    protected function buildPngBytesWithMissingMetadataInserted(string $pngBytes): string
    {
        $chunks = ImageFileStructure::listPngChunks($pngBytes);
        if ($chunks === [] || $chunks[0]['type'] !== 'IHDR') {
            throw new UnexpectedValueException('The PNG does not start with an IHDR chunk');
        }
        $carriesExif = false;
        $carriesXmp = false;
        foreach ($chunks as $chunk) {
            $carriesExif = $carriesExif || $chunk['type'] === 'eXIf';
            $carriesXmp = $carriesXmp || static::isPngXmpChunk($chunk['type'], $chunk['payload']);
        }
        $chunksBytesToInsert = '';
        if ($this->exifTiffBlock !== null && !$carriesExif) {
            $chunksBytesToInsert .= static::buildPngChunk('eXIf', $this->exifTiffBlock);
        }
        if ($this->xmpPacket !== null && !$carriesXmp) {
            // iTXt: keyword, NUL, compression flag 0, compression method 0, empty language tag, NUL,
            // empty translated keyword, NUL, UTF-8 text
            $chunksBytesToInsert .= static::buildPngChunk('iTXt', self::XMP_PNG_KEYWORD . "\0" . "\0\0" . "\0" . "\0" . $this->xmpPacket);
        }
        if ($chunksBytesToInsert === '') {
            return $pngBytes;
        }
        $insertionOffset = $chunks[0]['offset'] + $chunks[0]['length'];
        return substr($pngBytes, 0, $insertionOffset) . $chunksBytesToInsert . substr($pngBytes, $insertionOffset);
    }

    /**
     * Directly after the last segment (before the first SOS) whose kind comes earlier in
     * {@see JPEG_SEGMENT_KIND_ORDER} than $kind; directly after SOI when there is none.
     */
    protected static function getJpegInsertionOffsetForKind(string $jpegBytes, string $kind): int
    {
        $insertionOffset = strlen(ImageFileStructure::JPEG_START_OF_IMAGE);
        $rankOfTheKind = self::JPEG_SEGMENT_KIND_ORDER[$kind];
        foreach (ImageFileStructure::listJpegSegmentsBeforeFirstScan($jpegBytes) as $segment) {
            $segmentKind = static::getJpegSegmentKind($segment['marker'], $segment['payload']);
            if ($segmentKind !== null && self::JPEG_SEGMENT_KIND_ORDER[$segmentKind] < $rankOfTheKind) {
                $insertionOffset = max($insertionOffset, $segment['offset'] + $segment['length']);
            }
        }
        return $insertionOffset;
    }

    /**
     * @throws LengthException When the payload is over 65,533 bytes: the 16-bit length field would be truncated.
     */
    protected static function buildJpegSegment(int $marker, string $payload): string
    {
        if (strlen($payload) > self::MAX_JPEG_SEGMENT_PAYLOAD_LENGTH) {
            throw new LengthException(
                sprintf(
                    'A JPEG segment holds at most %d payload bytes; this one would hold %d',
                    self::MAX_JPEG_SEGMENT_PAYLOAD_LENGTH,
                    strlen($payload),
                )
            );
        }
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    protected static function buildPngChunk(string $chunkType, string $chunkPayload): string
    {
        return pack('N', strlen($chunkPayload)) . $chunkType . $chunkPayload
            . pack('N', crc32($chunkType . $chunkPayload));
    }

    protected static function isPngXmpChunk(string $chunkType, string $chunkPayload): bool
    {
        return in_array($chunkType, ['iTXt', 'zTXt', 'tEXt'], true)
            && str_starts_with($chunkPayload, self::XMP_PNG_KEYWORD . "\0");
    }

    /**
     * The XMP packet of an XMP text chunk, laid out as the PNG specification describes. iTXt: keyword, NUL,
     * compression flag, compression method, language tag, NUL, translated keyword, NUL, text (zlib-compressed when
     * the flag is 1). zTXt: keyword, NUL, compression method, zlib-compressed text. tEXt: keyword, NUL, text.
     *
     * @throws UnexpectedValueException When the layout is broken or a compressed text does not inflate.
     */
    protected static function readXmpPacketFromPngTextChunk(string $chunkType, string $chunkPayload): string
    {
        $afterKeyword = substr($chunkPayload, strlen(self::XMP_PNG_KEYWORD) + 1);
        if ($chunkType === 'tEXt') {
            return $afterKeyword;
        }
        if ($chunkType === 'zTXt') {
            return static::inflateZlibStream(substr($afterKeyword, 1));
        }
        if (strlen($afterKeyword) < 4) {
            throw new UnexpectedValueException('The XMP iTXt chunk is too short for its fields');
        }
        $isCompressed = $afterKeyword[0] === "\x01";
        $afterCompressionFields = substr($afterKeyword, 2);
        $languageTagEnd = strpos($afterCompressionFields, "\0");
        $translatedKeywordEnd = $languageTagEnd === false ? false : strpos($afterCompressionFields, "\0", $languageTagEnd + 1);
        if ($translatedKeywordEnd === false) {
            throw new UnexpectedValueException('The XMP iTXt chunk lacks the NUL after its language tag or translated keyword');
        }
        $text = substr($afterCompressionFields, $translatedKeywordEnd + 1);
        return $isCompressed ? static::inflateZlibStream($text) : $text;
    }

    /**
     * Inflates a compressed XMP text to at most {@see MAX_INFLATED_XMP_PACKET_LENGTH} bytes. gzuncompress() keeps its
     * length limit only roughly: when the stream ends inside its last output buffer it returns more than the limit
     * (16,842,752 bytes came back whole), so the length of the result is checked again. When it stops at the limit it
     * returns false, as it does for a corrupt stream, so the message of a false names both causes.
     *
     * @throws UnexpectedValueException When the stream inflates to more than the limit, or is not a valid zlib stream.
     */
    protected static function inflateZlibStream(string $zlibStream): string
    {
        // gzuncompress() warns and returns false on a corrupt stream and at its length limit; the exception reports it
        $inflatedText = @gzuncompress($zlibStream, self::MAX_INFLATED_XMP_PACKET_LENGTH);
        if ($inflatedText === false) {
            throw new UnexpectedValueException(
                sprintf(
                    'The compressed XMP text of the PNG is not a valid zlib stream, or inflates to more than %d bytes',
                    self::MAX_INFLATED_XMP_PACKET_LENGTH,
                )
            );
        }
        if (strlen($inflatedText) > self::MAX_INFLATED_XMP_PACKET_LENGTH) {
            throw new UnexpectedValueException(
                sprintf('The inflated XMP exceeds %d bytes', self::MAX_INFLATED_XMP_PACKET_LENGTH)
            );
        }
        return $inflatedText;
    }
}
