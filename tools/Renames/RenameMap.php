<?php

declare(strict_types=1);

namespace DDD\Tools\Renames;

use JsonException;

/**
 * The rename map a package ships in `renames.json` at its root: every rename of a skill name, constant, class,
 * namespace, configuration key, console command, package id or environment variable, with the version it happened in.
 *
 * Format (JSON schema: `renames.schema.json` beside this class):
 *
 *     {
 *       "format": 1,
 *       "package": "acme/orders",
 *       "renames": [
 *         { "version": "2.0.0", "kind": "class", "old": "Acme\\Orders\\Basket", "new": "Acme\\Orders\\Cart",
 *           "note": "optional, shown in reports" }
 *       ]
 *     }
 *
 * Rules checked here (`ddd-renames validate`): the shape above and nothing else; versions are regular
 * `MAJOR.MINOR.PATCH`; old and new are spelled as their kind requires and differ; a constant keeps its class (a class
 * move is its own `class` entry); within one version no name is renamed twice and no entry renames the result of
 * another (a chain or a swap: write `A -> C` directly). Across versions a chain is the history and is applied in order.
 */
class RenameMap
{
    public const string MAP_FILE_NAME = 'renames.json';

    public const int MAP_FORMAT = 1;

    public const string VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

    public const array ALLOWED_DOCUMENT_KEYS = ['$schema', 'format', 'package', 'renames'];

    public const array ALLOWED_ENTRY_KEYS = ['version', 'kind', 'old', 'new', 'note'];

    /**
     * @param RenameEntry[] $renameEntries sorted by version, then by their order in the file
     * @param string[] $problems errors: the map must not be shipped like this; entries with a problem are left out
     * @param string[] $warnings findings that do not block
     */
    public function __construct(
        public string $packageName,
        public array $renameEntries = [],
        public array $problems = [],
        public array $warnings = [],
    ) {
    }

