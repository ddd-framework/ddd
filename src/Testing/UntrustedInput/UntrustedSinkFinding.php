<?php

declare(strict_types=1);

namespace DDD\Testing\UntrustedInput;

/**
 * Plain object on purpose (no framework base class): the scan runs in test suites without a booted kernel.
 *
 * One raw call to a sink of foreign input, found by {@see UntrustedSinkScanner}.
 */
class UntrustedSinkFinding
{
    /** @var string Path relative to the package root, `/`-separated */
    public string $filePath;

    /** @var int Line of the call */
    public int $line;

    /** @var string Name of the sink (UntrustedSinkScanner::SINK_*) */
    public string $sink;

    /** @var string What the call looks like (for the failure message) */
    public string $callDescription;

    /**
     * @return string `src/Foo.php:12 exec (exec(...))`
     */
    public function describe(): string
    {
        return sprintf('%s:%d %s (%s)', $this->filePath, $this->line, $this->sink, $this->callDescription);
    }
}
