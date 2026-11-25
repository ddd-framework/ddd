<?php

declare(strict_types=1);

namespace DDD\Tools\Renames;

use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * `vendor/bin/ddd-renames`: applies the renames of the installed packages to an application, and validates a
 * package's own map.
 *
 * Which renames are pending: for every installed package (`vendor/composer/installed.json`) that ships `renames.json`,
 * the entries newer than the version recorded in the application's state file `.ddd-renames.json` (all entries when
 * nothing is recorded; `--from-version <package>=<version>` overrides the record) and not newer than the installed
 * version. `--apply` writes the certain hits and records the installed versions; the state file is committed with
 * the application, so `--check` in CI fails while a package update's renames are not applied.
 *
 * What is scanned: every text file below the root except `vendor/`, `node_modules/`, `.git/`, `var/` and the other
 * folders of `DEFAULT_EXCLUDED_DIRECTORY_NAMES` (at any depth), symbolic links, lock files and the state file;
 * narrowed by `include` and widened in its exclusions by `exclude` (state file or options).
 *
 * Plain PHP on purpose: it runs right after `composer update` without booting a kernel.
 */
class RenamesCommandLine
{
    public const string STATE_FILE_NAME = '.ddd-renames.json';

    public const int STATE_FORMAT = 1;

    public const string OUTPUT_PREFIX = '[ddd-renames] ';

    public const int EXIT_SUCCESS = 0;

    /** `--check`: renames pending; `validate`: the map has problems */
    public const int EXIT_FAILURE = 1;

    /** Wrong usage, unreadable files */
    public const int EXIT_ERROR = 2;

    public const string MODE_DRY_RUN = 'dry-run';

    public const string MODE_APPLY = 'apply';

    public const string MODE_CHECK = 'check';

    public const string MODE_MARK_APPLIED = 'mark-applied';

    public const string FORMAT_TEXT = 'text';

    public const string FORMAT_JSON = 'json';

    /** Folder names never entered, at any depth */
    public const array DEFAULT_EXCLUDED_DIRECTORY_NAMES = [
        'vendor', 'node_modules', '.git', 'var', '.idea', '.vscode', '.phpunit.cache', '.cache', 'coverage', '.svn', '.hg',
    ];

    /** File names never read (generated, or the tool's own state) */
    public const array DEFAULT_EXCLUDED_FILE_NAMES = [
        self::STATE_FILE_NAME, 'composer.lock', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', '.ddd-skills.json',
    ];

    /** Extensions of the text files read; dotfiles `.env*` are read as well */
    public const array TEXT_FILE_EXTENSIONS = [
        'php', 'phtml', 'twig', 'yaml', 'yml', 'json', 'jsonc', 'json5', 'md', 'mdx', 'txt', 'xml', 'xsd', 'html', 'htm',
        'neon', 'dist', 'ini', 'toml', 'env', 'sh', 'bash', 'zsh', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'vue', 'svelte',
        'css', 'scss', 'csv', 'tsv', 'sql', 'py', 'rst', 'adoc', 'conf', 'cfg', 'properties', 'tpl', 'mustache', 'hbs',
    ];

    /** Files larger than this are skipped (generated dumps, fixtures of binary data) */
    public const int MAXIMUM_FILE_SIZE = 4194304;

    public const string USAGE = <<<'TEXT'
Usage:
  ddd-renames [--apply | --check | --mark-applied] [options]
      Finds the pending renames of the installed packages in the application (default: dry run, lists file:line).
      --apply             writes the certain hits and records the installed versions in .ddd-renames.json
      --check             CI: exit 1 while renames are pending (not yet applied and recorded)
      --mark-applied      records the installed versions without writing files (renames already done by hand)
      --root <dir>        application root (default: current directory)
      --vendor-dir <dir>  Composer vendor folder (default: <root>/vendor)
      --from-version <package>=<version>   treat renames up to <version> as applied (repeatable; overrides the record)
      --include <path>    scan only these paths (folder prefix or glob, repeatable; adds to "include" of the state file)
      --exclude <path>    skip these paths (folder prefix or glob, repeatable; adds to "exclude" of the state file)
      --format=json       machine-readable report on stdout
  ddd-renames validate [--package-dir <dir>] [--format=json]
      Checks a package's own renames.json (schema, versions, kinds, old != new, no chain or swap within a version).
      Exit 1 on problems.

TEXT;

