<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

/**
 * Resolves a path that came from outside (a route parameter, a file name, an archive entry) inside a root directory
 * before any file function sees it. `..`, `%2e%2e`, absolute paths, NUL bytes, backslashes and symbolic links that
 * leave the root are refused; an optional extension allow-list narrows what may be opened.
 */
class ContainedPath
{
    /**
     * The real path of an EXISTING file below the root.
     * @param string $rootDirectory server-generated root (must exist)
     * @param string $relativePath from outside
     * @param string[] $allowedExtensions lower case without dot (`svg`, `png`); empty = any
     * @return string absolute real path inside the root
     * @throws UntrustedContentException outside the root, missing, a directory, or a refused extension
     */
    public static function resolveExistingFile(string $rootDirectory, string $relativePath, array $allowedExtensions = []): string
    {
        $candidatePath = static::buildCandidatePath($rootDirectory, $relativePath, $allowedExtensions);
        $realRootDirectory = static::resolveRootDirectory($rootDirectory);
        $realPath = realpath($candidatePath);
        if ($realPath === false || !is_file($realPath) || !static::isInside($realRootDirectory, $realPath)) {
            throw new UntrustedContentException('The path does not name a file inside its directory.');
        }
        return $realPath;
    }

    /**
     * The absolute path of a file that is about to be CREATED below the root (its directory must exist).
     * @param string $rootDirectory server-generated root (must exist)
     * @param string $relativePath from outside
     * @param string[] $allowedExtensions lower case without dot; empty = any
     * @return string absolute path inside the root
     * @throws UntrustedContentException
     */
    public static function resolveNewFile(string $rootDirectory, string $relativePath, array $allowedExtensions = []): string
    {
        $candidatePath = static::buildCandidatePath($rootDirectory, $relativePath, $allowedExtensions);
        $realRootDirectory = static::resolveRootDirectory($rootDirectory);
        $realParentDirectory = realpath(dirname($candidatePath));
        if ($realParentDirectory === false || !is_dir($realParentDirectory) || !static::isInside($realRootDirectory, $realParentDirectory, true)) {
            throw new UntrustedContentException('The path does not name a place inside its directory.');
        }
        $targetPath = $realParentDirectory . DIRECTORY_SEPARATOR . basename($candidatePath);
        if (is_link($targetPath)) {
            throw new UntrustedContentException('The path names a symbolic link.');
        }
        return $targetPath;
    }

    /**
     * Pure check of a relative path from outside: no traversal, no absolute path, no control characters.
     * @param string $relativePath
     * @return bool
     */
    public static function isSafeRelativePath(string $relativePath): bool
    {
        // an encoded dot, slash, backslash or NUL is refused as such: a second decoding further down must not open a hole
        if ($relativePath === '' || preg_match('/%(2e|2f|5c|00)/i', $relativePath) === 1) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $relativePath) === 1 || str_starts_with($relativePath, '/') || preg_match('/^[a-zA-Z]:/', $relativePath) === 1) {
            return false;
        }
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $rootDirectory
     * @param string $relativePath
     * @param string[] $allowedExtensions
     * @return string
     * @throws UntrustedContentException
     */
    protected static function buildCandidatePath(string $rootDirectory, string $relativePath, array $allowedExtensions): string
    {
        if (!static::isSafeRelativePath($relativePath)) {
            throw new UntrustedContentException('The path is not a plain relative path.');
        }
        if ($allowedExtensions !== []) {
            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                throw new UntrustedContentException('The file type is not allowed here.');
            }
        }
        return rtrim($rootDirectory, '/') . '/' . $relativePath;
    }

    /**
     * @param string $rootDirectory
     * @return string
     * @throws UntrustedContentException
     */
    protected static function resolveRootDirectory(string $rootDirectory): string
    {
        $realRootDirectory = realpath($rootDirectory);
        if ($realRootDirectory === false || !is_dir($realRootDirectory)) {
            throw new UntrustedContentException('The directory does not exist.');
        }
        return $realRootDirectory;
    }

    /**
     * @param string $realRootDirectory
     * @param string $realPath
     * @param bool $rootItselfAllowed
     * @return bool
     */
    protected static function isInside(string $realRootDirectory, string $realPath, bool $rootItselfAllowed = false): bool
    {
        if ($rootItselfAllowed && $realPath === $realRootDirectory) {
            return true;
        }
        return str_starts_with($realPath, rtrim($realRootDirectory, '/') . '/');
    }
}
