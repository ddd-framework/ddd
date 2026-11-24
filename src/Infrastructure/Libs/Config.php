<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

use DDD\Infrastructure\Traits\SingletonTrait;

class Config
{
    use SingletonTrait;

    /**
     * Application-level config root folders. Always searched before module-level roots.
     * @var string[]
     */
    protected static array $appRootDirectories = [];

    /**
     * Module-level config root folders (DDD framework + modules). Searched after app roots.
     * @var string[]
     */
    protected static array $moduleRootDirectories = [];

    protected static array $configTree;

    /**
     * Root rank (index in the search order, 0 = highest) of each config file read, by its dot path.
     * @var int[]
     */
    protected static array $configNodeRootRanksByPath = [];

    /**
     * Dot paths of folders known to have no file of their own (in no root).
     * @var bool[]
     */
    protected static array $configNodePathsWithoutOwnFile = [];

    protected static ?array $env = null;

    /**
     * Add new config directory in which we can search for values.
     * Newer config directories within the same tier are searched first.
     * App-level directories are always searched before module-level directories.
     *
     * @param string $configRootDirectory
     * @param bool $isModule True for DDD framework / module configs (lower priority),
     *                       false for application configs (higher priority).
     * @return void
     */
    protected function addConfigRootDirectory(string $configRootDirectory, bool $isModule = false): void
    {
        // Silently ignore non-existent directories — not every package (e.g. DDD framework
        // itself, or a module without config) ships a config/app folder.
        if (!is_dir($configRootDirectory)) {
            return;
        }

        $target = $isModule ? 'moduleRootDirectories' : 'appRootDirectories';

        if (in_array($configRootDirectory, self::$$target, true)) {
            return;
        }

        // Add the new root directory at the beginning of its tier
        array_unshift(self::$$target, $configRootDirectory);
        // the root ranks of the files read so far changed
        self::$configNodeRootRanksByPath = [];
        self::$configNodePathsWithoutOwnFile = [];
    }

    /**
     * Public method to add config directory that also handles the Config instance creation
     *
     * @param string $configRootDirectory
     * @param bool $isModule True for DDD framework / module configs (lower priority),
     *                       false for application configs (higher priority).
     * @return void
     */
    public static function addConfigDirectory(string $configRootDirectory, bool $isModule = false): void
    {
        /** @var Config $configInstance */
        $configInstance = self::getInstance();
        $configInstance->addConfigRootDirectory($configRootDirectory, $isModule);
    }

    /**
     * Returns the merged list of root directories in search-priority order:
     * app-level first, then module-level.
     *
     * @return string[]
     */
    protected static function getRootDirectories(): array
    {
        return [...self::$appRootDirectories, ...self::$moduleRootDirectories];
    }

    /**
     * Every config root directory in search order (application roots first, then module roots) — for code that lists
     * what the roots hold (e.g. every skill file of every module) instead of resolving one key through {@see get()}.
     *
     * @return string[]
     */
    public static function getConfigRootDirectories(): array
    {
        return static::getRootDirectories();
    }

    /**
     * Reads a config value by its dot path (`Area.file.key…`).
     *
     * Resolution, per root in priority order (application roots, then module roots):
     * - a path segment may name a file (`Translations.php`, `.md`, `.txt`) and a folder (`Translations/`) side by
     *   side, in one root or across roots. A key the file defines wins; a key it does not define is looked up in the
     *   folder of the same name, in this root and in every lower root. The file therefore never hides the folder.
     * - a file replaces the file at the same path in a lower root whole (an application file is an override of the
     *   module's file, not a patch of it).
     * - reading the node itself (`Common.Translations`) answers the file's value, plus the entries read from the
     *   folder so far; for a key both define, the one from the higher root wins, and within one root the file wins.
     *   Folders are never listed, so an entry of the folder appears only once something read it.
     *
     * @param string $searchString
     * @param bool $prioritizeDirectorySearch true: within one root the folder is looked at before the file
     * @return mixed
     */
    public static function get(string $searchString, bool $prioritizeDirectorySearch = false): mixed
    {
        /** @var Config $configInstance */
        $configInstance = self::getInstance();
        return $configInstance->searchInConfig($searchString, $prioritizeDirectorySearch);
    }

