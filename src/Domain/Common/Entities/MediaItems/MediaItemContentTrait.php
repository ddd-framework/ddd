<?php

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Infrastructure\Exceptions\Exception;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\PhotoUtils\PhotoUtils;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\GuardedHttpFetchService;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UnsupportedImageFormatException;
use DDD\Infrastructure\Traits\Serializer\Attributes\DontPersistProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Imagick;
use ImagickException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * @method MediaItem getParent()
 */
trait MediaItemContentTrait
{
    /** @var int Largest image loadFromSource() reads (bytes) */
    public const int MAX_SOURCE_BYTES = 20971520;

    /** @var string|null */
    public ?string $base64EncodedContent;

    /** @var int|null Height in pixels */
    public ?int $height;

    /** @var int|null Width in pixels */
    public ?int $width;

    /** @var float|null File size in bytes */
    public ?float $fileSize;

    /** @var string|null File format */
    public ?string $fileFormat;

    /** @var string|null Body content */
    #[HideProperty, DontPersistProperty]
    public ?string $body;

    /** @var string|null Name of the mediaItem */
    public ?string $name;

    /** @var bool|null If true, photo uses compression */
    public ?bool $compression = false;

    /** @var string|null Mediaitem external id */
    public ?string $externalId;

    /** @var string|null md5 of the bytes width, height, size and format were measured from (never from a client) */
    protected ?string $contentInfoMeasuredForBytesHash = null;

    /**
     * Returns the raw binary image blob.
     *
     * Two ingestion paths exist for MediaItemContent and both must work transparently for downstream
     * code (thumbnailing, hashing, embeddings, format detection):
     *   - Classical JSON path: $base64EncodedContent is set (via DTO hydration), $body is not.
     *   - Multipart/upload path: $body is set directly from the uploaded bytes (via loadFromUploadedFile),
     *     $base64EncodedContent is left unset and only generated lazily on serialization.
     *
     * This method is the single read-side accessor that hides which path was used. Prefers $body when present
     * (avoids the base64 decode round-trip on the upload path) and falls back to decoding $base64EncodedContent
     * for the classical path. Existing callers that only ever set base64 see no behavior change.
     */
    public function getImageBlob(): bool|string|null
    {
        if (isset($this->body) && $this->body !== '') {
            return $this->body;
        }

        if (!isset($this->base64EncodedContent)) {
            return null;
        }

        if (str_contains($this->base64EncodedContent, ',')) {
            return base64_decode(PhotoUtils::getEncodedImageString($this->base64EncodedContent));
        }

        return base64_decode($this->base64EncodedContent);
    }

    public function getScope(): ?string
    {
        return $this->getParent()->scope ?? null;
    }

    /**
     * True when width, height, size and format are known AND were measured by the server from the current bytes.
     * Values a client sent with the content (`fileFormat: "text/html"`, a width) never count: whenever bytes are
     * present they are measured again. Without bytes (an item loaded without its content) the stored values stand.
     * @return bool
     */
    public function isMediaItemContentInfoGenerated(): bool
    {
        if (!isset($this->width, $this->height, $this->fileSize, $this->fileFormat)) {
            return false;
        }
        $contentBytes = $this->getImageBlob();
        if (!is_string($contentBytes) || $contentBytes === '') {
            return true;
        }
        return $this->contentInfoMeasuredForBytesHash === md5($contentBytes);
    }

    /**
     * Measures width, height, size and format from the bytes (never from what a client declared). An allowed raster
     * image (JPEG, PNG, GIF, WebP, HEIC) is probed from its header; another image format getimagesize() reads keeps
     * working; anything else is refused.
     * @throws Exception
     */
    public function populateMediaItemContentInfo(): void
    {
        $decodedImage = $this->getImageBlob();
        if (!is_string($decodedImage) || $decodedImage === '') {
            throw new NotFoundException('Invalid image format');
        }
        if (RasterImageDecoder::isAllowed($decodedImage)) {
            try {
                $rasterImageInfo = RasterImageDecoder::probe($decodedImage);
            } catch (ImagickException) {
                throw new NotFoundException('Invalid image format');
            }
            $this->width = $rasterImageInfo->width;
            $this->height = $rasterImageInfo->height;
            $this->fileFormat = $rasterImageInfo->mimeType;
        } else {
            $imageInfo = PhotoUtils::getImageInfoFromString($decodedImage);
            if (!$imageInfo) {
                throw new NotFoundException('Invalid image format');
            }
            $this->width = (int)$imageInfo[0];
            $this->height = (int)$imageInfo[1];
            $this->fileFormat = (string)$imageInfo['mime'];
        }
        $this->fileSize = strlen($decodedImage);
        $this->contentInfoMeasuredForBytesHash = md5($decodedImage);
    }

    /**
     * Populate media item content information from an Imagick instance
     *
     * @param Imagick $imagickInstance The Imagick instance to extract image information from
     * @throws Exception
     */
    public function populateMediaItemContentInfoFromImagick(Imagick $imagickInstance): void
    {
        // Get image dimensions
        $this->width = $imagickInstance->getImageWidth();
        $this->height = $imagickInstance->getImageHeight();

        // Get the image format (MIME type)
        $this->fileFormat = $imagickInstance->getImageMimeType();

        // Get the image blob and calculate its size
        $this->body = $imagickInstance->getImageBlob();
        if (isset($this->body)) {
            $this->base64EncodedContent = base64_encode($this->body);
        }
        $this->fileSize = strlen($this->body); // File size in bytes
        $this->contentInfoMeasuredForBytesHash = md5($this->body);
    }

