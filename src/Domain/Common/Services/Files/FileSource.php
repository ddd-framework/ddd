<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\Files;

use DDD\Domain\Common\Entities\Files\Sources\FileContent;
use DDD\Domain\Common\Entities\Files\Sources\FileFactsSet;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One kind of stored files (a media library, the attachments of a ticket, a drive) as the rest of the system reaches
 * it. A module or an application implements it as a service; the tag registers it, and every reader goes through
 * {@see FileSourcesService}, which resolves `<sourceName>/<id>`.
 *
 * Rules of a source:
 *
 * - It reads only through its entity's service, so the repository's read rights apply as everywhere. It adds no right
 *   and lifts none: a file the caller may not read does not exist for it (left out of the facts, "not found" on read).
 * - It answers facts measured from the bytes, never a client-declared type, name or size.
 * - It refuses what must not be read: content whose scan is not released, a trashed file, bytes over the cap.
 * - It hands out no URL of a store: at most {@see \DDD\Domain\Common\Entities\Files\Sources\FileFacts::$openLink}.
 */
#[AutoconfigureTag(FileSource::TAG)]
interface FileSource
{
    public const string TAG = 'ddd.file_source';

    /**
     * The name in references, e.g. `media`: lower case
     * ({@see \DDD\Domain\Common\Entities\Files\Sources\FileReference::SOURCE_NAME_PATTERN}), unique per application.
     */
    public function getSourceName(): string;

    /**
     * The facts of the files the caller may read; the others are left out.
     *
     * @param string[] $ids
     */
    public function findFilesFacts(array $ids): FileFactsSet;

    /**
     * The bytes of one file with its facts.
     *
     * @throws NotFoundException When there is no such file for the caller
     * @throws BadRequestException When the file must not be read (over $maxBytes, not released, trashed)
     */
    public function readFileContent(string $id, int $maxBytes): FileContent;
}
