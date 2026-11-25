<?php

declare(strict_types=1);

namespace DDD\Tools\Renames;

use RuntimeException;

/**
 * One run of `ddd-renames` over an application: reads the state file and the installed packages' maps, finds the
 * pending renames version by version (a later version sees the result of an earlier one, also in a dry run), writes
 * the certain hits and the state on `--apply`.
 */
class RenameRun
{
    /** @var PackageRenames[] */
    public array $packageRenamesList = [];

    /** @var RenameHit[] */
    public array $hits = [];

    /** @var string[] relative paths of the files whose content changed (written on --apply) */
    public array $changedFilePaths = [];

    /** @var string[] */
    public array $warnings = [];

    /** @var string[] effective include patterns (state file + options) */
    public array $includePatterns = [];

    /** @var string[] effective exclude patterns (state file + options) */
    public array $excludePatterns = [];

    /** @var array<string, string> relative path => content after the certain hits */
    protected array $changedContentsByPath = [];

    /** @var array<string, mixed> the state document as read (unknown keys are kept on write) */
    protected array $stateDocument = [];

    /**
     * @param array<string, string> $fromVersionsByPackage package => version treated as applied
     * @param string[] $optionIncludePatterns
     * @param string[] $optionExcludePatterns
     */
    public function __construct(
        public string $rootDirectory,
        public string $vendorDirectory,
        public string $mode = RenamesCommandLine::MODE_DRY_RUN,
        public array $fromVersionsByPackage = [],
        public array $optionIncludePatterns = [],
        public array $optionExcludePatterns = [],
    ) {
    }

    public function execute(): void
    {
        if (!is_dir($this->rootDirectory)) {
            throw new RuntimeException("no folder {$this->rootDirectory}");
        }
        $this->readStateFile();
        $this->readInstalledPackages();
        if ($this->mode !== RenamesCommandLine::MODE_MARK_APPLIED && $this->hasPendingRenames()) {
            $this->findHits();
        }
        if ($this->mode === RenamesCommandLine::MODE_APPLY) {
            foreach ($this->changedFilePaths as $relativePath) {
                file_put_contents($this->rootDirectory . '/' . $relativePath, $this->changedContentsByPath[$relativePath]);
            }
        }
        if (in_array($this->mode, [RenamesCommandLine::MODE_APPLY, RenamesCommandLine::MODE_MARK_APPLIED], true)) {
            $this->writeStateFile();
        }
    }

    public function hasPendingRenames(): bool
    {
        foreach ($this->packageRenamesList as $packageRenames) {
            if ($packageRenames->pendingEntriesByVersion !== []) {
                return true;
            }
        }
        return false;
    }

    protected function readStateFile(): void
    {
        $stateFile = $this->rootDirectory . '/' . RenamesCommandLine::STATE_FILE_NAME;
        $this->stateDocument = is_file($stateFile) ? RenamesCommandLine::decodeJsonFile($stateFile) : [];
        $stateIncludes = array_values(array_filter((array)($this->stateDocument['include'] ?? []), 'is_string'));
        $stateExcludes = array_values(array_filter((array)($this->stateDocument['exclude'] ?? []), 'is_string'));
        $this->includePatterns = array_values(array_unique([...$stateIncludes, ...$this->optionIncludePatterns]));
        $this->excludePatterns = array_values(array_unique([...$stateExcludes, ...$this->optionExcludePatterns]));
    }

    protected function readInstalledPackages(): void
    {
        $installedFile = $this->vendorDirectory . '/composer/installed.json';
        if (!is_file($installedFile)) {
            throw new RuntimeException("no {$installedFile}: run composer install first, or pass --vendor-dir");
        }
        $installed = RenamesCommandLine::decodeJsonFile($installedFile);
        $installedPackages = isset($installed['packages']) && is_array($installed['packages']) ? $installed['packages'] : $installed;
        $recordedPackages = is_array($this->stateDocument['packages'] ?? null) ? $this->stateDocument['packages'] : [];
        foreach ($installedPackages as $installedPackage) {
            if (!is_array($installedPackage) || !is_string($installedPackage['name'] ?? null)) {
                continue;
            }
            $packageName = $installedPackage['name'];
            $installPath = is_string($installedPackage['install-path'] ?? null)
                ? $this->vendorDirectory . '/composer/' . $installedPackage['install-path']
                : $this->vendorDirectory . '/' . $packageName;
            $mapFile = rtrim($installPath, '/') . '/' . RenameMap::MAP_FILE_NAME;
            if (!is_file($mapFile)) {
                continue;
            }
            $renameMap = RenameMap::loadFromFile($mapFile, $packageName);
            foreach ($renameMap->problems as $problem) {
                $this->warnings[] = "{$packageName} renames.json: {$problem} (the entry is skipped)";
            }
            $recordedVersion = $recordedPackages[$packageName]['appliedThroughVersion'] ?? null;
            $packageRenames = new PackageRenames(
                $packageName,
                static::readRegularVersion($installedPackage),
                $renameMap,
                $this->fromVersionsByPackage[$packageName] ?? (is_string($recordedVersion) ? $recordedVersion : null),
            );
            $packageRenames->computePendingEntries();
            $this->packageRenamesList[] = $packageRenames;
        }
        usort($this->packageRenamesList, static fn(PackageRenames $first, PackageRenames $second): int => strcmp($first->packageName, $second->packageName));
        foreach (array_keys($this->fromVersionsByPackage) as $packageName) {
            if (!in_array($packageName, array_map(static fn(PackageRenames $packageRenames): string => $packageRenames->packageName, $this->packageRenamesList), true)) {
                $this->warnings[] = "--from-version {$packageName}: no installed package with a renames.json of that name";
            }
        }
    }

