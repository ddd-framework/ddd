<?php

declare(strict_types=1);

namespace DDD\Tools\Renames;

/**
 * One rename a package release made: what kind of name, the old and the new spelling, the version it happened in.
 * Read from a package's `renames.json` (see `renames.schema.json` beside this class).
 */
class RenameEntry
{
    /** An agent or documentation skill name (`Agent.Web`, `ddd-foo-specialist`); whole name only */
    public const string KIND_SKILL_NAME = 'skillName';

    /** A class constant, written `Fully\Qualified\Class::CONSTANT_NAME` in old and new (same class) */
    public const string KIND_CONSTANT = 'constant';

    /** A fully qualified class, interface, trait or enum name */
    public const string KIND_CLASS = 'class';

    /** A namespace; every class and sub-namespace below it moves along */
    public const string KIND_NAMESPACE = 'namespace';

    /** A dotted configuration key (`AI.webSearch`); its sub-keys (`AI.webSearch.enabled`) move along */
    public const string KIND_CONFIG_KEY = 'configKey';

    /** A console command name (`app:foo:bar`) */
    public const string KIND_CONSOLE_COMMAND = 'consoleCommand';

    /** A package id (`provider.topic.feature`); whole id only */
    public const string KIND_PACKAGE_ID = 'packageId';

    /** An environment variable name */
    public const string KIND_ENV_VAR = 'envVar';

    public const array KINDS = [
        self::KIND_SKILL_NAME,
        self::KIND_CONSTANT,
        self::KIND_CLASS,
        self::KIND_NAMESPACE,
        self::KIND_CONFIG_KEY,
        self::KIND_CONSOLE_COMMAND,
        self::KIND_PACKAGE_ID,
        self::KIND_ENV_VAR,
    ];

    /** The spelling each kind accepts for old and new */
    public const array NAME_PATTERNS_BY_KIND = [
        self::KIND_SKILL_NAME => '/^[A-Za-z0-9_](?:[A-Za-z0-9_\-.]*[A-Za-z0-9_])?$/',
        self::KIND_CONSTANT => '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*::[A-Za-z_][A-Za-z0-9_]*$/',
        self::KIND_CLASS => '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/',
        self::KIND_NAMESPACE => '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/',
        self::KIND_CONFIG_KEY => '/^[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)*$/',
        self::KIND_CONSOLE_COMMAND => '/^[A-Za-z0-9](?:[A-Za-z0-9_\-:]*[A-Za-z0-9_\-])?$/',
        self::KIND_PACKAGE_ID => '/^[A-Za-z0-9_](?:[A-Za-z0-9_\-.]*[A-Za-z0-9_])?$/',
        self::KIND_ENV_VAR => '/^[A-Za-z_][A-Za-z0-9_]*$/',
    ];

    public function __construct(
        public string $packageName,
        public string $version,
        public string $kind,
        public string $oldName,
        public string $newName,
        public ?string $note = null,
    ) {
    }

    /** `Class` of a constant entry (`Fully\Qualified\Class::NAME`), null for other kinds */
    public function getConstantClassName(): ?string
    {
        return $this->kind === self::KIND_CONSTANT ? explode('::', $this->oldName, 2)[0] : null;
    }

    /** The constant name of the old spelling of a constant entry */
    public function getOldConstantName(): ?string
    {
        return $this->kind === self::KIND_CONSTANT ? explode('::', $this->oldName, 2)[1] : null;
    }

    /** The constant name of the new spelling of a constant entry */
    public function getNewConstantName(): ?string
    {
        return $this->kind === self::KIND_CONSTANT ? explode('::', $this->newName, 2)[1] : null;
    }

    /** A one-line description for reports: `class Old\Name -> New\Name (acme/pkg 2.0.0)` */
    public function describe(): string
    {
        return "{$this->kind} {$this->oldName} -> {$this->newName} ({$this->packageName} {$this->version})";
    }

    /**
     * @return array<string, string|null>
     */
    public function toJsonObject(): array
    {
        return [
            'package' => $this->packageName,
            'version' => $this->version,
            'kind' => $this->kind,
            'old' => $this->oldName,
            'new' => $this->newName,
            'note' => $this->note,
        ];
    }
}
