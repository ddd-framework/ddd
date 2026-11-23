<?php

declare(strict_types=1);

namespace DDD\Testing\UntrustedInput;

use PHPUnit\Framework\TestCase;

/**
 * The sink scan of a package or an application: every raw call to a sink of foreign input in its sources goes through
 * the helpers of `DDD\Infrastructure\Untrusted` or is listed in `config/untrusted-sinks-allowlist.php` with a reason.
 * An allow-list entry that no longer matches a call fails as well, so the list never outlives the code.
 *
 *     class UntrustedSinkScanTest extends UntrustedSinkScanTestCase
 *     {
 *         protected function getPackageRootDirectory(): string
 *         {
 *             return dirname(__DIR__);
 *         }
 *     }
 */
abstract class UntrustedSinkScanTestCase extends TestCase
{
    /**
     * @return string absolute root of the package or application (holds `src/` and `config/`)
     */
    abstract protected function getPackageRootDirectory(): string;

    /**
     * @return string[] source directories relative to the root
     */
    protected function getSourceDirectories(): array
    {
        return ['src'];
    }

    /**
     * @return string the allow-list file
     */
    protected function getAllowListFilePath(): string
    {
        return rtrim($this->getPackageRootDirectory(), '/') . '/config/untrusted-sinks-allowlist.php';
    }

    public function testEveryRawSinkCallIsAllowListed(): void
    {
        $untrustedSinkAllowList = UntrustedSinkAllowList::fromFile($this->getAllowListFilePath());
        $uncoveredDescriptions = [];
        foreach ($this->findSinkCalls()->getElements() as $untrustedSinkFinding) {
            if (!$untrustedSinkAllowList->covers($untrustedSinkFinding)) {
                $uncoveredDescriptions[] = $untrustedSinkFinding->describe();
            }
        }
        $this->assertSame(
            [],
            $uncoveredDescriptions,
            "Raw sink calls outside the helpers of DDD\\Infrastructure\\Untrusted and outside the allow-list.\n"
            . "Use the helper (skill ddd-untrusted-input-specialist) or add an entry with its reason to "
            . $this->getAllowListFilePath()
        );
    }

    public function testEveryAllowListEntryStillMatchesACall(): void
    {
        $untrustedSinkFindings = $this->findSinkCalls();
        $staleEntries = [];
        foreach (UntrustedSinkAllowList::fromFile($this->getAllowListFilePath())->getElements() as $untrustedSinkAllowListEntry) {
            $isMatched = false;
            foreach ($untrustedSinkFindings->getElements() as $untrustedSinkFinding) {
                if ($untrustedSinkAllowListEntry->covers($untrustedSinkFinding)) {
                    $isMatched = true;
                    break;
                }
            }
            if (!$isMatched) {
                $staleEntries[] = $untrustedSinkAllowListEntry->filePath . ' ' . $untrustedSinkAllowListEntry->sink;
            }
        }
        $this->assertSame([], $staleEntries, 'Allow-list entries without a matching call: remove them.');
    }

    /**
     * @return UntrustedSinkFindings
     */
    protected function findSinkCalls(): UntrustedSinkFindings
    {
        static $findingsByRoot = [];
        $packageRootDirectory = $this->getPackageRootDirectory();
        return $findingsByRoot[$packageRootDirectory] ??= (new UntrustedSinkScanner())->scan($packageRootDirectory, $this->getSourceDirectories());
    }
}
