<?php

declare(strict_types=1);

namespace DDD\Tools\ServerMessages;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * The server messages a DDD package's endpoints answer with, listed for the text export of an application.
 *
 * A web frontend translates a server message by its English text. An application's text export finds the messages in
 * its own PHP; a message that lives in an installed package is invisible to that scan, and an export that prunes keys
 * it did not find would delete a text an endpoint still sends. Each PHP package therefore ships
 * `i18n/server-messages.json`: the keys are generated from its `src/` (`build`), the translator hints are written by
 * hand in the same file and kept on every build, CI runs `build --check`. An application merges every installed
 * package's file (`collect`) into its export and never prunes a key a package lists.
 *
 * What counts as a server message (the same rules an application export applies to its own PHP):
 *   - `new <user-facing>Exception('literal'` and `<Templated…Exception>::fromTemplate('template'`,
 *   - message constants (`const … MESSAGE… = '…'` except push texts `…PUSH…`, `const ERROR_… = '…'`, not `ERROR_CODE…`),
 *   - `return 'literal';` in a `…Reason()` method (denial reasons that become exception messages);
 * a message is a sentence: codes (`SOME_CODE`), single words and empty strings are skipped. A file containing
 * `@server-messages-ignore-file`, or matching `DEFAULT_EXCLUDED_FILES` or a pattern of the manifest's `excludedFiles`, is
 * not scanned; the scanned folders are `DEFAULT_SCAN_DIRECTORIES` unless the manifest names its own `scanDirectories`.
 *
 * Plain PHP on purpose: it runs in a package's CI and in an application's export without booting a kernel.
 */
class ServerMessagesManifest
{
    public const string MANIFEST_PATH = 'i18n/server-messages.json';

    public const int MANIFEST_FORMAT = 1;

    public const string GENERATED_BY = 'ddd-server-messages build — keys generated from src/, hints written by hand and kept';

    /** A hint shorter than this cannot tell a translator what failed and where it shows */
    public const int MINIMUM_HINT_LENGTH = 40;

    /** Sources an endpoint's caller can reach; a manifest's `scanDirectories` replaces the list */
    public const array DEFAULT_SCAN_DIRECTORIES = [
        'src/Domain',
        'src/Presentation/Api',
        'src/Presentation/Base',
        'src/Symfony/EventListeners',
        'src/Symfony/Security',
    ];

    /**
     * Sources whose messages reach no person (workers, external-service repos, the agent's tools and evaluation): their
     * texts stay English. Regular expressions on the path relative to the package; a manifest's `excludedFiles` adds to
     * them.
     */
    public const array DEFAULT_EXCLUDED_FILES = [
        '/MessageHandlers/',
        '/Repo/Argus/',
        '/Presentation/Mcp/',
        '/Services/Eval/',
        '/Entities/Evals/',
    ];

    public const string IGNORE_FILE_MARKER = '@server-messages-ignore-file';

    public const string OUTPUT_PREFIX = '[ddd-server-messages] ';

    public const int EXIT_SUCCESS = 0;

    public const int EXIT_FAILURE = 1;

    /** Exceptions whose message reaches the caller (internal failures stay English and are not listed) */
    public const string USER_FACING_EXCEPTION_PATTERN =
        '(?:Templated)?(?:BadRequest|NotFound|Forbidden|MethodNotAllowed|Conflict|Unauthorized|TooManyRequests|UnprocessableEntity|ServiceUnavailable)Exception';

    public const string PHP_LITERAL_PATTERN = '\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*"';