    /**
     * @param string $searchString
     * @param bool $prioritizeDirectorySearch
     * @return mixed
     */
    protected function searchInConfig(
        string $searchString,
        bool $prioritizeDirectorySearch
    ): mixed {
        if ($searchString === '') {
            return null;
        }
        $treeValue = $this->searchInConfigTree($searchString);
        $isNodeAssembledFromDeeperFilesOnly = $treeValue && $this->isConfigNodeAssembledFromDeeperFilesOnly($searchString);
        if ($treeValue && !$isNodeAssembledFromDeeperFilesOnly) {
            return $treeValue;
        }
        $searchKeys = self::explodeSearchString($searchString);
        $nodePathsAnsweredByAFile = [];
        foreach (self::getRootDirectories() as $rootRank => $configRoot) {
            if ($this->resolveInConfigDirectory(
                $configRoot,
                $rootRank,
                $searchKeys,
                [],
                $prioritizeDirectorySearch,
                $nodePathsAnsweredByAFile
            )) {
                return $this->searchInConfigTree($searchString);
            }
        }
        if ($isNodeAssembledFromDeeperFilesOnly) {
            // a folder without a file of its own: what was read below it is all there is
            self::$configNodePathsWithoutOwnFile[$searchString] = true;
        }
        return $this->searchInConfigTree($searchString);
    }

    /**
     * Walks one root along the search keys; loads each file met on the way (file before folder, unless the folder is
     * prioritized) until one of them answers the search.
     *
     * @param string[] $searchKeys the keys still to walk
     * @param string[] $nodeKeys the keys walked so far (the path of $configDirectory below the root)
     * @param bool[] $nodePathsAnsweredByAFile dot paths a file of a higher root already stands for (by reference:
     *                                         filled across the roots of one search)
     */
    protected function resolveInConfigDirectory(
        string $configDirectory,
        int $rootRank,
        array $searchKeys,
        array $nodeKeys,
        bool $prioritizeDirectorySearch,
        array &$nodePathsAnsweredByAFile
    ): bool {
        if (!$searchKeys) {
            return false;
        }
        $currentKey = array_shift($searchKeys);
        $nodeKeys[] = $currentKey;
        $nodePath = implode('.', $nodeKeys);
        $directoryItems = scandir($configDirectory);
        if (!$directoryItems) {
            return false;
        }
        $lowercaseDirectoryItems = array_map('strtolower', $directoryItems);
        $lowercaseCurrentKey = strtolower($currentKey);

        $configFilePath = null;
        foreach (['php', 'md', 'txt'] as $extension) {
            $itemIndex = array_search($lowercaseCurrentKey . '.' . $extension, $lowercaseDirectoryItems, true);
            if ($itemIndex !== false) {
                $candidatePath = $configDirectory . DIRECTORY_SEPARATOR . $directoryItems[$itemIndex];
                if (is_file($candidatePath)) {
                    $configFilePath = $candidatePath;
                    break;
                }
            }
        }
        $subdirectoryPath = null;
        $itemIndex = array_search($lowercaseCurrentKey, $lowercaseDirectoryItems, true);
        if ($itemIndex !== false) {
            $candidatePath = $configDirectory . DIRECTORY_SEPARATOR . $directoryItems[$itemIndex];
            if (is_dir($candidatePath)) {
                $subdirectoryPath = $candidatePath;
            }
        }
        // a file of a higher root at the same path replaces this one whole
        if ($configFilePath !== null && isset($nodePathsAnsweredByAFile[$nodePath])) {
            $configFilePath = null;
        }

        if ($prioritizeDirectorySearch && $subdirectoryPath !== null && $this->resolveInConfigDirectory(
            $subdirectoryPath,
            $rootRank,
            $searchKeys,
            $nodeKeys,
            $prioritizeDirectorySearch,
            $nodePathsAnsweredByAFile
        )) {
            return true;
        }
        if ($configFilePath !== null) {
            $nodePathsAnsweredByAFile[$nodePath] = true;
            $this->loadConfigFileIntoTree($configFilePath, $nodeKeys, $rootRank);
            $searchedValue = $this->searchInConfigTree($nodePath . ($searchKeys ? '.' . implode('.', $searchKeys) : ''));
            if ($searchedValue !== null) {
                return true;
            }
        }
        if (!$prioritizeDirectorySearch && $subdirectoryPath !== null) {
            return $this->resolveInConfigDirectory(
                $subdirectoryPath,
                $rootRank,
                $searchKeys,
                $nodeKeys,
                $prioritizeDirectorySearch,
                $nodePathsAnsweredByAFile
            );
        }
        return false;
    }

