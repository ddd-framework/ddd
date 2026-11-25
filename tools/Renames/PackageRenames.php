<?php

declare(strict_types=1);

namespace DDD\Tools\Renames;

/**
 * An installed package that ships a rename map, with the version range of its renames still pending in the
 * application.
 */
class PackageRenames
{
    /**
     * @param string|null $installedVersion regular `MAJOR.MINOR.PATCH`, null for a branch (`dev-main`: every rename counts)
     * @param string|null $appliedThroughVersion renames up to this version count as applied (state record or --from-version)
     * @param array<string, RenameEntry[]> $pendingEntriesByVersion
     */
    public function __construct(
        public string $packageName,
        public ?string $installedVersion,
        public RenameMap $renameMap,
        public ?string $appliedThroughVersion = null,
        public array $pendingEntriesByVersion = [],
    ) {
    }

    public function computePendingEntries(): void
    {
        $this->pendingEntriesByVersion = $this->renameMap->getEntriesByVersion($this->appliedThroughVersion, $this->installedVersion);
    }

    public function countPendingEntries(): int
    {
        return array_sum(array_map('count', $this->pendingEntriesByVersion));
    }

    /** The version recorded after an apply: the installed one, for a branch the newest rename's; never lower than before */
    public function getVersionToRecord(): ?string
    {
        $versionToRecord = $this->installedVersion ?? $this->renameMap->getHighestVersion() ?? $this->appliedThroughVersion;
        if ($this->appliedThroughVersion !== null && ($versionToRecord === null || version_compare($this->appliedThroughVersion, $versionToRecord, '>'))) {
            return $this->appliedThroughVersion;
        }
        return $versionToRecord;
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonObject(): array
    {
        return [
            'name' => $this->packageName,
            'installedVersion' => $this->installedVersion,
            'appliedThroughVersion' => $this->appliedThroughVersion,
            'pendingVersions' => array_map('strval', array_keys($this->pendingEntriesByVersion)),
            'pendingRenames' => $this->countPendingEntries(),
            'mapProblems' => $this->renameMap->problems,
        ];
    }
}
