<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Files\Sources;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a {@see \DDD\Domain\Common\Services\Files\FileSource} answers about one file the caller may read. Type and
 * size are measured from the bytes by the source, never taken from what a client declared. No content, no content
 * URL: bytes leave a source only through {@see \DDD\Domain\Common\Services\Files\FileSourcesService}.
 */
class FileFacts extends ValueObject
{
    /** @var FileReference The address of the file */
    public FileReference $fileReference;

    /** @var string The file name (a source without names builds one); foreign text when isContentUntrusted is set */
    public string $name = '';

    /** @var string One of FileFamily::FAMILIES */
    public string $family = FileFamily::OTHER;

    /** @var string|null The content type measured from the bytes */
    public ?string $contentType = null;

    /** @var int|null The size of the bytes */
    public ?int $sizeBytes = null;

    /** @var string|null The sha256 of the bytes, when the source knows it without reading them */
    public ?string $sha256 = null;

    /** @var int|null Width in pixels (pictures) */
    public ?int $width = null;

    /** @var int|null Height in pixels (pictures) */
    public ?int $height = null;

    /** @var string One of FileAudience::AUDIENCES: who sees the file where it lies */
    public string $audience = FileAudience::INTERNAL;

    /** @var bool True when name and content came from outside the operating team (a customer, a website) */
    public bool $isContentUntrusted = true;

    /** @var string|null Where the file lies, for people ("Ticket #2511", "Team Musterstadt", "Media library") */
    public ?string $containerLabel = null;

    /** @var string|null The application screen that shows the file: a path of the application, never a content URL */
    public ?string $openLink = null;

    /**
     * Sets the screen link when it is a path of the application (`/admin/media/901`): no scheme, no host, no
     * protocol-relative form, so a source can never hand out a content URL of a store by mistake.
     */
    public function setOpenLinkIfSafe(?string $openLink): void
    {
        $this->openLink = null;
        if ($openLink === null || $openLink === '') {
            return;
        }
        $isApplicationPath = str_starts_with($openLink, '/')
            && !str_starts_with($openLink, '//')
            && !str_contains($openLink, '\\')
            && !str_contains($openLink, '://')
            && preg_match('/[\x00-\x1F\x7F]/', $openLink) !== 1;
        if ($isApplicationPath) {
            $this->openLink = $openLink;
        }
    }

    public function isImage(): bool
    {
        return $this->family === FileFamily::IMAGE;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->fileReference->toString());
    }
}
