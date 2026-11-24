<?php

declare(strict_types=1);

namespace DDD\Testing\Modules;

/**
 * One breach of the owned-namespace rule: a class or a config file shipped by two installed packages, or a class of a
 * package outside the namespaces it declares ({@see \DDD\Infrastructure\Modules\DDDModule::getOwnedNamespaces()}).
 * A plain object on purpose: the check runs in test suites without a booted kernel.
 */
class ModuleNamespaceViolation
{
    /** Two packages ship a class of the same name (Composer silently loads the first one) */
    public const string KIND_DUPLICATE_CLASS = 'DUPLICATE_CLASS';

    /** Two packages ship a config file of the same path below `config/app/` (one shadows the other) */
    public const string KIND_DUPLICATE_CONFIG_FILE = 'DUPLICATE_CONFIG_FILE';

    /** A package that declares owned namespaces ships a class outside them */
    public const string KIND_CLASS_OUTSIDE_OWNED_NAMESPACES = 'CLASS_OUTSIDE_OWNED_NAMESPACES';

    /**
     * @param string $kind KIND_*
     * @param string $subject The class name or the config file path (relative to `config/app/`)
     * @param string[] $packageNames The packages involved
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $subject,
        public readonly array $packageNames,
    ) {
    }

    /**
     * @return string One line for a failure message
     */
    public function describe(): string
    {
        return match ($this->kind) {
            self::KIND_DUPLICATE_CLASS => sprintf('class %s is shipped by %s', $this->subject, implode(' and ', $this->packageNames)),
            self::KIND_DUPLICATE_CONFIG_FILE => sprintf('config file config/app/%s is shipped by %s', $this->subject, implode(' and ', $this->packageNames)),
            default => sprintf('class %s of %s lies outside the namespaces the package declares as its own', $this->subject, implode(', ', $this->packageNames)),
        };
    }
}
