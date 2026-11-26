<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Files\Fixtures;

use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileContent;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFactsSet;
use DDD\Domain\Common\Entities\Files\Sources\FileFamily;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Services\Files\FileSource;
use DDD\Infrastructure\Exceptions\NotFoundException;

/**
 * A source over an array of bytes; ids missing from it are files the caller may not read.
 */
class FixtureFileSource implements FileSource
{
    /** @var int How often facts were asked, to prove one call per source */
    public int $findFilesFactsCallCount = 0;

    /**
     * @param array<string, string> $contentById
     * @param array<string, string> $contentTypeById
     */
    public function __construct(
        protected string $sourceName,
        protected array $contentById,
        protected array $contentTypeById = [],
        protected string $audience = FileAudience::INTERNAL
    ) {
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    public function findFilesFacts(array $ids): FileFactsSet
    {
        $this->findFilesFactsCallCount++;
        $fileFactsSet = new FileFactsSet();
        foreach ($ids as $id) {
            if (isset($this->contentById[$id])) {
                $fileFacts = $this->buildFileFacts((string)$id);
                $fileFactsSet->add($fileFacts);
            }
        }
        return $fileFactsSet;
    }

    public function readFileContent(string $id, int $maxBytes): FileContent
    {
        if (!isset($this->contentById[$id])) {
            throw new NotFoundException('not found');
        }
        return FileContent::fromFactsAndContent($this->buildFileFacts($id), $this->contentById[$id]);
    }

    protected function buildFileFacts(string $id): FileFacts
    {
        $fileFacts = new FileFacts();
        $fileFacts->fileReference = new FileReference($this->sourceName, $id);
        $fileFacts->contentType = $this->contentTypeById[$id] ?? 'application/octet-stream';
        $fileFacts->name = 'file-' . $id;
        $fileFacts->family = FileFamily::fromContentType($fileFacts->contentType, $fileFacts->name);
        $fileFacts->sizeBytes = strlen($this->contentById[$id]);
        $fileFacts->audience = $this->audience;
        return $fileFacts;
    }
}