    /**
     * Reads and validates a map file. Problems never throw: they are collected, and only valid entries are kept.
     *
     * @param string|null $packageVersion the package's own version; an entry newer than it is a warning
     */
    public static function loadFromFile(string $mapFile, string $packageName, ?string $packageVersion = null): static
    {
        $renameMap = new static($packageName);
        try {
            $document = json_decode((string)file_get_contents($mapFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            $renameMap->problems[] = "{$mapFile} is no valid JSON: {$jsonException->getMessage()}";
            return $renameMap;
        }
        $renameMap->readDocument($document, $packageVersion);
        return $renameMap;
    }

    /**
     * @param mixed $document the decoded JSON document
     */
    public function readDocument(mixed $document, ?string $packageVersion = null): void
    {
        if (!is_array($document) || array_is_list($document) && $document !== []) {
            $this->problems[] = 'the map must be a JSON object';
            return;
        }
        foreach (array_keys($document) as $documentKey) {
            if (!in_array($documentKey, self::ALLOWED_DOCUMENT_KEYS, true)) {
                $this->problems[] = "unknown key \"{$documentKey}\" (allowed: " . implode(', ', self::ALLOWED_DOCUMENT_KEYS) . ')';
            }
        }
        if (($document['format'] ?? null) !== self::MAP_FORMAT) {
            $this->problems[] = '"format" must be ' . self::MAP_FORMAT;
        }
        if (isset($document['package']) && $document['package'] !== $this->packageName) {
            $this->problems[] = '"package" is ' . json_encode($document['package'], JSON_UNESCAPED_SLASHES) . ", the package is {$this->packageName}";
        }
        $rawEntries = $document['renames'] ?? null;
        if (!is_array($rawEntries) || !array_is_list($rawEntries)) {
            $this->problems[] = '"renames" must be a list';
            return;
        }
        $validEntries = [];
        foreach ($rawEntries as $index => $rawEntry) {
            $renameEntry = $this->createEntry($rawEntry, $index, $packageVersion);
            if ($renameEntry !== null) {
                $validEntries[] = $renameEntry;
            }
        }
        $this->renameEntries = static::sortEntriesByVersion($validEntries);
        $this->findProblemsBetweenEntries();
    }

    /**
     * The entries of a version range: newer than `$afterVersion` (all when null), not newer than `$throughVersion`
     * (no upper bound when null), grouped by version in ascending order.
     *
     * @return array<string, RenameEntry[]> version => entries
     */
    public function getEntriesByVersion(?string $afterVersion, ?string $throughVersion): array
    {
        $entriesByVersion = [];
        foreach ($this->renameEntries as $renameEntry) {
            if ($afterVersion !== null && version_compare($renameEntry->version, $afterVersion, '<=')) {
                continue;
            }
            if ($throughVersion !== null && version_compare($renameEntry->version, $throughVersion, '>')) {
                continue;
            }
            $entriesByVersion[$renameEntry->version][] = $renameEntry;
        }
        return $entriesByVersion;
    }

    public function getHighestVersion(): ?string
    {
        return $this->renameEntries === [] ? null : $this->renameEntries[count($this->renameEntries) - 1]->version;
    }

    protected function createEntry(mixed $rawEntry, int $index, ?string $packageVersion): ?RenameEntry
    {
        $label = "renames[{$index}]";
        if (!is_array($rawEntry) || array_is_list($rawEntry)) {
            $this->problems[] = "{$label} must be an object";
            return null;
        }
        $problemCount = count($this->problems);
        foreach (array_keys($rawEntry) as $entryKey) {
            if (!in_array($entryKey, self::ALLOWED_ENTRY_KEYS, true)) {
                $this->problems[] = "{$label}: unknown key \"{$entryKey}\" (allowed: " . implode(', ', self::ALLOWED_ENTRY_KEYS) . ')';
            }
        }
        foreach (['version', 'kind', 'old', 'new'] as $requiredKey) {
            if (!is_string($rawEntry[$requiredKey] ?? null) || $rawEntry[$requiredKey] === '') {
                $this->problems[] = "{$label}: \"{$requiredKey}\" must be a non-empty string";
            }
        }
        if (array_key_exists('note', $rawEntry) && !is_string($rawEntry['note'])) {
            $this->problems[] = "{$label}: \"note\" must be a string";
        }
        if (count($this->problems) > $problemCount) {
            return null;
        }
        $version = ltrim($rawEntry['version'], 'v');
        $kind = $rawEntry['kind'];
        $oldName = $rawEntry['old'];
        $newName = $rawEntry['new'];
        $label .= " ({$kind} {$oldName})";
        if (!preg_match(self::VERSION_PATTERN, $version)) {
            $this->problems[] = "{$label}: version \"{$rawEntry['version']}\" is no regular MAJOR.MINOR.PATCH version";
        }
        if (!in_array($kind, RenameEntry::KINDS, true)) {
            $this->problems[] = "{$label}: unknown kind (allowed: " . implode(', ', RenameEntry::KINDS) . ')';
            return null;
        }
        foreach (['old' => $oldName, 'new' => $newName] as $side => $name) {
            if (!preg_match(RenameEntry::NAME_PATTERNS_BY_KIND[$kind], $name)) {
                $this->problems[] = "{$label}: \"{$side}\" " . json_encode($name, JSON_UNESCAPED_SLASHES) . " is no valid {$kind}"
                    . ($kind === RenameEntry::KIND_CONSTANT ? ' (write Fully\\Qualified\\Class::CONSTANT_NAME)' : '');
            }
        }
        if ($oldName === $newName) {
            $this->problems[] = "{$label}: old and new are the same";
        }
        if (
            $kind === RenameEntry::KIND_CONSTANT && str_contains($oldName, '::') && str_contains($newName, '::')
            && explode('::', $oldName, 2)[0] !== explode('::', $newName, 2)[0]
        ) {
            $this->problems[] = "{$label}: a constant entry keeps its class; a moved class is its own \"class\" entry";
        }
        if (count($this->problems) > $problemCount) {
            return null;
        }
        if ($packageVersion !== null && preg_match(self::VERSION_PATTERN, ltrim($packageVersion, 'v')) && version_compare($version, ltrim($packageVersion, 'v'), '>')) {
            $this->warnings[] = "{$label}: version {$version} is newer than the package version {$packageVersion}";
        }
        return new RenameEntry($this->packageName, $version, $kind, $oldName, $newName, $rawEntry['note'] ?? null);
    }

    /** Duplicates, chains and swaps within a version; reverted renames across versions */
    protected function findProblemsBetweenEntries(): void
    {
        $entriesByVersion = $this->getEntriesByVersion(null, null);
        foreach ($entriesByVersion as $version => $renameEntries) {
            $seenOldNames = [];
            foreach ($renameEntries as $renameEntry) {
                $key = $renameEntry->kind . "\0" . $renameEntry->oldName;
                if (isset($seenOldNames[$key])) {
                    $this->problems[] = "version {$version}: {$renameEntry->kind} {$renameEntry->oldName} is renamed twice";
                }
                $seenOldNames[$key] = true;
            }
            foreach ($renameEntries as $renameEntry) {
                foreach ($renameEntries as $otherEntry) {
                    if ($otherEntry !== $renameEntry && static::isNameRenamedBy($renameEntry->newName, $renameEntry->kind, $otherEntry)) {
                        $this->problems[] = "version {$version}: chain — {$renameEntry->describe()} produces a name that {$otherEntry->describe()} renames again; write the final name directly";
                    }
                }
            }
        }
        $versions = array_keys($entriesByVersion);
        foreach ($versions as $versionIndex => $version) {
            foreach ($entriesByVersion[$version] as $renameEntry) {
                foreach (array_slice($versions, $versionIndex + 1) as $laterVersion) {
                    foreach ($entriesByVersion[$laterVersion] as $laterEntry) {
                        if ($laterEntry->kind === $renameEntry->kind && $laterEntry->oldName === $renameEntry->newName && $laterEntry->newName === $renameEntry->oldName) {
                            $this->warnings[] = "{$laterEntry->describe()} reverts {$renameEntry->describe()}";
                        }
                    }
                }
            }
        }
    }

    /** Whether a name (of a kind) would be renamed again by an entry: same name, or below a renamed namespace / config key */
    protected static function isNameRenamedBy(string $name, string $kind, RenameEntry $renameEntry): bool
    {
        $phpNameKinds = [RenameEntry::KIND_CLASS, RenameEntry::KIND_NAMESPACE];
        if (in_array($kind, $phpNameKinds, true) && in_array($renameEntry->kind, $phpNameKinds, true)) {
            return $name === $renameEntry->oldName
                || $renameEntry->kind === RenameEntry::KIND_NAMESPACE && str_starts_with($name, $renameEntry->oldName . '\\');
        }
        if ($kind !== $renameEntry->kind) {
            return false;
        }
        if ($kind === RenameEntry::KIND_CONFIG_KEY) {
            return $name === $renameEntry->oldName || str_starts_with($name, $renameEntry->oldName . '.');
        }
        return $name === $renameEntry->oldName;
    }

    /**
     * @param RenameEntry[] $renameEntries
     * @return RenameEntry[]
     */
    protected static function sortEntriesByVersion(array $renameEntries): array
    {
        $positions = [];
        foreach ($renameEntries as $position => $renameEntry) {
            $positions[spl_object_id($renameEntry)] = $position;
        }
        usort(
            $renameEntries,
            static fn(RenameEntry $first, RenameEntry $second): int => version_compare($first->version, $second->version)
                ?: $positions[spl_object_id($first)] <=> $positions[spl_object_id($second)]
        );
        return $renameEntries;
    }
}