    /**
     * @param string[] $arguments
     */
    public static function runFromCommandLine(array $arguments, string $currentDirectory): int
    {
        try {
            $outputFormat = static::readOptionValues($arguments, '--format')[0] ?? self::FORMAT_TEXT;
            if (!in_array($outputFormat, [self::FORMAT_TEXT, self::FORMAT_JSON], true)) {
                throw new RuntimeException("unknown --format {$outputFormat} (text, json)");
            }
            if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
                fwrite(STDOUT, self::USAGE);
                return self::EXIT_SUCCESS;
            }
            if (($arguments[0] ?? '') === 'validate') {
                $packageDirectory = static::readOptionValues($arguments, '--package-dir')[0] ?? $currentDirectory;
                return static::runValidate(static::toAbsolutePath($packageDirectory, $currentDirectory), $outputFormat);
            }
            $modes = array_values(array_filter(
                [self::MODE_APPLY => '--apply', self::MODE_CHECK => '--check', self::MODE_MARK_APPLIED => '--mark-applied'],
                static fn(string $flag): bool => in_array($flag, $arguments, true)
            ));
            if (count($modes) > 1) {
                throw new RuntimeException('--apply, --check and --mark-applied exclude each other');
            }
            $mode = match ($modes[0] ?? null) {
                '--apply' => self::MODE_APPLY,
                '--check' => self::MODE_CHECK,
                '--mark-applied' => self::MODE_MARK_APPLIED,
                default => self::MODE_DRY_RUN,
            };
            foreach ($arguments as $argument) {
                if (!str_starts_with($argument, '-') && !static::isOptionValue($arguments, $argument)) {
                    throw new RuntimeException("unknown argument {$argument}");
                }
            }
            $rootDirectory = static::toAbsolutePath(static::readOptionValues($arguments, '--root')[0] ?? $currentDirectory, $currentDirectory);
            $vendorDirectory = static::readOptionValues($arguments, '--vendor-dir')[0] ?? null;
            $fromVersionsByPackage = [];
            foreach (static::readOptionValues($arguments, '--from-version') as $fromVersionOption) {
                if (!preg_match('/^([^=\s]+)=v?(\d+\.\d+\.\d+)$/', $fromVersionOption, $fromVersionMatch)) {
                    throw new RuntimeException("--from-version expects <package>=<MAJOR.MINOR.PATCH>, got {$fromVersionOption}");
                }
                $fromVersionsByPackage[$fromVersionMatch[1]] = $fromVersionMatch[2];
            }
            $renameRun = new RenameRun(
                $rootDirectory,
                $vendorDirectory === null ? $rootDirectory . '/vendor' : static::toAbsolutePath($vendorDirectory, $currentDirectory),
                $mode,
                $fromVersionsByPackage,
                static::readOptionValues($arguments, '--include'),
                static::readOptionValues($arguments, '--exclude'),
            );
            $renameRun->execute();
            if ($outputFormat === self::FORMAT_JSON) {
                fwrite(STDOUT, static::encodeJson($renameRun->toJsonObject()));
            } else {
                fwrite(STDOUT, $renameRun->renderReport());
            }
            return $mode === self::MODE_CHECK && $renameRun->hasPendingRenames() ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
        } catch (Throwable $throwable) {
            fwrite(STDERR, self::OUTPUT_PREFIX . 'error: ' . $throwable->getMessage() . "\n");
            return self::EXIT_ERROR;
        }
    }

    public static function runValidate(string $packageDirectory, string $outputFormat): int
    {
        $mapFile = $packageDirectory . '/' . RenameMap::MAP_FILE_NAME;
        if (!is_file($mapFile)) {
            throw new RuntimeException("no {$mapFile}");
        }
        $composer = is_file($packageDirectory . '/composer.json') ? static::decodeJsonFile($packageDirectory . '/composer.json') : [];
        $packageName = is_string($composer['name'] ?? null) ? $composer['name'] : basename($packageDirectory);
        $packageVersion = is_string($composer['version'] ?? null) ? $composer['version'] : null;
        $renameMap = RenameMap::loadFromFile($mapFile, $packageName, $packageVersion);
        if ($outputFormat === self::FORMAT_JSON) {
            fwrite(STDOUT, static::encodeJson([
                'package' => $packageName,
                'valid' => $renameMap->problems === [],
                'renames' => count($renameMap->renameEntries),
                'problems' => $renameMap->problems,
                'warnings' => $renameMap->warnings,
            ]));
        } else {
            foreach ($renameMap->problems as $problem) {
                fwrite(STDOUT, self::OUTPUT_PREFIX . "problem: {$problem}\n");
            }
            foreach ($renameMap->warnings as $warning) {
                fwrite(STDOUT, self::OUTPUT_PREFIX . "warning: {$warning}\n");
            }
            fwrite(STDOUT, self::OUTPUT_PREFIX . "{$packageName}: " . count($renameMap->renameEntries) . ' renames, '
                . count($renameMap->problems) . ' problems, ' . count($renameMap->warnings) . " warnings\n");
        }
        return $renameMap->problems === [] ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
    }

    /**
     * The text files of the application below the root, relative paths sorted.
     *
     * @param string[] $includePatterns
     * @param string[] $excludePatterns
     * @return string[]
     */
    public static function findApplicationFiles(string $rootDirectory, array $includePatterns, array $excludePatterns): array
    {
        $directoryIterator = new RecursiveDirectoryIterator($rootDirectory, FilesystemIterator::SKIP_DOTS);
        $filterIterator = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (SplFileInfo $fileInfo) use ($rootDirectory, $excludePatterns): bool {
                if ($fileInfo->isLink()) {
                    return false;
                }
                $relativePath = substr($fileInfo->getPathname(), strlen($rootDirectory) + 1);
                if ($fileInfo->isDir()) {
                    return !in_array($fileInfo->getFilename(), self::DEFAULT_EXCLUDED_DIRECTORY_NAMES, true)
                        && !static::matchesAnyPathPattern($relativePath, $excludePatterns);
                }
                return true;
            }
        );
        $relativePaths = [];
        foreach (new RecursiveIteratorIterator($filterIterator) as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            $relativePath = substr($fileInfo->getPathname(), strlen($rootDirectory) + 1);
            $fileName = $fileInfo->getFilename();
            if (in_array($fileName, self::DEFAULT_EXCLUDED_FILE_NAMES, true) || $fileInfo->getSize() > self::MAXIMUM_FILE_SIZE) {
                continue;
            }
            $isDotEnvFile = $fileName === '.env' || str_starts_with($fileName, '.env.');
            if (!$isDotEnvFile && !in_array(strtolower($fileInfo->getExtension()), self::TEXT_FILE_EXTENSIONS, true)) {
                continue;
            }
            if ($includePatterns !== [] && !static::matchesAnyPathPattern($relativePath, $includePatterns)) {
                continue;
            }
            if (static::matchesAnyPathPattern($relativePath, $excludePatterns)) {
                continue;
            }
            $relativePaths[] = $relativePath;
        }
        sort($relativePaths, SORT_STRING);
        return $relativePaths;
    }

    /**
     * A pattern is a folder or file prefix (`src`, `src/Legacy/`, `docs/old.md`) or a glob (`tests/*.json`, `**`
     * crosses folders like `*`).
     *
     * @param string[] $pathPatterns
     */
    public static function matchesAnyPathPattern(string $relativePath, array $pathPatterns): bool
    {
        foreach ($pathPatterns as $pathPattern) {
            $pathPattern = trim($pathPattern);
            if ($pathPattern === '') {
                continue;
            }
            if (strpbrk($pathPattern, '*?[') !== false) {
                if (fnmatch(str_replace('**', '*', $pathPattern), $relativePath)) {
                    return true;
                }
                continue;
            }
            $prefix = rtrim($pathPattern, '/');
            if ($relativePath === $prefix || str_starts_with($relativePath, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeJsonFile(string $file): array
    {
        try {
            $decoded = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException("{$file} is no valid JSON: {$jsonException->getMessage()}", 0, $jsonException);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function encodeJson(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Every value of an option given as `--name value` or `--name=value`.
     *
     * @param string[] $arguments
     * @return string[]
     */
    protected static function readOptionValues(array $arguments, string $name): array
    {
        $values = [];
        foreach ($arguments as $index => $argument) {
            if ($argument === $name && isset($arguments[$index + 1])) {
                $values[] = $arguments[$index + 1];
            } elseif (str_starts_with($argument, $name . '=')) {
                $values[] = substr($argument, strlen($name) + 1);
            }
        }
        return $values;
    }

    /**
     * @param string[] $arguments
     */
    protected static function isOptionValue(array $arguments, string $argument): bool
    {
        foreach ($arguments as $index => $candidate) {
            if ($candidate === $argument && $index > 0 && in_array($arguments[$index - 1], ['--root', '--vendor-dir', '--from-version', '--include', '--exclude', '--format', '--package-dir'], true)) {
                return true;
            }
        }
        return false;
    }

    public static function toAbsolutePath(string $path, string $currentDirectory): string
    {
        return str_starts_with($path, '/') ? rtrim($path, '/') : rtrim($currentDirectory . '/' . $path, '/');
    }
}
