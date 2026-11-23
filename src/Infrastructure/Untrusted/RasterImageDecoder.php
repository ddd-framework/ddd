<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use finfo;
use Imagick;
use ImagickException;

/**
 * The one way foreign bytes reach Imagick.
 *
 * - The format comes from the magic bytes, never from a file name or a client MIME type. Only the raster formats
 *   JPEG, PNG, GIF, WebP and HEIC/HEIF are decoded; anything else (PostScript, EPS, PDF, SVG, MVG, MSL, text, …) is
 *   refused with an {@see UnsupportedImageFormatException} before Imagick sees a byte.
 * - The decoder is fixed: `setFormat()` before `readImageBlob()` (Imagick then runs exactly that coder, a PostScript
 *   body cannot switch it; reset right after the read so the wand never picks an encoder from it), a `png:` / `jpeg:`
 *   … prefix on file reads.
 * - Every read applies the resource limits first. An ImageMagick `policy.xml` that allows only these coders is the
 *   second fence (README › Untrusted input); these limits stay at or below its ceiling.
 *
 * Static: entities (MediaItem contents) and static helpers decode images without a container.
 */
class RasterImageDecoder
{
    public const string FORMAT_JPEG = 'JPEG';
    public const string FORMAT_PNG = 'PNG';
    public const string FORMAT_GIF = 'GIF';
    public const string FORMAT_WEBP = 'WEBP';
    public const string FORMAT_HEIC = 'HEIC';

    /** @var string[] The Imagick coders foreign bytes may reach */
    public const array ALLOWED_FORMATS = [self::FORMAT_JPEG, self::FORMAT_PNG, self::FORMAT_GIF, self::FORMAT_WEBP, self::FORMAT_HEIC];

    /** @var string[] MIME type by allowed format */
    public const array MIME_TYPES = [
        self::FORMAT_JPEG => 'image/jpeg',
        self::FORMAT_PNG => 'image/png',
        self::FORMAT_GIF => 'image/gif',
        self::FORMAT_WEBP => 'image/webp',
        self::FORMAT_HEIC => 'image/heic',
    ];

    /** @var string[] Other names of an allowed format => the coder's own (upper-case) name */
    public const array FORMAT_NAME_ALIASES = ['JPG' => self::FORMAT_JPEG, 'JPE' => self::FORMAT_JPEG, 'HEIF' => self::FORMAT_HEIC];

    /** @var string[] ISO-BMFF brands of HEIF still images and sequences (decoded by the HEIC coder) */
    public const array HEIF_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    /** @var string[] ISO-BMFF brands of AVIF (a HEIF container with AV1, not on the allow-list) */
    public const array AVIF_BRANDS = ['avif', 'avis'];

    /** @var int Bytes read from a file to tell its format */
    public const int HEADER_BYTES = 256;

    // Resource limits of one decode (a policy.xml may set a lower ceiling; Imagick can only lower its limits)
    public const int MEMORY_BYTES = 268435456;
    public const int MAP_BYTES = 536870912;
    public const int DISK_BYTES = 1073741824;
    public const int AREA_PIXELS = 50000000;
    public const int MAX_SIDE_PIXELS = 16384;
    public const int TIME_SECONDS = 30;

