<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Untrusted;

use DDD\Infrastructure\Untrusted\ArchiveInspector;
use DDD\Infrastructure\Untrusted\UntrustedContentException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ArchiveInspectorTest extends TestCase
{
    protected function createZip(array $entries): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        $zipArchive = new ZipArchive();
        $zipArchive->open($zipPath, ZipArchive::OVERWRITE);
        foreach ($entries as $entryName => $entryContent) {
            $zipArchive->addFromString($entryName, $entryContent);
        }
        $zipArchive->close();
        return $zipPath;
    }

    public function testListsEntriesWithoutExtracting(): void
    {
        $zipPath = $this->createZip(['word/document.xml' => '<a/>', '../evil.txt' => 'x']);
        try {
            $archiveInspection = ArchiveInspector::inspectZip($zipPath);
            $this->assertSame(2, $archiveInspection->entryCount);
            $this->assertTrue($archiveInspection->hasEntry('WORD/document.xml'));
            $this->assertTrue($archiveInspection->hasUnsafeEntryNames);
            $this->assertSame('<a/>', ArchiveInspector::readEntry($zipPath, 'word/document.xml'));
        } finally {
            @unlink($zipPath);
        }
    }

    public function testRefusesABombAndTooManyEntries(): void
    {
        $zipPath = $this->createZip(['bomb.txt' => str_repeat("\0", 5000000)]);
        try {
            $this->expectException(UntrustedContentException::class);
            ArchiveInspector::inspectZip($zipPath);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testRefusesTooManyEntries(): void
    {
        $zipPath = $this->createZip(['a' => 'a', 'b' => 'b', 'c' => 'c']);
        try {
            $this->expectException(UntrustedContentException::class);
            ArchiveInspector::inspectZip($zipPath, 2);
        } finally {
            @unlink($zipPath);
        }
    }
}