    protected function findHits(): void
    {
        $relativePaths = RenamesCommandLine::findApplicationFiles($this->rootDirectory, $this->includePatterns, $this->excludePatterns);
        foreach ($this->packageRenamesList as $packageRenames) {
            foreach ($packageRenames->pendingEntriesByVersion as $renameEntries) {
                foreach ($relativePaths as $relativePath) {
                    array_push($this->hits, ...RenameMatcher::findPathHits($relativePath, $renameEntries));
                    $content = $this->changedContentsByPath[$relativePath] ?? @file_get_contents($this->rootDirectory . '/' . $relativePath);
                    if (!is_string($content) || str_contains(substr($content, 0, 8000), "\0")) {
                        continue;
                    }
                    $fileHits = RenameMatcher::findHitsInContent($content, $relativePath, $renameEntries);
                    if ($fileHits === []) {
                        continue;
                    }
                    array_push($this->hits, ...$fileHits);
                    $changedContent = RenameMatcher::replaceCertainHits($content, $fileHits);
                    if ($changedContent !== $content) {
                        $this->changedContentsByPath[$relativePath] = $changedContent;
                    }
                }
            }
        }
        $this->changedFilePaths = array_keys($this->changedContentsByPath);
        sort($this->changedFilePaths, SORT_STRING);
    }

    protected function writeStateFile(): void
    {
        $stateDocument = $this->stateDocument;
        $stateDocument['format'] = RenamesCommandLine::STATE_FORMAT;
        $stateDocument['include'] = array_values(array_filter((array)($stateDocument['include'] ?? []), 'is_string'));
        $stateDocument['exclude'] = array_values(array_filter((array)($stateDocument['exclude'] ?? []), 'is_string'));
        $recordedPackages = is_array($stateDocument['packages'] ?? null) ? $stateDocument['packages'] : [];
        foreach ($this->packageRenamesList as $packageRenames) {
            $versionToRecord = $packageRenames->getVersionToRecord();
            if ($versionToRecord !== null) {
                $recordedPackages[$packageRenames->packageName] = ['appliedThroughVersion' => $versionToRecord];
            }
        }
        ksort($recordedPackages, SORT_STRING);
        $stateDocument['packages'] = (object)$recordedPackages;
        $orderedDocument = ['format' => $stateDocument['format'], 'include' => $stateDocument['include'], 'exclude' => $stateDocument['exclude'], 'packages' => $stateDocument['packages']];
        file_put_contents(
            $this->rootDirectory . '/' . RenamesCommandLine::STATE_FILE_NAME,
            RenamesCommandLine::encodeJson($orderedDocument + $stateDocument)
        );
    }

    /**
     * @param array<string, mixed> $installedPackage an entry of installed.json
     */
    protected static function readRegularVersion(array $installedPackage): ?string
    {
        $normalizedVersion = (string)($installedPackage['version_normalized'] ?? '');
        if (preg_match('/^(\d+\.\d+\.\d+)\.0$/', $normalizedVersion, $versionMatch)) {
            return $versionMatch[1];
        }
        $version = ltrim((string)($installedPackage['version'] ?? ''), 'vV');
        return preg_match(RenameMap::VERSION_PATTERN, $version) ? $version : null;
    }

    /**
     * @return RenameHit[]
     */
    public function getHitsOfType(string $type, ?bool $isCertain = null): array
    {
        return array_values(array_filter(
            $this->hits,
            static fn(RenameHit $hit): bool => $hit->type === $type && ($isCertain === null || $hit->isCertain === $isCertain)
        ));
    }