    /**
     * @return string Retuns base64 encoded content
     */
    public function getBase64EncodedContent(): ?string
    {
        if (isset($this->base64EncodedContent)) {
            return $this->base64EncodedContent;
        }
        if (!isset($this->body)) {
            return null;
        }
        $this->base64EncodedContent = base64_encode($this->body);
        return $this->base64EncodedContent;
    }

    /**
     * Loads MediaItemContent from a Symfony UploadedFile (multipart/form-data ingestion path).
     *
     * This is the binary counterpart to the classical JSON+base64 ingestion that happens implicitly
     * via DTO hydration of $base64EncodedContent. It exists so endpoints can accept native HTTP file
     * uploads (e.g. images attached to a chat message) without forcing the client to base64-encode
     * payloads — saving 33% of bandwidth, peak PHP heap, and a class of silent base64-corruption bugs.
     *
     * Behavior:
     *   - Reads the file's bytes directly into $body (no base64 round-trip).
     *   - Validates decodability up front via Imagick — throws NotFoundException on bad/corrupt files,
     *     so the caller can fail the single attachment without taking down the surrounding request.
     *   - Populates width/height/fileFormat/fileSize via populateMediaItemContentInfoFromImagick().
     *   - Leaves $base64EncodedContent unset; getBase64EncodedContent() will lazily produce it on demand
     *     (e.g. when the entity is serialized into a response), keeping memory pressure low until then.
     *
     * Use loadFromUploadedFile() for HTTP multipart uploads. Use loadFromSource() for path/URL-based
     * loading (server-side fetch). Use the existing $base64EncodedContent setter for JSON ingestion.
     *
     * @throws NotFoundException When the file cannot be read or decoded.
     * @throws ImagickException
     */
    public function loadFromUploadedFile(UploadedFile $file): void
    {
        $path = $file->getPathname();
        if (!is_readable($path)) {
            throw new NotFoundException("Uploaded file not readable: $path");
        }

        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            throw new NotFoundException("Uploaded file is empty or unreadable: $path");
        }

        // only an allowed raster image is decoded, its coder fixed (UnsupportedImageFormatException otherwise)
        $imagick = RasterImageDecoder::readBlob($content);

        $this->body = $content;
        // the client's name is a label only: no directory part, no control characters
        $this->name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?? '');
        $this->populateMediaItemContentInfoFromImagick($imagick);

        $imagick->clear();
        $imagick->destroy();
    }

    /**
     * Loads MediaItemContent from a URL or a server-side file. A URL is fetched through
     * {@see GuardedHttpFetchService} (public addresses only, pinned connect, re-checked redirects, byte cap); a path is
     * read only as a plain local file (no stream wrapper such as `phar://`, `php://`, `data:`). Only an allowed raster
     * image is decoded.
     * @param string|null $source URL or absolute server path; null = the parent's public URL
     * @return void
     * @throws Exception
     * @throws NotFoundException
     * @throws ImagickException
     */
    public function loadFromSource(?string $source = null): void
    {
        if (!$source) {
            $source = $this->getParent()->publicUrl ?? null;
        }
        if (!$source) {
            throw new NotFoundException('No source specified');
        }
        $contentIsUrl = str_starts_with($source, 'http://') || str_starts_with($source, 'https://');

        if ($contentIsUrl) {
            /** @var GuardedHttpFetchService $guardedHttpFetchService */
            $guardedHttpFetchService = DDDService::instance()->getService(GuardedHttpFetchService::class);
            $guardedHttpFetchResult = $guardedHttpFetchService->fetch($source, static::MAX_SOURCE_BYTES);
            if (!$guardedHttpFetchResult->isSuccessful() || $guardedHttpFetchResult->isTruncated) {
                throw new NotFoundException("Unable to read from URL: $source");
            }
            $content = $guardedHttpFetchResult->body;
        } else {
            if (str_contains($source, '://') || str_starts_with($source, 'data:') || !is_file($source)) {
                throw new NotFoundException("File not found: $source");
            }
            $content = @file_get_contents($source);
            if ($content === false) {
                throw new NotFoundException("Unable to read file: $source");
            }
        }

        try {
            $imagick = RasterImageDecoder::readBlob($content);
        } catch (UnsupportedImageFormatException $unsupportedImageFormatException) {
            throw new NotFoundException('Unable to process image content: ' . $unsupportedImageFormatException->getMessage());
        } catch (ImagickException $imagickException) {
            throw new NotFoundException('Unable to process image content: ' . $imagickException->getMessage());
        }

        $this->populateMediaItemContentInfoFromImagick($imagick);
    }

    public function getBody(): ?string
    {
        if (isset($this->body)) {
            return $this->body;
        }
        if (isset($this->base64EncodedContent)) {
            $this->body = base64_decode($this->base64EncodedContent);
            return $this->body;
        }
        return null;
    }

    public function onToObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): void {
        $this->getBase64EncodedContent();
        $this->body = null;
    }
}