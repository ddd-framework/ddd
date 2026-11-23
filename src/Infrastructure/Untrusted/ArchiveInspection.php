<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The entry list of an archive read by {@see ArchiveInspector} (nothing extracted).
 */
class ArchiveInspection extends ValueObject
{
    /** @var string[] Entry names as the archive states them */
    public array $entryNames = [];

    /** @var int Number of entries */
    public int $entryCount = 0;

    /** @var int Sum of the uncompressed sizes */
    public int $uncompressedBytes = 0;

    /** @var int Sum of the compressed sizes */
    public int $compressedBytes = 0;

    /** @var bool An entry name is absolute or contains `..` (never extract such an archive) */
    public bool $hasUnsafeEntryNames = false;

    /**
     * @param string $entryName
     * @return bool case-insensitive exact match
     */
    public function hasEntry(string $entryName): bool
    {
        foreach ($this->entryNames as $existingEntryName) {
            if (strcasecmp($existingEntryName, $entryName) === 0) {
                return true;
            }
        }
        return false;
    }
}
