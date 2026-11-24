<?php

declare(strict_types=1);

namespace DDD\Testing\Modules;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The breaches of one check run. A plain collection on purpose: the check runs in test suites without a booted kernel.
 *
 * @implements IteratorAggregate<int, ModuleNamespaceViolation>
 */
class ModuleNamespaceViolations implements IteratorAggregate, Countable
{
    /** @var ModuleNamespaceViolation[] */
    protected array $moduleNamespaceViolations = [];

    public function add(ModuleNamespaceViolation $moduleNamespaceViolation): void
    {
        $this->moduleNamespaceViolations[] = $moduleNamespaceViolation;
    }

    /**
     * @param string $kind ModuleNamespaceViolation::KIND_*
     * @return static The breaches of one kind
     */
    public function filterByKind(string $kind): static
    {
        $filteredViolations = new static();
        foreach ($this->moduleNamespaceViolations as $moduleNamespaceViolation) {
            if ($moduleNamespaceViolation->kind === $kind) {
                $filteredViolations->add($moduleNamespaceViolation);
            }
        }
        return $filteredViolations;
    }

    /**
     * @return string[] One line per breach
     */
    public function describe(): array
    {
        $lines = [];
        foreach ($this->moduleNamespaceViolations as $moduleNamespaceViolation) {
            $lines[] = $moduleNamespaceViolation->describe();
        }
        return $lines;
    }

    /**
     * @return ModuleNamespaceViolation[]
     */
    public function getElements(): array
    {
        return $this->moduleNamespaceViolations;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->moduleNamespaceViolations);
    }

    public function count(): int
    {
        return count($this->moduleNamespaceViolations);
    }
}