    public const string USAGE = <<<'TEXT'
Usage:
  ddd-server-messages build [--package-dir <dir>] [--check]
      Scans the endpoint-reachable folders of <dir>/src and writes <dir>/i18n/server-messages.json (keys generated, hints kept).
      --check: writes nothing; exit 1 when the file is outdated or a hint is shorter than 40 characters.
  ddd-server-messages collect [--vendor-dir <dir>] [--out <file>]
      Merges the i18n/server-messages.json of every installed package below <dir>/mgamadeus/* (default ./vendor)
      into one JSON document for an application's text export (stdout or --out).

TEXT;

    /**
     * @param string[] $arguments
     */
    public static function runFromCommandLine(array $arguments, string $currentDirectory): int
    {
        $command = $arguments[0] ?? '';
        $options = array_slice($arguments, 1);
        try {
            if ($command === 'build') {
                $packageDirectory = static::readOptionValue($options, '--package-dir') ?? $currentDirectory;
                return static::runBuild(static::toAbsolutePath($packageDirectory, $currentDirectory), in_array('--check', $options, true));
            }
            if ($command === 'collect') {
                $vendorDirectory = static::readOptionValue($options, '--vendor-dir') ?? $currentDirectory . '/vendor';
                $outputFile = static::readOptionValue($options, '--out');
                $collected = static::collectInstalledPackages(static::toAbsolutePath($vendorDirectory, $currentDirectory));
                $json = static::encodeJson($collected);
                if ($outputFile === null) {
                    fwrite(STDOUT, $json);
                } else {
                    file_put_contents(static::toAbsolutePath($outputFile, $currentDirectory), $json);
                    fwrite(STDERR, self::OUTPUT_PREFIX . count($collected['keys']) . ' keys of ' . count($collected['packages']) . " packages written to {$outputFile}\n");
                }
                return self::EXIT_SUCCESS;
            }
        } catch (Throwable $throwable) {
            fwrite(STDERR, self::OUTPUT_PREFIX . 'error: ' . $throwable->getMessage() . "\n");
            return self::EXIT_FAILURE;
        }
        fwrite($command === '--help' || $command === '-h' ? STDOUT : STDERR, self::USAGE);
        return $command === '--help' || $command === '-h' ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
    }

    /**
     * Writes or checks the package's manifest.
     */
    public static function runBuild(string $packageDirectory, bool $checkOnly): int
    {
        $manifestFile = $packageDirectory . '/' . self::MANIFEST_PATH;
        $existingManifest = is_file($manifestFile) ? static::decodeJsonFile($manifestFile) : null;
        $builtManifest = static::buildManifest($packageDirectory, $existingManifest);
        $builtJson = static::encodeJson($builtManifest);
        $problems = static::findManifestProblems($builtManifest);
        if ($checkOnly) {
            if (!is_file($manifestFile) || file_get_contents($manifestFile) !== $builtJson) {
                array_unshift($problems, self::MANIFEST_PATH . ' is outdated: run ddd-server-messages build');
            }
            foreach ($problems as $problem) {
                fwrite(STDERR, self::OUTPUT_PREFIX . $problem . "\n");
            }
            fwrite(STDERR, self::OUTPUT_PREFIX . count($builtManifest['keys']) . ' server messages, ' . count($problems) . " problems\n");
            return $problems === [] ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
        }
        if (!is_dir(dirname($manifestFile))) {
            mkdir(dirname($manifestFile), 0775, true);
        }
        file_put_contents($manifestFile, $builtJson);
        foreach ($problems as $problem) {
            fwrite(STDERR, self::OUTPUT_PREFIX . $problem . "\n");
        }
        fwrite(STDERR, self::OUTPUT_PREFIX . count($builtManifest['keys']) . ' server messages written to ' . self::MANIFEST_PATH . "\n");
        return self::EXIT_SUCCESS;
    }