    public function renderReport(): string
    {
        $prefix = RenamesCommandLine::OUTPUT_PREFIX;
        $lines = [];
        $lines[] = $prefix . match ($this->mode) {
            RenamesCommandLine::MODE_APPLY => 'apply: certain hits written, versions recorded in ' . RenamesCommandLine::STATE_FILE_NAME,
            RenamesCommandLine::MODE_CHECK => 'check: nothing written',
            RenamesCommandLine::MODE_MARK_APPLIED => 'mark-applied: versions recorded in ' . RenamesCommandLine::STATE_FILE_NAME . ', no file searched',
            default => 'dry run: nothing written (--apply writes the certain hits)',
        };
        foreach ($this->warnings as $warning) {
            $lines[] = "{$prefix}warning: {$warning}";
        }
        if ($this->packageRenamesList === []) {
            $lines[] = "{$prefix}no installed package ships a renames.json";
        }
        foreach ($this->packageRenamesList as $packageRenames) {
            $from = $packageRenames->appliedThroughVersion ?? '(nothing recorded)';
            $installed = $packageRenames->installedVersion ?? '(branch)';
            $lines[] = "{$prefix}{$packageRenames->packageName} {$from} -> {$installed}: {$packageRenames->countPendingEntries()} renames pending";
            foreach ($packageRenames->pendingEntriesByVersion as $renameEntries) {
                foreach ($renameEntries as $renameEntry) {
                    $lines[] = "    {$renameEntry->version}  {$renameEntry->kind}  {$renameEntry->oldName} -> {$renameEntry->newName}"
                        . ($renameEntry->note !== null && $renameEntry->note !== '' ? "  ({$renameEntry->note})" : '');
                }
            }
        }
        $certainHits = $this->getHitsOfType(RenameHit::TYPE_TEXT, true);
        $ambiguousHits = $this->getHitsOfType(RenameHit::TYPE_TEXT, false);
        $pathHits = $this->getHitsOfType(RenameHit::TYPE_PATH);
        if ($certainHits !== []) {
            $lines[] = '';
            $lines[] = $this->mode === RenamesCommandLine::MODE_APPLY ? 'Written:' : 'Certain (written by --apply):';
            foreach ($certainHits as $hit) {
                $lines[] = "  {$hit->relativeFilePath}:{$hit->lineNumber}:{$hit->columnNumber}  {$hit->matchedText} -> {$hit->replacementText}  | {$hit->lineText}";
            }
        }
        if ($ambiguousHits !== []) {
            $lines[] = '';
            $lines[] = 'Ambiguous (never written, check by hand):';
            foreach ($ambiguousHits as $hit) {
                $lines[] = "  {$hit->relativeFilePath}:{$hit->lineNumber}:{$hit->columnNumber}  {$hit->matchedText} -> {$hit->replacementText}?  {$hit->ambiguityReason}  | {$hit->lineText}";
            }
        }
        if ($pathHits !== []) {
            $lines[] = '';
            $lines[] = 'Paths named after an old name (move by hand):';
            foreach ($pathHits as $hit) {
                $lines[] = "  {$hit->relativeFilePath}  ({$hit->renameEntry->describe()})";
            }
        }
        $lines[] = '';
        $lines[] = $prefix . count($certainHits) . ' certain hits in ' . count($this->changedFilePaths) . ' files, '
            . count($ambiguousHits) . ' ambiguous, ' . count($pathHits) . ' paths';
        if ($this->mode === RenamesCommandLine::MODE_CHECK && $this->hasPendingRenames()) {
            $lines[] = $prefix . 'renames pending: run vendor/bin/ddd-renames --apply and commit the result with ' . RenamesCommandLine::STATE_FILE_NAME;
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonObject(): array
    {
        $pendingRenames = [];
        foreach ($this->packageRenamesList as $packageRenames) {
            foreach ($packageRenames->pendingEntriesByVersion as $renameEntries) {
                foreach ($renameEntries as $renameEntry) {
                    $pendingRenames[] = $renameEntry->toJsonObject();
                }
            }
        }
        return [
            'mode' => $this->mode,
            'root' => $this->rootDirectory,
            'pending' => $this->hasPendingRenames(),
            'packages' => array_map(static fn(PackageRenames $packageRenames): array => $packageRenames->toJsonObject(), $this->packageRenamesList),
            'renames' => $pendingRenames,
            'hits' => array_map(static fn(RenameHit $hit): array => $hit->toJsonObject(), $this->hits),
            'changedFiles' => $this->changedFilePaths,
            'warnings' => $this->warnings,
            'summary' => [
                'pendingRenames' => count($pendingRenames),
                'certainHits' => count($this->getHitsOfType(RenameHit::TYPE_TEXT, true)),
                'ambiguousHits' => count($this->getHitsOfType(RenameHit::TYPE_TEXT, false)),
                'pathHits' => count($this->getHitsOfType(RenameHit::TYPE_PATH)),
                'changedFiles' => count($this->changedFilePaths),
            ],
        ];
    }
}
