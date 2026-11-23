<?php

declare(strict_types=1);

namespace DDD\Testing\UntrustedInput;

use InvalidArgumentException;

/**
 * The allow-list of a package (`config/untrusted-sinks-allowlist.php`, a PHP file returning a list of entries with
 * `file`, `sink`, optional `lines` [from, to] and a required `reason`).
 * A plain collection on purpose: the scan runs in test suites without a booted kernel.
 */
class UntrustedSinkAllowList
{
    /** @var UntrustedSinkAllowListEntry[] */
    protected array $untrustedSinkAllowListEntries = [];

    /**
     * @param UntrustedSinkAllowListEntry $untrustedSinkAllowListEntry
     * @return void
     */
    public function add(UntrustedSinkAllowListEntry $untrustedSinkAllowListEntry): void
    {
        $this->untrustedSinkAllowListEntries[] = $untrustedSinkAllowListEntry;
    }

    /**
     * @return UntrustedSinkAllowListEntry[]
     */
    public function getElements(): array
    {
        return $this->untrustedSinkAllowListEntries;
    }

    /**
     * @param string $allowListFilePath
     * @return static an empty list when the file does not exist
     * @throws InvalidArgumentException an entry without file, sink or reason
     */
    public static function fromFile(string $allowListFilePath): static
    {
        $untrustedSinkAllowList = new static();
        if (!is_file($allowListFilePath)) {
            return $untrustedSinkAllowList;
        }
        $rawEntries = require $allowListFilePath;
        if (!is_array($rawEntries)) {
            throw new InvalidArgumentException("{$allowListFilePath} must return a list of entries.");
        }
        foreach ($rawEntries as $entryIndex => $rawEntry) {
            if (!is_array($rawEntry) || !is_string($rawEntry['file'] ?? null) || !is_string($rawEntry['sink'] ?? null)
                || trim((string)($rawEntry['reason'] ?? '')) === ''
            ) {
                throw new InvalidArgumentException("Entry {$entryIndex} of {$allowListFilePath} needs file, sink and a reason.");
            }
            $untrustedSinkAllowListEntry = new UntrustedSinkAllowListEntry();
            $untrustedSinkAllowListEntry->filePath = ltrim($rawEntry['file'], '/');
            $untrustedSinkAllowListEntry->sink = $rawEntry['sink'];
            $untrustedSinkAllowListEntry->reason = trim($rawEntry['reason']);
            if (isset($rawEntry['lines']) && is_array($rawEntry['lines'])) {
                $untrustedSinkAllowListEntry->fromLine = (int)($rawEntry['lines'][0] ?? 0);
                $untrustedSinkAllowListEntry->toLine = (int)($rawEntry['lines'][1] ?? $rawEntry['lines'][0] ?? 0);
            }
            $untrustedSinkAllowList->add($untrustedSinkAllowListEntry);
        }
        return $untrustedSinkAllowList;
    }

    /**
     * @param UntrustedSinkFinding $untrustedSinkFinding
     * @return bool
     */
    public function covers(UntrustedSinkFinding $untrustedSinkFinding): bool
    {
        foreach ($this->getElements() as $untrustedSinkAllowListEntry) {
            if ($untrustedSinkAllowListEntry->covers($untrustedSinkFinding)) {
                return true;
            }
        }
        return false;
    }
}
