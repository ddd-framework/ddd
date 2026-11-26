<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\Files;

use DDD\Domain\Common\Entities\Files\Sources\FileContent;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFactsSet;
use DDD\Domain\Common\Entities\Files\Sources\FileImagePreview;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Entities\Files\Sources\FileReferences;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UnsupportedImageFormatException;
use Imagick;
use ImagickException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The registry of the file sources ({@see FileSource}, every service carrying the tag) and the one way to a file
 * named by a reference: facts, bytes and a rendered picture, each under the rights of the running account (the
 * source reads through its entity's service). A reference typed by anyone, a model included, resolves only here.
 */
class FileSourcesService extends Service
{
    /** @var int The largest source a picture is rendered from, unless the caller names its own cap */
    public const int PREVIEW_SOURCE_MAX_BYTES = 26214400;

    /** @var int The most pixels of a source a picture is rendered from, unless the caller names its own cap */
    public const int PREVIEW_SOURCE_MAX_PIXELS = 50000000;

    /** @var int The longest edge a rendered picture may have */
    public const int PREVIEW_MAX_EDGE_PIXELS = 2048;

    /** @var int The shortest "longest edge" a caller may ask for */
    public const int PREVIEW_MIN_EDGE_PIXELS = 16;

    /** @var int JPEG quality of rendered pictures and of copies written without metadata */
    public const int JPEG_QUALITY = 88;

    /** @var string[] Profiles that describe the picture's colours and stay when the metadata is removed */
    public const array KEPT_IMAGE_PROFILES = ['icc', 'icm'];

    /** @var iterable<FileSource> */
    protected iterable $fileSources = [];

    /** @param iterable<FileSource> $fileSources */
    #[Required]
    public function setFileSources(
        #[AutowireIterator(FileSource::TAG)]
        iterable $fileSources
    ): void {
        $this->fileSources = $fileSources;
    }

    /** @return string[] The names of the registered sources, sorted */
    public function getSourceNames(): array
    {
        $sourceNames = [];
        foreach ($this->fileSources as $fileSource) {
            $sourceNames[] = $fileSource->getSourceName();
        }
        sort($sourceNames);
        return $sourceNames;
    }

    public function findFileSourceByName(string $sourceName): ?FileSource
    {
        foreach ($this->fileSources as $fileSource) {
            if ($fileSource->getSourceName() === $sourceName) {
                return $fileSource;
            }
        }
        return null;
    }

    public function hasFileSource(string $sourceName): bool
    {
        return $this->findFileSourceByName($sourceName) !== null;
    }

    /**
     * The reference a text names when it has the form of one AND its source is registered; null for anything else
     * (a path such as `assets/logo.png` is no reference).
     */
    public function findFileReference(string $text): ?FileReference
    {
        $fileReference = FileReference::tryFromString($text);
        if ($fileReference === null || !$this->hasFileSource($fileReference->sourceName)) {
            return null;
        }
        return $fileReference;
    }

    /**
     * The reference a text names.
     *
     * @throws BadRequestException When the text is no reference or names a source that is not registered
     */
    public function resolveFileReference(string $text): FileReference
    {
        $fileReference = FileReference::fromString($text);
        $this->requireFileSource($fileReference);
        return $fileReference;
    }

    /** The facts of one file, or null when the caller may not read it or it does not exist */
    public function findFileFacts(FileReference $fileReference): ?FileFacts
    {
        $fileSource = $this->findFileSourceByName($fileReference->sourceName);
        if ($fileSource === null) {
            return null;
        }
        return $fileSource->findFilesFacts([$fileReference->id])->findByFileReference($fileReference);
    }

    /**
     * The facts of one file.
     *
     * @throws BadRequestException When the source is not registered
     * @throws NotFoundException When there is no such file for the caller
     */
    public function requireFileFacts(FileReference $fileReference): FileFacts
    {
        $this->requireFileSource($fileReference);
        $fileFacts = $this->findFileFacts($fileReference);
        if ($fileFacts === null) {
            throw new NotFoundException(sprintf('%s was not found. Check the reference with the tool or screen that listed it.', $fileReference->toString()));
        }
        return $fileFacts;
    }

    /**
     * The facts of several files, in the order asked; a file the caller may not read (or of an unknown source) is
     * left out. One call per source.
     */
    public function findFilesFacts(FileReferences $fileReferences): FileFactsSet
    {
        $idsBySourceName = [];
        foreach ($fileReferences->getElements() as $fileReference) {
            $idsBySourceName[$fileReference->sourceName][$fileReference->id] = $fileReference->id;
        }
        $foundFactsByReference = [];
        foreach ($idsBySourceName as $sourceName => $ids) {
            $fileSource = $this->findFileSourceByName((string)$sourceName);
            if ($fileSource === null) {
                continue;
            }
            foreach ($fileSource->findFilesFacts(array_map('strval', array_values($ids)))->getElements() as $fileFacts) {
                $foundFactsByReference[$fileFacts->fileReference->toString()] = $fileFacts;
            }
        }
        $fileFactsSet = new FileFactsSet();
        foreach ($fileReferences->getElements() as $fileReference) {
            $fileFacts = $foundFactsByReference[$fileReference->toString()] ?? null;
            if ($fileFacts !== null) {
                $fileFactsSet->add($fileFacts);
            }
        }
        return $fileFactsSet;
    }

    /**
     * The bytes of one file with its facts.
     *
     * @throws BadRequestException When the source is not registered, or the file must not be read (over the cap, not released)
     * @throws NotFoundException When there is no such file for the caller
     */
    public function readFileContent(FileReference $fileReference, int $maxBytes): FileContent
    {
        $fileSource = $this->requireFileSource($fileReference);
        $fileContent = $fileSource->readFileContent($fileReference->id, $maxBytes);
        if ($fileContent->getSizeBytes() > $maxBytes) {
            throw new BadRequestException(sprintf(
                '%s is too large to be read here (%d bytes, at most %d).',
                $fileReference->toString(),
                $fileContent->getSizeBytes(),
                $maxBytes
            ));
        }
        return $fileContent;
    }

    /**
     * A picture of an image file for viewing: decoded with the pinned raster decoder, turned upright, without
     * metadata, its longest edge at most $maxEdgePixels (never enlarged), as JPEG or with transparency as PNG.
     *
     * @throws BadRequestException When the file is no raster picture, or over the caps
     * @throws NotFoundException When there is no such file for the caller
     */
    public function renderImagePreview(
        FileReference $fileReference,
        int $maxEdgePixels,
        ?int $maxSourceBytes = null,
        ?int $maxSourcePixels = null
    ): FileImagePreview {
        $fileContent = $this->readFileContent($fileReference, $maxSourceBytes ?? static::PREVIEW_SOURCE_MAX_BYTES);
        return $this->renderImagePreviewOfContent($fileContent, $maxEdgePixels, $maxSourcePixels);
    }

    /**
     * {@see self::renderImagePreview()} for bytes that were already read.
     *
     * @throws BadRequestException When the bytes are no raster picture, or have more pixels than the cap
     */
    public function renderImagePreviewOfContent(FileContent $fileContent, int $maxEdgePixels, ?int $maxSourcePixels = null): FileImagePreview
    {
        $referenceText = $fileContent->fileFacts->fileReference->toString();
        $bytes = $fileContent->getContent();
        $maxEdgePixels = max(static::PREVIEW_MIN_EDGE_PIXELS, min(static::PREVIEW_MAX_EDGE_PIXELS, $maxEdgePixels));
        $imagick = null;
        try {
            $rasterImageInfo = RasterImageDecoder::probe($bytes);
            $pixelCount = $rasterImageInfo->width * $rasterImageInfo->height;
            $maxSourcePixels ??= static::PREVIEW_SOURCE_MAX_PIXELS;
            if ($pixelCount > $maxSourcePixels) {
                throw new BadRequestException(sprintf(
                    '%s has too many pixels to be rendered (%d x %d, at most %d megapixels).',
                    $referenceText,
                    $rasterImageInfo->width,
                    $rasterImageInfo->height,
                    intdiv($maxSourcePixels, 1000000)
                ));
            }
            $imagick = $this->decodeFirstFrameUpright($bytes);
            $this->removeMetadataOfImage($imagick);
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            $longestEdge = max($width, $height);
            if ($longestEdge > $maxEdgePixels) {
                $scale = $maxEdgePixels / $longestEdge;
                $imagick->thumbnailImage(max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale)));
            }
            $hasTransparency = $imagick->getImageAlphaChannel();
            if ($hasTransparency) {
                RasterImageDecoder::setOutputFormat($imagick, RasterImageDecoder::FORMAT_PNG);
            } else {
                RasterImageDecoder::setOutputFormat($imagick, RasterImageDecoder::FORMAT_JPEG);
                $imagick->setImageCompressionQuality(static::JPEG_QUALITY);
            }
            $fileImagePreview = new FileImagePreview();
            $fileImagePreview->fileFacts = $fileContent->fileFacts;
            $fileImagePreview->contentType = $hasTransparency ? 'image/png' : 'image/jpeg';
            $fileImagePreview->width = $imagick->getImageWidth();
            $fileImagePreview->height = $imagick->getImageHeight();
            $fileImagePreview->setContent($imagick->getImageBlob());
            return $fileImagePreview;
        } catch (UnsupportedImageFormatException) {
            throw new BadRequestException(sprintf('%s is no picture that can be rendered (JPEG, PNG, GIF, WebP or HEIC).', $referenceText));
        } catch (ImagickException $imagickException) {
            throw new BadRequestException(sprintf('%s could not be decoded as a picture: %s', $referenceText, $imagickException->getMessage()));
        } finally {
            $imagick?->clear();
        }
    }

    /**
     * The bytes of a raster picture without its metadata (EXIF, XMP, IPTC: place, time, device), turned upright
     * first so it still looks the same. The colour profile stays. Bytes that carry no metadata and need no turn are
     * returned as they are; otherwise the picture is written again in its own format (HEIC as JPEG). For a sink
     * whose flow decision says {@see \DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision::$stripsMetadata}.
     *
     * @throws BadRequestException When the bytes are no raster picture or cannot be decoded
     */
    public function removeImageMetadata(string $bytes): string
    {
        $imagick = null;
        try {
            $format = RasterImageDecoder::assertAllowedFormat($bytes);
            $imagick = RasterImageDecoder::readBlob($bytes);
            $isSingleFrame = $imagick->getNumberImages() === 1;
            $orientation = $imagick->getImageOrientation();
            $needsTurn = !in_array($orientation, [Imagick::ORIENTATION_UNDEFINED, Imagick::ORIENTATION_TOPLEFT], true);
            $removableProfiles = array_diff(
                array_map('strtolower', $imagick->getImageProfiles('*', false)),
                static::KEPT_IMAGE_PROFILES
            );
            if ($isSingleFrame && !$needsTurn && $removableProfiles === [] && $format !== RasterImageDecoder::FORMAT_HEIC) {
                return $bytes;
            }
            if (!$isSingleFrame) {
                // an animation keeps its frames; only its profiles go
                foreach ($imagick as $frame) {
                    $this->removeMetadataOfImage($frame);
                }
                return $imagick->getImagesBlob();
            }
            $imagick->autoOrient();
            $this->removeMetadataOfImage($imagick);
            if ($format === RasterImageDecoder::FORMAT_HEIC || $format === RasterImageDecoder::FORMAT_JPEG) {
                RasterImageDecoder::setOutputFormat($imagick, RasterImageDecoder::FORMAT_JPEG);
                $imagick->setImageCompressionQuality(max(static::JPEG_QUALITY, 92));
            } else {
                RasterImageDecoder::setOutputFormat($imagick, $format);
            }
            return $imagick->getImageBlob();
        } catch (UnsupportedImageFormatException) {
            throw new BadRequestException('The file is no raster picture (JPEG, PNG, GIF, WebP or HEIC).');
        } catch (ImagickException $imagickException) {
            throw new BadRequestException('The picture could not be decoded: ' . $imagickException->getMessage());
        } finally {
            $imagick?->clear();
        }
    }

    /**
     * @throws BadRequestException When the source of the reference is not registered
     */
    protected function requireFileSource(FileReference $fileReference): FileSource
    {
        $fileSource = $this->findFileSourceByName($fileReference->sourceName);
        if ($fileSource === null) {
            $sourceNames = $this->getSourceNames();
            throw new BadRequestException(sprintf(
                '"%s" names no file source. Known sources: %s.',
                $fileReference->sourceName,
                $sourceNames === [] ? 'none' : implode(', ', $sourceNames)
            ));
        }
        return $fileSource;
    }

    /**
     * The first frame of a raster picture, turned upright.
     *
     * @throws UnsupportedImageFormatException
     * @throws ImagickException
     */
    protected function decodeFirstFrameUpright(string $bytes): Imagick
    {
        $imagick = RasterImageDecoder::readBlob($bytes);
        if ($imagick->getNumberImages() > 1) {
            $imagick->setFirstIterator();
            $firstFrame = $imagick->getImage();
            $imagick->clear();
            $imagick = $firstFrame;
        }
        $imagick->autoOrient();
        return $imagick;
    }

    /**
     * Removes every profile and property of an image except its colour profile.
     *
     * @throws ImagickException
     */
    protected function removeMetadataOfImage(Imagick $imagick): void
    {
        $keptProfiles = [];
        foreach ($imagick->getImageProfiles('*', true) as $profileName => $profileBytes) {
            if (in_array(strtolower((string)$profileName), static::KEPT_IMAGE_PROFILES, true)) {
                $keptProfiles[(string)$profileName] = $profileBytes;
            }
        }
        $imagick->stripImage();
        foreach ($keptProfiles as $profileName => $profileBytes) {
            $imagick->profileImage($profileName, $profileBytes);
        }
        $imagick->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }
}
