<?php

declare(strict_types=1);

namespace DDD\Testing\UntrustedInput;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The findings of a scan. A plain collection on purpose: the scan runs in test suites without a booted kernel.
 *
 * @implements IteratorAggregate<int, UntrustedSinkFinding>
 */
class UntrustedSinkFindings implements IteratorAggregate, Countable
{
    /** @var UntrustedSinkFinding[] */
    protected array $untrustedSinkFindings = [];

    /**
     * @param UntrustedSinkFinding $untrustedSinkFinding
     * @return void
     */
    public function add(UntrustedSinkFinding $untrustedSinkFinding): void
    {
        $this->untrustedSinkFindings[] = $untrustedSinkFinding;
    }

    /**
     * @return UntrustedSinkFinding[]
     */
    public function getElements(): array
    {
        return $this->untrustedSinkFindings;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->untrustedSinkFindings);
    }

    public function count(): int
    {
        return count($this->untrustedSinkFindings);
    }
}
