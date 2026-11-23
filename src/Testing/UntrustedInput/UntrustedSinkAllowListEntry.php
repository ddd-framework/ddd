<?php

declare(strict_types=1);

namespace DDD\Testing\UntrustedInput;

/**
 * One entry of a package's `config/untrusted-sinks-allowlist.php`: a raw sink call that is fine, and why.
 *
 *     ['file' => 'src/Domain/Foo/Services/BarService.php', 'sink' => 'process', 'reason' => 'our own console command, argument array'],
 *     ['file' => 'src/…/Baz.php', 'sink' => 'unserialize', 'lines' => [120, 140], 'reason' => '…'],
 */
class UntrustedSinkAllowListEntry
{
    /** @var string Path relative to the package root */
    public string $filePath;

    /** @var string Sink name (UntrustedSinkScanner::SINK_*) */
    public string $sink;

    /** @var int|null First line covered, null = the whole file */
    public ?int $fromLine = null;

    /** @var int|null Last line covered */
    public ?int $toLine = null;

    /** @var string Why the call is safe (required) */
    public string $reason;

    /**
     * @param UntrustedSinkFinding $untrustedSinkFinding
     * @return bool
     */
    public function covers(UntrustedSinkFinding $untrustedSinkFinding): bool
    {
        if ($untrustedSinkFinding->filePath !== $this->filePath || $untrustedSinkFinding->sink !== $this->sink) {
            return false;
        }
        if ($this->fromLine === null) {
            return true;
        }
        return $untrustedSinkFinding->line >= $this->fromLine && $untrustedSinkFinding->line <= ($this->toLine ?? $this->fromLine);
    }
}
