<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Untrusted;

use DDD\Testing\UntrustedInput\UntrustedSinkAllowList;
use DDD\Testing\UntrustedInput\UntrustedSinkScanner;
use PHPUnit\Framework\TestCase;

class UntrustedSinkScannerTest extends TestCase
{
    public function testFindsEveryRawSinkOfTheFixture(): void
    {
        $untrustedSinkFindings = (new UntrustedSinkScanner())->scanFile(__DIR__ . '/Fixtures/SinkCallsFixture.php.txt', 'fixture.php');
        $foundSinks = [];
        foreach ($untrustedSinkFindings as $untrustedSinkFinding) {
            $foundSinks[] = $untrustedSinkFinding->line . ':' . $untrustedSinkFinding->sink;
        }
        $this->assertSame([
            '11:shell', '12:shell', '13:eval', '14:unserialize', '16:imagick-decode', '17:xml-parse', '18:shell',
            '19:process', '20:file-path-from-request',
        ], $foundSinks);
    }

    public function testAllowListCoversByFileSinkAndLines(): void
    {
        $allowListPath = tempnam(sys_get_temp_dir(), 'allowlist') . '.php';
        file_put_contents($allowListPath, "<?php return [['file' => 'fixture.php', 'sink' => 'shell', 'lines' => [11, 12], 'reason' => 'test']];");
        try {
            $untrustedSinkAllowList = UntrustedSinkAllowList::fromFile($allowListPath);
            $coveredLines = [];
            foreach ((new UntrustedSinkScanner())->scanFile(__DIR__ . '/Fixtures/SinkCallsFixture.php.txt', 'fixture.php') as $untrustedSinkFinding) {
                if ($untrustedSinkAllowList->covers($untrustedSinkFinding)) {
                    $coveredLines[] = $untrustedSinkFinding->line;
                }
            }
            $this->assertSame([11, 12], $coveredLines);
        } finally {
            @unlink($allowListPath);
        }
    }

    public function testAllowListEntryNeedsAReason(): void
    {
        $allowListPath = tempnam(sys_get_temp_dir(), 'allowlist') . '.php';
        file_put_contents($allowListPath, "<?php return [['file' => 'fixture.php', 'sink' => 'shell']];");
        try {
            $this->expectException(\InvalidArgumentException::class);
            UntrustedSinkAllowList::fromFile($allowListPath);
        } finally {
            @unlink($allowListPath);
        }
    }
}
