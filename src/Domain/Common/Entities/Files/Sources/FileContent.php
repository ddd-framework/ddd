<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Files\Sources;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The bytes of one file together with its facts, read under the caller's rights and capped by the caller
 * ({@see \DDD\Domain\Common\Services\Files\FileSource::readFileContent()}). The bytes are never serialized: they are
 * read with {@see self::getContent()} or as a stream.
 */
class FileContent extends ValueObject
{
    /** @var FileFacts The facts of the file the bytes belong to */
    public FileFacts $fileFacts;

    /** @var string The bytes */
    protected string $content = '';

    public static function fromFactsAndContent(FileFacts $fileFacts, string $content): FileContent
    {
        $fileContent = new static();
        $fileContent->fileFacts = $fileFacts;
        $fileContent->content = $content;
        return $fileContent;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getSizeBytes(): int
    {
        return strlen($this->content);
    }

    /**
     * The bytes as a readable stream positioned at its start.
     *
     * @return resource
     */
    public function getContentStream()
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $this->content);
        rewind($stream);
        return $stream;
    }
}
