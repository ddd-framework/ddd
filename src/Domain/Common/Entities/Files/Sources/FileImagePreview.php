<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Files\Sources;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A picture of a file rendered by the server for viewing
 * ({@see \DDD\Domain\Common\Services\Files\FileSourcesService::renderImagePreview()}): decoded with the pinned
 * raster decoder, turned upright, without metadata, at most the asked edge, as JPEG or (with transparency) PNG.
 */
class FileImagePreview extends ValueObject
{
    /** @var FileFacts The facts of the file the preview shows */
    public FileFacts $fileFacts;

    /** @var string The content type of the rendered bytes: image/jpeg or image/png */
    public string $contentType;

    /** @var int Width of the rendered picture in pixels */
    public int $width;

    /** @var int Height of the rendered picture in pixels */
    public int $height;

    /** @var string The rendered bytes */
    protected string $content = '';

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