    /**
     * Sets the Imagick resource limits of one decode.
     * @return void
     */
    public static function applyResourceLimits(): void
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, static::MEMORY_BYTES);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, static::MAP_BYTES);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, static::DISK_BYTES);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, static::AREA_PIXELS);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_WIDTH, static::MAX_SIDE_PIXELS);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_HEIGHT, static::MAX_SIDE_PIXELS);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, static::TIME_SECONDS);
    }

    /**
     * The allowed raster format the magic bytes announce.
     * @param string $bytes the whole content or at least its first HEADER_BYTES
     * @return string|null one of ALLOWED_FORMATS, null for everything else
     */
    public static function detectFormat(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return static::FORMAT_JPEG;
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return static::FORMAT_PNG;
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return static::FORMAT_GIF;
        }
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return static::FORMAT_WEBP;
        }
        return static::isHeif($bytes) ? static::FORMAT_HEIC : null;
    }

    /**
     * The MIME type of an allowed raster format read from the bytes.
     * @param string $bytes
     * @return string|null `image/jpeg` … `image/heic`, null for everything else
     */
    public static function detectMimeType(string $bytes): ?string
    {
        $format = static::detectFormat($bytes);
        return $format !== null ? static::MIME_TYPES[$format] : null;
    }

    /**
     * @param string $path server-generated
     * @return string|null one of ALLOWED_FORMATS, null for everything else (an unreadable file included)
     */
    public static function detectFormatOfFile(string $path): ?string
    {
        return static::detectFormat(static::readHead($path));
    }

    /**
     * @param string $path server-generated
     * @return string the first HEADER_BYTES of the file ('' for a missing or unreadable file)
     */
    public static function readHead(string $path): string
    {
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            return '';
        }
        $head = fread($handle, static::HEADER_BYTES);
        fclose($handle);
        return is_string($head) ? $head : '';
    }

    /**
     * @param string $bytes
     * @return bool true for bytes of an allowed raster format
     */
    public static function isAllowed(string $bytes): bool
    {
        return static::detectFormat($bytes) !== null;
    }

    /**
     * @param string $bytes
     * @return string the allowed format of the bytes
     * @throws UnsupportedImageFormatException
     */
    public static function assertAllowedFormat(string $bytes): string
    {
        $format = static::detectFormat($bytes);
        if ($format === null) {
            throw UnsupportedImageFormatException::forDetectedType(static::describeContentType($bytes));
        }
        return $format;
    }

    /**
     * @param string $bytes
     * @return string the MIME type libmagic reads from the bytes, for messages ('unknown' when it cannot tell)
     */
    public static function describeContentType(string $bytes): string
    {
        if ($bytes === '') {
            return 'empty';
        }
        if (!class_exists(finfo::class)) {
            return 'unknown';
        }
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer(substr($bytes, 0, 65536));
        return is_string($mimeType) && $mimeType !== '' ? strtolower($mimeType) : 'unknown';
    }

    /**
     * Decodes bytes of an allowed raster format with that format's coder fixed, under the resource limits.
     * @param string $bytes
     * @param Imagick|null $imagick an instance to read into (settings such as `jpeg:size` already set), else a new one
     * @return Imagick
     * @throws UnsupportedImageFormatException the bytes are no allowed raster image
     * @throws ImagickException the bytes are corrupt or exceed a limit
     */
    public static function readBlob(string $bytes, ?Imagick $imagick = null): Imagick
    {
        $format = static::assertAllowedFormat($bytes);
        static::applyResourceLimits();
        $imagick ??= new Imagick();
        $imagick->setFormat($format);
        $imagick->readImageBlob($bytes);
        // the wand's format would otherwise also pick the ENCODER of every image derived from it (a flattened or
        // cloned image written after setImageFormat('jpeg') came out in the source format)
        $imagick->setFormat('');
        return $imagick;
    }

    /**
     * The coder name of an allowed raster format: upper case, aliases resolved (`jpg` → `JPEG`). An allow-list
     * `policy.xml` matches module names case-sensitively on some ImageMagick builds, so a lower-case `jpeg` is refused
     * where `JPEG` is allowed.
     * @param string $format
     * @return string one of ALLOWED_FORMATS
     * @throws UnsupportedImageFormatException the name is no allowed raster format
     */
    public static function normalizeFormatName(string $format): string
    {
        $formatName = strtoupper(trim($format));
        $formatName = static::FORMAT_NAME_ALIASES[$formatName] ?? $formatName;
        if (!in_array($formatName, static::ALLOWED_FORMATS, true)) {
            throw new UnsupportedImageFormatException(
                'Output format not allowed: ' . $format . '; allowed: ' . implode(', ', static::ALLOWED_FORMATS)
            );
        }
        return $formatName;
    }

    /**
     * Sets the encoder of an image to an allowed raster format, by its upper-case coder name.
     * @param Imagick $imagick
     * @param string $format `jpeg`, `jpg`, `PNG`, `webp`, …
     * @return string the coder name set
     * @throws UnsupportedImageFormatException the name is no allowed raster format
     * @throws ImagickException
     */
    public static function setOutputFormat(Imagick $imagick, string $format): string
    {
        $formatName = static::normalizeFormatName($format);
        $imagick->setImageFormat($formatName);
        return $formatName;
    }

    /**
     * Width, height and MIME type of an allowed raster image, read from its header only (no pixel decode).
     * @param string $bytes
     * @return RasterImageInfo
     * @throws UnsupportedImageFormatException the bytes are no allowed raster image
     * @throws ImagickException the header is corrupt
     */
    public static function probe(string $bytes): RasterImageInfo
    {
        $format = static::assertAllowedFormat($bytes);
        $rasterImageInfo = new RasterImageInfo();
        $rasterImageInfo->format = $format;
        $rasterImageInfo->mimeType = static::MIME_TYPES[$format];
        $rasterImageInfo->sizeBytes = strlen($bytes);
        $imageSize = $format !== static::FORMAT_HEIC ? @getimagesizefromstring($bytes) : false;
        if (is_array($imageSize) && $imageSize[0] > 0 && $imageSize[1] > 0) {
            $rasterImageInfo->width = (int)$imageSize[0];
            $rasterImageInfo->height = (int)$imageSize[1];
            return $rasterImageInfo;
        }
        static::applyResourceLimits();
        $imagick = new Imagick();
        try {
            $imagick->setFormat($format);
            $imagick->pingImageBlob($bytes);
            $rasterImageInfo->width = $imagick->getImageWidth();
            $rasterImageInfo->height = $imagick->getImageHeight();
        } finally {
            $imagick->clear();
        }
        return $rasterImageInfo;
    }

    /**
     * Decodes (or only pings) the FIRST frame of a file of an allowed raster format, the format fixed by the prefix.
     * @param string $path absolute, server-generated
     * @param Imagick|null $imagick
     * @param bool $pingOnly header only (width / height), no pixel decode
     * @return Imagick
     * @throws UnsupportedImageFormatException
     * @throws ImagickException
     */
    public static function readFirstFrameOfFile(string $path, ?Imagick $imagick = null, bool $pingOnly = false): Imagick
    {
        $format = static::assertAllowedFormat(static::readHead($path));
        static::applyResourceLimits();
        $imagick ??= new Imagick();
        $source = strtolower($format) . ':' . $path . '[0]';
        if ($pingOnly) {
            $imagick->pingImage($source);
        } else {
            $imagick->readImage($source);
        }
        return $imagick;
    }

    /**
     * @param string $bytes
     * @return bool An ISO-BMFF file whose brands name a HEIF still image (not AVIF)
     */
    protected static function isHeif(string $bytes): bool
    {
        if (strlen($bytes) < 16 || substr($bytes, 4, 4) !== 'ftyp') {
            return false;
        }
        $boxSize = unpack('N', substr($bytes, 0, 4))[1];
        $boxEnd = min(max($boxSize, 16), strlen($bytes), static::HEADER_BYTES);
        $brands = [substr($bytes, 8, 4)];
        for ($offset = 16; $offset + 4 <= $boxEnd; $offset += 4) {
            $brands[] = substr($bytes, $offset, 4);
        }
        if (array_intersect($brands, static::AVIF_BRANDS) !== []) {
            return false;
        }
        return array_intersect($brands, static::HEIF_BRANDS) !== [];
    }
}