    /**
     * Puts a config file's value into the tree at its path. An array value is merged with the entries already read
     * below that path (from the folder of the same name): for a key both hold, the entry of the higher root wins, and
     * within one root the file wins.
     *
     * @param string[] $nodeKeys
     */
    protected function loadConfigFileIntoTree(string $configFilePath, array $nodeKeys, int $rootRank): void
    {
        $nodePath = implode('.', $nodeKeys);
        if (!isset(self::$configTree)) {
            self::$configTree = [];
        }
        $currentTreeElement = &self::$configTree;
        foreach ($nodeKeys as $nodeKey) {
            // keep the files already read below this path (resetting it dropped every sibling, e.g. reading one
            // AI.Skills.* file dropped every other AI.* entry of the process); only a non-array value is replaced
            if (!isset($currentTreeElement[$nodeKey]) || !is_array($currentTreeElement[$nodeKey])) {
                $currentTreeElement[$nodeKey] = [];
            }
            $currentTreeElement = &$currentTreeElement[$nodeKey];
        }
        // already in the tree from this file (the tree may have been replaced since, e.g. by a test)
        if (isset(self::$configNodeRootRanksByPath[$nodePath]) && self::$configNodeRootRanksByPath[$nodePath] === $rootRank
            && $currentTreeElement !== []) {
            return;
        }
        $fileExtension = strtolower(pathinfo($configFilePath, PATHINFO_EXTENSION));
        $fileValue = ($fileExtension === 'md' || $fileExtension === 'txt')
            ? file_get_contents($configFilePath)
            : require $configFilePath;
        if (is_array($fileValue) && is_array($currentTreeElement) && $currentTreeElement !== []) {
            foreach ($currentTreeElement as $entryKey => $entryValue) {
                if (!array_key_exists($entryKey, $fileValue)
                    || $this->findHighestRootRankBelowConfigNode($nodePath . '.' . $entryKey) < $rootRank) {
                    $fileValue[$entryKey] = $entryValue;
                }
            }
        }
        $currentTreeElement = $fileValue;
        self::$configNodeRootRanksByPath[$nodePath] = $rootRank;
        unset(self::$configNodePathsWithoutOwnFile[$nodePath]);
    }

    /** The best (lowest) root rank of the files read at or below a node; PHP_INT_MAX when none */
    protected function findHighestRootRankBelowConfigNode(string $nodePath): int
    {
        $highestRootRank = PHP_INT_MAX;
        foreach (self::$configNodeRootRanksByPath as $loadedNodePath => $rootRank) {
            if (($loadedNodePath === $nodePath || str_starts_with($loadedNodePath, $nodePath . '.')) && $rootRank < $highestRootRank) {
                $highestRootRank = $rootRank;
            }
        }
        return $highestRootRank;
    }

    /**
     * True when the tree holds the node only because files below it were read (its folder), while a file of the node
     * itself or of a node above it was never read: the cached value may lack the node's own file.
     */
    protected function isConfigNodeAssembledFromDeeperFilesOnly(string $nodePath): bool
    {
        if (isset(self::$configNodePathsWithoutOwnFile[$nodePath])) {
            return false;
        }
        $hasDeeperFile = false;
        foreach (self::$configNodeRootRanksByPath as $loadedNodePath => $rootRank) {
            if ($loadedNodePath === $nodePath || str_starts_with($nodePath, $loadedNodePath . '.')) {
                return false;
            }
            if (str_starts_with($loadedNodePath, $nodePath . '.')) {
                $hasDeeperFile = true;
            }
        }
        return $hasDeeperFile;
    }

    /**
     * @param string $searchString
     * @return mixed
     */
    public function searchInConfigTree(string $searchString): mixed
    {
        if (!isset(self::$configTree)) {
            return null;
        }
        $explodedSearchString = self::explodeSearchString($searchString);
        $currentIndex = &self::$configTree;
        foreach ($explodedSearchString as $currentSubindex) {
            if (isset($currentIndex[$currentSubindex])) {
                $currentIndex = &$currentIndex[$currentSubindex];
            } else {
                return null;
            }
        }
        return $currentIndex;
    }

    public static function explodeSearchString(string $searchString): array
    {
        return explode('.', $searchString);
    }

    public static function setEnv(array $env): void
    {
        self::$env = $env;
    }

    /**
     * Returns Environment Variable
     * @param string $varname
     * @return string|false
     */
    public static function getEnv(string $varname): bool|int|float|string|null
    {
        if (isset(self::$env[$varname])) {
            $value = self::$env[$varname] ?? null;
        }
        else {
            $value = $_ENV[$varname] ?? null;
        }
        // Check and return boolean values
        if ($value !== null) {
            if (strtolower($value) === 'true') {
                return true;
            } elseif (strtolower($value) === 'false') {
                return false;
            }

            // Check and return numeric values
            if (is_numeric($value)) {
                // Floats have a decimal point
                if (strpos($value, '.') !== false) {
                    return (float)$value;
                } else {
                    return (int)$value;
                }
            }
        }

        // Return the original string or null if not set
        return $value;
    }
}
