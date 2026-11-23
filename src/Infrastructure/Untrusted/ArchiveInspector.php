<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use ZipArchive;

/**
 * Reads the entry list of a ZIP from outside (OOXML, ODF, uploads) under caps — entry count, total uncompressed size,
 * compression ratio — and never extracts anything to disk. A single entry's bytes are read with a byte cap.
 */
class ArchiveInspector
{
    public const int DEFAULT_MAX_ENTRIES = 10000;
    public const int DEFAULT_MAX_UNCOMPRESSED_BYTES = 1073741824;
    public const int DEFAULT_MAX_COMPRESSION_RATIO = 200;

    /**
     * @param string $zipPath server-generated path of the archive
     * @param int $maxEntries
     * @param int $maxUncompressedBytes
     * @param int $maxCompressionRatio uncompressed / compressed of the whole archive
     * @return ArchiveInspection
     * @throws UntrustedContentException not a ZIP, or over a cap
     */
    public static function inspectZip(
        string $zipPath,
        int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        int $maxUncompressedBytes = self::DEFAULT_MAX_UNCOMPRESSED_BYTES,
        int $maxCompressionRatio = self::DEFAULT_MAX_COMPRESSION_RATIO
    ): ArchiveInspection {
        $zipArchive = static::openZip($zipPath);
        try {
            if ($zipArchive->numFiles > $maxEntries) {
                throw new UntrustedContentException(sprintf('The archive holds more than %d entries.', $maxEntries));
            }
            $archiveInspection = new ArchiveInspection();
            for ($entryIndex = 0; $entryIndex < $zipArchive->numFiles; $entryIndex++) {
                $entryStat = $zipArchive->statIndex($entryIndex);
                if ($entryStat === false) {
                    throw new UntrustedContentException('The archive has an unreadable entry.');
                }
                $archiveInspection->entryNames[] = (string)$entryStat['name'];
                $archiveInspection->uncompressedBytes += (int)$entryStat['size'];
                $archiveInspection->compressedBytes += (int)$entryStat['comp_size'];
                if (!ContainedPath::isSafeRelativePath(rtrim((string)$entryStat['name'], '/'))) {
                    $archiveInspection->hasUnsafeEntryNames = true;
                }
                if ($archiveInspection->uncompressedBytes > $maxUncompressedBytes) {
                    throw new UntrustedContentException(sprintf('The archive expands to more than %d bytes.', $maxUncompressedBytes));
                }
            }
            $archiveInspection->entryCount = count($archiveInspection->entryNames);
            if ($archiveInspection->uncompressedBytes > 0 && $archiveInspection->uncompressedBytes > $maxCompressionRatio * max(1, $archiveInspection->compressedBytes)) {
                throw new UntrustedContentException('The archive is compressed beyond the allowed ratio.');
            }
            return $archiveInspection;
        } finally {
            $zipArchive->close();
        }
    }

    /**
     * The bytes of one entry, read up to a cap (nothing written to disk).
     * @param string $zipPath
     * @param string $entryName
     * @param int $maxBytes
     * @return string|null null when the archive has no such entry
     * @throws UntrustedContentException not a ZIP, or the entry is larger than the cap
     */
    public static function readEntry(string $zipPath, string $entryName, int $maxBytes = 10485760): ?string
    {
        $zipArchive = static::openZip($zipPath);
        try {
            $entryStat = $zipArchive->statName($entryName);
            if ($entryStat === false) {
                return null;
            }
            if ((int)$entryStat['size'] > $maxBytes) {
                throw new UntrustedContentException(sprintf('The archive entry is larger than %d bytes.', $maxBytes));
            }
            $entryStream = $zipArchive->getStream($entryName);
            if ($entryStream === false) {
                return null;
            }
            $entryBytes = stream_get_contents($entryStream, $maxBytes + 1);
            fclose($entryStream);
            if (!is_string($entryBytes) || strlen($entryBytes) > $maxBytes) {
                throw new UntrustedContentException(sprintf('The archive entry is larger than %d bytes.', $maxBytes));
            }
            return $entryBytes;
        } finally {
            $zipArchive->close();
        }
    }

    /**
     * @param string $zipPath
     * @return ZipArchive
     * @throws UntrustedContentException
     */
    protected static function openZip(string $zipPath): ZipArchive
    {
        $zipArchive = new ZipArchive();
        if ($zipArchive->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new UntrustedContentException('The file is not a readable ZIP archive.');
        }
        return $zipArchive;
    }
}