    /**
     * The manifest of a package: the messages found in its src/, the hints and excluded files of the existing manifest.
     *
     * @param array<string, mixed>|null $existingManifest
     * @return array<string, mixed>
     */
    public static function buildManifest(string $packageDirectory, ?array $existingManifest): array
    {
        $excludedFilePatterns = array_values(array_filter((array)($existingManifest['excludedFiles'] ?? []), 'is_string'));
        $ownScanDirectories = array_values(array_filter((array)($existingManifest['scanDirectories'] ?? []), 'is_string'));
        $scanDirectories = $ownScanDirectories !== [] ? $ownScanDirectories : self::DEFAULT_SCAN_DIRECTORIES;
        $hintsByKey = [];
        foreach ((array)($existingManifest['keys'] ?? []) as $existingEntry) {
            if (is_array($existingEntry) && isset($existingEntry['key']) && is_string($existingEntry['hint'] ?? null)) {
                $hintsByKey[(string)$existingEntry['key']] = $existingEntry['hint'];
            }
        }
        $originsByText = [];
        foreach ($scanDirectories as $scanDirectory) {
            $foundMessages = static::findServerMessagesOfDirectory(
                $packageDirectory . '/' . trim($scanDirectory, '/'),
                $packageDirectory,
                [...self::DEFAULT_EXCLUDED_FILES, ...$excludedFilePatterns]
            );
            foreach ($foundMessages as [$text, $origin]) {
                $originsByText[$text][$origin] = true;
            }
        }
        ksort($originsByText, SORT_STRING);
        $keys = [];
        foreach ($originsByText as $text => $origins) {
            $text = (string)$text;
            $originNames = array_keys($origins);
            sort($originNames, SORT_STRING);
            $keys[] = ['key' => $text, 'text' => $text, 'hint' => $hintsByKey[$text] ?? '', 'origins' => $originNames];
        }
        return [
            'generatedBy' => self::GENERATED_BY,
            'format' => self::MANIFEST_FORMAT,
            'package' => static::readPackageName($packageDirectory),
            'scanDirectories' => $ownScanDirectories,
            'excludedFiles' => $excludedFilePatterns,
            'keys' => $keys,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return string[]
     */
    public static function findManifestProblems(array $manifest): array
    {
        $problems = [];
        foreach ($manifest['keys'] as $entry) {
            if (mb_strlen(trim($entry['hint'])) < self::MINIMUM_HINT_LENGTH) {
                $problems[] = 'hint shorter than ' . self::MINIMUM_HINT_LENGTH . ' characters: ' . json_encode($entry['key'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $problems;
    }

    /**
     * The server messages of every PHP file below a directory.
     *
     * @param string[] $excludedFilePatterns regular expressions matched against the path relative to the package
     * @return array<int, array{0: string, 1: string}> pairs of message text and origin `Class::method`
     */
    public static function findServerMessagesOfDirectory(string $sourceDirectory, string $packageDirectory, array $excludedFilePatterns = []): array
    {
        if (!is_dir($sourceDirectory)) {
            return [];
        }
        $phpFiles = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.php')) {
                $phpFiles[] = $fileInfo->getPathname();
            }
        }
        sort($phpFiles, SORT_STRING);
        $messages = [];
        foreach ($phpFiles as $phpFile) {
            $relativePath = ltrim(substr($phpFile, strlen($packageDirectory)), '/');
            foreach ($excludedFilePatterns as $excludedFilePattern) {
                if (@preg_match('#' . $excludedFilePattern . '#', $relativePath) === 1) {
                    continue 2;
                }
            }
            array_push($messages, ...static::findServerMessagesOfSource((string)file_get_contents($phpFile), basename($phpFile, '.php')));
        }
        return $messages;
    }

    /**
     * @return array<int, array{0: string, 1: string}> pairs of message text and origin `Class::method`
     */
    public static function findServerMessagesOfSource(string $source, string $className): array
    {
        if (str_contains($source, self::IGNORE_FILE_MARKER)) {
            return [];
        }
        $literal = self::PHP_LITERAL_PATTERN;
        $exception = self::USER_FACING_EXCEPTION_PATTERN;
        $patterns = [
            '/new\s+' . $exception . '\(\s*(' . $literal . ')\s*[,)]/',
            '/' . $exception . '::fromTemplate\(\s*(' . $literal . ')\s*[,)]/',
            '/const\s+(?:string\s+)?(?:(?![A-Z0-9_]*PUSH)[A-Z0-9_]*MESSAGE[A-Z0-9_]*|ERROR_(?!CODE)[A-Z0-9_]+)\s*=\s*(' . $literal . ')\s*;/',
        ];
        $messages = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$matchedLiteral, $offset]) {
                $message = static::createServerMessage($matchedLiteral, $source, (int)$offset, $className);
                if ($message !== null) {
                    $messages[] = $message;
                }
            }
        }
        preg_match_all('/return\s+(' . $literal . ')\s*;/', $source, $returnMatches, PREG_OFFSET_CAPTURE);
        foreach ($returnMatches[1] as [$matchedLiteral, $offset]) {
            $methodName = static::findMethodNameAtOffset($source, (int)$offset);
            $message = $methodName !== null && str_ends_with($methodName, 'Reason')
                ? static::createServerMessage($matchedLiteral, $source, (int)$offset, $className)
                : null;
            if ($message !== null) {
                $messages[] = $message;
            }
        }
        return $messages;
    }

    /**
     * Merges the manifests of the installed packages below `<vendorDirectory>/mgamadeus/*`. A key listed by several
     * packages keeps the longest hint and every origin and package.
     *
     * @return array<string, mixed>
     */
    public static function collectInstalledPackages(string $vendorDirectory): array
    {
        $manifestFiles = glob($vendorDirectory . '/mgamadeus/*/' . self::MANIFEST_PATH) ?: [];
        sort($manifestFiles, SORT_STRING);
        $packages = [];
        $entriesByKey = [];
        foreach ($manifestFiles as $manifestFile) {
            $manifest = static::decodeJsonFile($manifestFile);
            $packageName = is_string($manifest['package'] ?? null) ? $manifest['package'] : basename(dirname($manifestFile, 2));
            $packages[] = $packageName;
            foreach ((array)($manifest['keys'] ?? []) as $entry) {
                if (!is_array($entry) || !is_string($entry['key'] ?? null)) {
                    continue;
                }
                $key = $entry['key'];
                $hint = is_string($entry['hint'] ?? null) ? $entry['hint'] : '';
                $collectedEntry = $entriesByKey[$key] ?? ['key' => $key, 'text' => (string)($entry['text'] ?? $key), 'hint' => '', 'origins' => [], 'packages' => []];
                if (mb_strlen($hint) > mb_strlen($collectedEntry['hint'])) {
                    $collectedEntry['hint'] = $hint;
                }
                $collectedEntry['origins'] = array_values(array_unique([...$collectedEntry['origins'], ...array_filter((array)($entry['origins'] ?? []), 'is_string')]));
                $collectedEntry['packages'] = array_values(array_unique([...$collectedEntry['packages'], $packageName]));
                $entriesByKey[$key] = $collectedEntry;
            }
        }
        ksort($entriesByKey, SORT_STRING);
        return ['generatedBy' => 'ddd-server-messages collect', 'format' => self::MANIFEST_FORMAT, 'packages' => $packages, 'keys' => array_values($entriesByKey)];
    }

    /**
     * A pair of message text and origin, null when the literal is no sentence (a code, a single word, empty).
     *
     * @return array{0: string, 1: string}|null
     */
    protected static function createServerMessage(string $literal, string $source, int $offset, string $className): ?array
    {
        $text = trim(static::unquotePhpLiteral($literal));
        if (!preg_match('/\s/', $text) || preg_match('/^[A-Z0-9_]+$/', $text)) {
            return null;
        }
        $methodName = static::findMethodNameAtOffset($source, $offset);
        return [$text, $methodName !== null ? $className . '::' . $methodName : $className];
    }

    /** The name of the last `function x(` before an offset */
    protected static function findMethodNameAtOffset(string $source, int $offset): ?string
    {
        preg_match_all('/function\s+([A-Za-z_]\w*)\s*\(/', substr($source, 0, $offset), $functionMatches);
        $names = $functionMatches[1];
        return $names === [] ? null : $names[count($names) - 1];
    }

    /** A PHP string literal's value (single-quoted: only `\'` and `\\`; double-quoted without `$`: the usual escapes) */
    protected static function unquotePhpLiteral(string $literal): string
    {
        $body = substr($literal, 1, -1);
        if ($literal[0] === "'") {
            return (string)preg_replace('/\\\\([\'\\\\])/', '$1', $body);
        }
        return (string)preg_replace_callback(
            '/\\\\([nt"\\\\$])/',
            static fn(array $match): string => $match[1] === 'n' ? "\n" : ($match[1] === 't' ? "\t" : $match[1]),
            $body
        );
    }

    protected static function readPackageName(string $packageDirectory): string
    {
        $composerFile = $packageDirectory . '/composer.json';
        if (!is_file($composerFile)) {
            return basename($packageDirectory);
        }
        $composer = static::decodeJsonFile($composerFile);
        return is_string($composer['name'] ?? null) ? $composer['name'] : basename($packageDirectory);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decodeJsonFile(string $file): array
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
    protected static function encodeJson(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param string[] $options
     */
    protected static function readOptionValue(array $options, string $name): ?string
    {
        foreach ($options as $index => $option) {
            if ($option === $name && isset($options[$index + 1])) {
                return $options[$index + 1];
            }
            if (str_starts_with($option, $name . '=')) {
                return substr($option, strlen($name) + 1);
            }
        }
        return null;
    }

    protected static function toAbsolutePath(string $path, string $currentDirectory): string
    {
        return str_starts_with($path, '/') ? rtrim($path, '/') : rtrim($currentDirectory . '/' . $path, '/');
    }
}
