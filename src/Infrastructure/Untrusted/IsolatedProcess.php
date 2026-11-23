<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A child process for work on foreign bytes (a converter, a scanner, a merge tool):
 * - an argument array, never a shell string; the binary resolved to an absolute path;
 * - only server-generated paths on the command line (the caller writes foreign bytes to a file it named itself);
 * - an environment stripped of every inherited variable (no secret of the PHP process reaches the tool), only PATH,
 *   HOME, TMPDIR and LANG;
 * - `timeout -k` as the outer kill switch for the whole process group (when coreutils `timeout` exists), the Process
 *   timeout as a backstop; `prlimit --as` caps the address space when `prlimit` exists.
 *
 * Network isolation is not in reach of PHP: tools that do not need the network get none from the container or
 * sandbox they run in.
 */
class IsolatedProcess
{
    /** @var string PATH of the child */
    public const string SAFE_PATH = '/usr/local/bin:/usr/bin:/bin';

    /** @var int Default timeout (seconds) */
    public const int DEFAULT_TIMEOUT_SECONDS = 60;

    /** @var int Default address space cap (bytes) when prlimit exists */
    public const int DEFAULT_ADDRESS_SPACE_BYTES = 2147483648;

    /**
     * Builds the process: binary + arguments, isolated environment, kill switch, limits. The caller runs it
     * (`run()`, `mustRun()`) and reads its output.
     *
     * @param string $binary name on the PATH or an absolute path
     * @param string[] $arguments every argument separately; foreign values only as files the caller named
     * @param string $workingDirectory server-generated, used as HOME and TMPDIR parent
     * @param int $timeoutSeconds
     * @param int|null $addressSpaceBytes null = no prlimit
     * @return Process
     * @throws InternalErrorException the binary is not installed
     */
    public static function create(
        string $binary,
        array $arguments,
        string $workingDirectory,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?int $addressSpaceBytes = self::DEFAULT_ADDRESS_SPACE_BYTES
    ): Process {
        $command = array_merge(
            static::buildTimeoutPrefix($timeoutSeconds),
            $addressSpaceBytes !== null ? static::buildAddressSpacePrefix($addressSpaceBytes) : [],
            [static::resolveBinary($binary)],
            array_map('strval', array_values($arguments))
        );
        $process = new Process($command, $workingDirectory, static::buildIsolatedEnvironment($workingDirectory));
        $process->setTimeout($timeoutSeconds + 10);
        return $process;
    }

    /**
     * Every inherited variable removed (Symfony Process: false = unset); only PATH, HOME, TMPDIR and LANG set.
     * @param string $workingDirectory
     * @return array<string, string|false>
     */
    public static function buildIsolatedEnvironment(string $workingDirectory): array
    {
        $environment = [];
        foreach (array_keys(getenv()) as $inheritedName) {
            $environment[$inheritedName] = false;
        }
        foreach (array_keys($_ENV) as $inheritedName) {
            $environment[$inheritedName] = false;
        }
        foreach (array_keys($_SERVER) as $inheritedName) {
            if (is_string($inheritedName) && is_string($_SERVER[$inheritedName])) {
                $environment[$inheritedName] = false;
            }
        }
        $environment['PATH'] = static::SAFE_PATH;
        $environment['HOME'] = $workingDirectory;
        $environment['TMPDIR'] = $workingDirectory;
        $environment['LANG'] = 'C.UTF-8';
        return $environment;
    }

    /**
     * @param int $timeoutSeconds
     * @return string[] `timeout -k 5 <s>` (signals the whole process group), empty when not installed
     */
    public static function buildTimeoutPrefix(int $timeoutSeconds): array
    {
        $timeoutBinary = (new ExecutableFinder())->find('timeout');
        return $timeoutBinary !== null ? [$timeoutBinary, '-k', '5', (string)$timeoutSeconds] : [];
    }

    /**
     * @param int $addressSpaceBytes
     * @return string[] `prlimit --as=<bytes> --`, empty when not installed
     */
    public static function buildAddressSpacePrefix(int $addressSpaceBytes): array
    {
        $prlimitBinary = (new ExecutableFinder())->find('prlimit');
        return $prlimitBinary !== null ? [$prlimitBinary, '--as=' . $addressSpaceBytes, '--'] : [];
    }

    /**
     * @param string $binary name on the PATH or an absolute path
     * @return string absolute path
     * @throws InternalErrorException not installed or not executable
     */
    public static function resolveBinary(string $binary): string
    {
        if (str_starts_with($binary, '/')) {
            if (!is_executable($binary)) {
                throw new InternalErrorException("{$binary} is not executable");
            }
            return $binary;
        }
        if (preg_match('/^[A-Za-z0-9._+-]+$/', $binary) !== 1) {
            throw new InternalErrorException('A binary is named by its plain name or an absolute path.');
        }
        $resolvedBinary = (new ExecutableFinder())->find($binary);
        if ($resolvedBinary === null) {
            throw new InternalErrorException("{$binary} is not installed");
        }
        return $resolvedBinary;
    }

    /**
     * A fresh private working directory below the system temp directory (mode 0700).
     * @param string $purpose short label for the directory name
     * @return string absolute path
     * @throws InternalErrorException
     */
    public static function createWorkingDirectory(string $purpose = 'isolated'): string
    {
        $workingDirectory = rtrim(sys_get_temp_dir(), '/') . '/ddd-' . preg_replace('/[^a-z0-9-]/', '', strtolower($purpose)) . '-' . bin2hex(random_bytes(8));
        if (!mkdir($workingDirectory, 0700, true) && !is_dir($workingDirectory)) {
            throw new InternalErrorException('The working directory cannot be created.');
        }
        return $workingDirectory;
    }

    /**
     * Removes a working directory made by {@see createWorkingDirectory()} with everything in it (links are removed,
     * never followed).
     * @param string $workingDirectory
     * @return void
     */
    public static function removeWorkingDirectory(string $workingDirectory): void
    {
        if (!is_dir($workingDirectory) || is_link($workingDirectory)) {
            return;
        }
        foreach (scandir($workingDirectory) ?: [] as $entryName) {
            if ($entryName === '.' || $entryName === '..') {
                continue;
            }
            $entryPath = $workingDirectory . '/' . $entryName;
            if (is_dir($entryPath) && !is_link($entryPath)) {
                static::removeWorkingDirectory($entryPath);
            } else {
                @unlink($entryPath);
            }
        }
        @rmdir($workingDirectory);
    }
}
