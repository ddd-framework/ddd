<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use DDD\DDDBundle;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\Virtual\VirtualEntityRegistry;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\ClassFinder;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Services\RequestService;
use DDD\Symfony\Kernels\DDDKernel;
use Exception;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Twig\Environment;

class DDDService
{
    public const string FRAMEWORK_ROOT_NAMESPACE = 'DDD';

    public const string APP_ROOT_NAMESPACE = 'App';

    public const string ENV_DEV = 'dev';

    public const string ENV_PROD = 'prod';

    /** @var bool If true, the service itself will throw errors */
    public bool $throwErrors = false;

    /** @var bool Controls caching behaviour, app components can access this variable in order to decide if they apply caching or not */
    public static bool $noCache = false;

    protected static $cachesSnapshotSet = false;

    /**
     * @var array<int, bool[]> Cache states pushed by createCachesSnapshot() (and so by deactivateCaches() /
     * activateCaches()) and popped by restoreCachesSnapshot(), so nested pairs each restore their own previous state.
     * $cachesSnapshotSet and $cacheStates mirror the top of this stack for subclasses that still read them.
     */
    protected static array $cachesStateStack = [];

    protected static $entityRightsRestrictionSnapshotSet = false;

    /**
     * @var bool[] Entity rights restriction states pushed by createEntityRightsRestrictionsStateSnapshot() /
     * deactivateEntityRightsRestrictions() and popped by restoreEntityRightsRestrictionsStateSnapshot(), so nested
     * pairs each restore their own previous state. $entityRightsRestrictionSnapshotSet and
     * $entityRightsRestrictionStates mirror the top of this stack for subclasses that still read them.
     */
    protected static array $entityRightsRestrictionsStateStack = [];

    protected static ?string $frameworkDir = null;

    protected static $cacheStates = [
        'doctrineRegistry' => true,
        'virtualRegistry' => true,
        'app' => true,
        'classFinder' => true
    ];

    protected static $entityRightsRestrictionStates = [
        DBEntity::class => true
    ];

    /** @var DDDService */
    protected static $instance;

    /** Above this share of the memory limit the usage counts as high */
    public const float MEMORY_USAGE_HIGH_SHARE_OF_LIMIT = 0.5;

    /** Default growth, in percent of the memory limit, since the last release before the caches are released again */
    public const float DEFAULT_MEMORY_RELEASE_STEP_PERCENT_OF_LIMIT = 10.0;

    /** Committed release step in percent of the memory limit (`config/app/Base/Repo.php`, under `database`) */
    public const string MEMORY_RELEASE_STEP_PERCENT_CONFIG_KEY = 'Base.Repo.database.memoryReleaseStepPercentOfLimit';

    /** Environment variable that overrides the committed release step */
    public const string MEMORY_RELEASE_STEP_PERCENT_ENV_NAME = 'DDD_MEMORY_RELEASE_STEP_PERCENT_OF_LIMIT';

    /** @var int|null Usage noted after the last release that left the usage above the threshold; null = none */
    protected static ?int $memoryUsageInBytesAfterLastRelease = null;

    /** @var float|null The release step in percent of the limit, read once per process */
    protected static ?float $memoryReleaseStepPercentOfLimit = null;

    /** @var string|null The `memory_limit` text {@see self::$convertedMemoryLimitInBytes} was converted from */
    protected static ?string $convertedMemoryLimitSetting = null;

    /** @var int|null The converted memory limit */
    protected static ?int $convertedMemoryLimitInBytes = null;


    public static function instance(): self
    {
        if (isset(self::$instance)) {
            return self::$instance;
        }
        /** @var self $instance */
        $instance = self::getServicePrivate(static::class);
        self::$instance = $instance;
        return $instance;
    }

    protected static function getContainerServiceClassNameForClassPrivate(string $className): ?string
    {
        if (isset(StaticRegistry::$frameworkToAppClassNames[$className])) {
            return StaticRegistry::$frameworkToAppClassNames[$className];
        }
        if (!StaticRegistry::$containerServiceClassMap) {
            StaticRegistry::$containerServiceClassMap = include_once self::getCacheDirPrivate(
                    false
                ) . '/service_class_map.php';
        }
        if (isset(StaticRegistry::$containerServiceClassMap[$className]) && StaticRegistry::$containerServiceClassMap[$className] != $className) {
            StaticRegistry::$frameworkToAppClassNames[$className] = StaticRegistry::$containerServiceClassMap[$className];
            return StaticRegistry::$containerServiceClassMap[$className];
        }
        if (str_starts_with($className, self::FRAMEWORK_ROOT_NAMESPACE)) {
            $appClassName = self::APP_ROOT_NAMESPACE . substr($className, strlen(self::FRAMEWORK_ROOT_NAMESPACE));
            try {
                if (class_exists($appClassName)) {
                    // only a genuine subclass overrides: an unrelated application class that happens to sit at the same
                    // path (a class a newer framework or module release added under a name the application already
                    // used) must never replace the framework class — it would fail later with a TypeError far away
                    $frameworkClassExists = class_exists($className) || interface_exists($className);
                    if (!$frameworkClassExists || is_a($appClassName, $className, true)) {
                        StaticRegistry::$frameworkToAppClassNames[$className] = $appClassName;
                        return $appClassName;
                    }
                    error_log(
                        "[DDD] {$appClassName} sits at the same path as {$className} but does not extend it, so it does "
                        . "not override it: rename the application class or make it extend {$className}"
                    );
                }
            }
            catch (Exception){

            }
        }
        StaticRegistry::$frameworkToAppClassNames[$className] = $className;
        return $className;
    }

    /**
     * Returns class name for given service name, this is particulary usefull as we sometimes dont want to create an instance for
     * each service in order to know its particular class name (which is eventually overwritten in the services definitions)
     * @param string $className
     * @return string|null
     */
    public function getContainerServiceClassNameForClass(string $className): ?string
    {
        return self::getContainerServiceClassNameForClassPrivate($className);
    }

    /**
     * @return RequestService|null
     */
    public function getRequestService(): ?RequestService
    {
        /** @var RequestService $requestService */
        $requestService = DDDBundle::getContainer()->get(RequestService::class);
        return $requestService;
    }

    /**
     * Pushes the current cache states. Every push is matched by exactly one restoreCachesSnapshot(), in a `finally`;
     * pairs may be nested.
     *
     * @return void
     */
    public function createCachesSnapshot(): void
    {
        $currentCacheStates = $this->getCurrentCacheStates();
        self::$cachesStateStack[] = $currentCacheStates;
        self::$cacheStates = $currentCacheStates;
        self::$cachesSnapshotSet = true;
    }

    /**
     * Pops the newest snapshot and applies it, so a nested pair restores the state of the enclosing window, not the
     * state before the outermost one. A restore without a snapshot changes nothing.
     *
     * @return void
     */
    public function restoreCachesSnapshot(): void
    {
        if (!self::$cachesStateStack) {
            return;
        }
        $this->applyCacheStates(array_pop(self::$cachesStateStack));
        self::$cachesSnapshotSet = self::$cachesStateStack !== [];
        if (self::$cachesSnapshotSet) {
            self::$cacheStates = self::$cachesStateStack[array_key_last(self::$cachesStateStack)];
        }
    }

    /**
     * Whether each cache is ON right now. The extension point for an application that adds a cache switch: it
     * overrides this and {@see applyCacheStates()} (parent result plus its own key) instead of the public methods.
     *
     * @return bool[] Cache state by cache name, true = ON
     */
    protected function getCurrentCacheStates(): array
    {
        return [
            'doctrineRegistry' => !DoctrineEntityRegistry::$clearCache,
            'virtualRegistry' => !VirtualEntityRegistry::$clearCache,
            'app' => !DDDService::$noCache,
            'classFinder' => !ClassFinder::$clearCache
        ];
    }

    /**
     * Switches every cache to the given state; a cache missing from the states is switched ON.
     *
     * @param bool[] $cacheStates Cache state by cache name as returned by getCurrentCacheStates()
     * @return void
     */
    protected function applyCacheStates(array $cacheStates): void
    {
        DoctrineEntityRegistry::$clearCache = !($cacheStates['doctrineRegistry'] ?? true);
        VirtualEntityRegistry::$clearCache = !($cacheStates['virtualRegistry'] ?? true);
        DDDService::$noCache = !($cacheStates['app'] ?? true);
        ClassFinder::$clearCache = !($cacheStates['classFinder'] ?? true);
    }

    /**
     * Deactivates all application related caches. Pushes a snapshot first: pair it with restoreCachesSnapshot() in a
     * `finally`; pairs may be nested.
     *
     * @return void
     */
    public function deactivateCaches(): void
    {
        $this->createCachesSnapshot();
        $this->applyCacheStates($this->getCacheStatesAllSwitchedTo(false));
    }

    /**
     * Every cache this service knows — the framework's and those an application adds through
     * {@see getCurrentCacheStates()} — mapped to one state, so deactivateCaches() / activateCaches() switch the
     * application's caches too without being overridden.
     *
     * @param bool $cachesOn true = every cache ON
     * @return bool[] Cache state by cache name
     */
    protected function getCacheStatesAllSwitchedTo(bool $cachesOn): array
    {
        return array_fill_keys(array_keys($this->getCurrentCacheStates()), $cachesOn);
    }

    /**
     * A new worker job starts with every cache ON and no cache-state snapshot — a job that died inside a
     * deactivateCaches/restoreCachesSnapshot pair would otherwise leave the next jobs of the process without caches.
     * Called by the handler base's job reset only.
     */
    public function resetCachesStateForNewJob(): void
    {
        // every cache ON, including the switches an application adds through applyCacheStates()
        $this->applyCacheStates([]);
        self::$cachesStateStack = [];
        self::$cachesSnapshotSet = false;
    }

    /**
     * Activates all application related caches. Pushes a snapshot first like deactivateCaches(): pair it with its own
     * restoreCachesSnapshot() in a `finally`; pairs may be nested.
     *
     * @return void
     */
    public function activateCaches(): void
    {
        $this->createCachesSnapshot();
        $this->applyCacheStates($this->getCacheStatesAllSwitchedTo(true));
    }

    protected static function getServicePrivate(string $serviceName): mixed
    {
        $className = self::getContainerServiceClassNameForClassPrivate($serviceName);
        return DDDBundle::getContainer()->get($className);
    }

    /**
     * Returns any service from container
     * @param string $serviceName
     * @return mixed
     */
    public function getService(string $serviceName): mixed
    {
        return self::getServicePrivate($serviceName);
    }

    /**
     * @return DDDKernel Returns current Kernel
     */
    public function getKernel(): DDDKernel
    {
        /** @var DDDKernel $kernel */
        $kernel = self::getService('kernel');
        return $kernel;
    }

    /**
     * Returns the current app environment
     * @return string
     */
    public function getEnvironment(): string
    {
        return self::getService('kernel')->getEnvironment();
    }

    /**
     * Returns the kernel prefix of the AppKernel, e.g. api/admin, api/client etc.
     * @return string|null
     */
    public function getKernelPrefix(): ?string
    {
        /** @var DDDKernel $kernel */
        $kernel = self::getService('kernel');
        if (method_exists($kernel, 'getKernelPrefix')) {
            return $kernel->getKernelPrefix();
        }
        return null;
    }

    /**
     * Return the path of the console of the current kernel
     * @param bool $returnRelativePath
     * @return string
     * @throws Exception
     */
    public function getConsoleDir(bool $returnRelativePath = true): string
    {
        $kernelPrefix = self::getKernelPrefix();
        $consolePath = $returnRelativePath ? '' : self::getRootDir();
        $consolePath .= '/bin/console';
        if ($kernelPrefix) {
            $consolePath .= '_' . str_replace('/', '_', $kernelPrefix);
        }
        return $consolePath;
    }

    protected static function getCacheDirPrivate(bool $returnRelativePath = true): string
    {
        $path = DDDBundle::getContainer()->getParameter('kernel.cache_dir');
        if (!$returnRelativePath) {
            return $path;
        } else {
            return str_replace(self::getRootDirPrivate(), '', $path);
        }
    }

    /**
     * Returns file cache path of symfony
     * @param bool $returnRelativePath
     * @return string
     */
    public function getCacheDir(bool $returnRelativePath = true): string
    {
        return self::getCacheDirPrivate($returnRelativePath);
    }

    public function createConsoleApplicationForCurrentKernel(): Application
    {
        $kernel = self::getKernel();
        return new Application($kernel);
    }

    public static function getRootDirPrivate(): string
    {
        return DDDBundle::getContainer()->getParameter('kernel.project_dir');
    }

    /**
     * Returns the root directory of the project, e.g. /var/www/dev-workspaces/<workspace>/app
     * @return string
     */
    public function getRootDir(): string
    {
        return self::getRootDirPrivate();
    }

    /** @var string The path segment that marks a WORKSPACE checkout; the segment after it is the workspace's name. */
    public const string WORKSPACES_PATH_SEGMENT = '/dev-workspaces/';

    /**
     * The name of the workspace this process runs in — `/var/www/dev-workspaces/prj6/app` → `prj6` — or null outside
     * a workspace (production, a developer's machine). Several checkouts of one app share a machine, a database and a
     * message broker; everything that must be told apart per checkout (the broker vhost, the cache namespace, the
     * origin stamp on a message) derives its name from THIS method, so no two of them can ever disagree.
     */
    public function getWorkspaceName(): ?string
    {
        return self::workspaceNameFromRootDir(self::getRootDirPrivate());
    }

    /** The pure derivation behind {@see getWorkspaceName()}. */
    public static function workspaceNameFromRootDir(string $rootDir, ?string $workspacesPathSegment = null): ?string
    {
        // The segment is a PARAMETER wherever one is configured ({@see
        // \DDD\Symfony\DependencyInjection\EnvVarProcessors\WorkspaceVhostEnvVarProcessor::PATH_SEGMENT_PARAMETER});
        // the constant below is only its default, so a deployment that lays its checkouts out differently changes
        // ONE value and every derivation follows.
        $workspacesPathSegment = $workspacesPathSegment !== null && $workspacesPathSegment !== ''
            ? $workspacesPathSegment
            : self::WORKSPACES_PATH_SEGMENT;
        if (preg_match('#' . preg_quote($workspacesPathSegment, '#') . '([^/]+)/#', rtrim($rootDir, '/') . '/', $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    /**
     * @return string Returns the root dir of the framework
     */
    public function getFrameworkRootDir(): string
    {
        if (isset(self::$frameworkDir)) {
            return self::$frameworkDir;
        } else {
            $appServiceReflectionClass = new ReflectionClass(self::class);
            $frameworkRoot = $appServiceReflectionClass->getFileName();

            // Climb up two levels from the AppService.php file to reach the ddd_src directory
            $rootPath = dirname(dirname(dirname($frameworkRoot)));

            return $rootPath;
        }
    }

    public function getLogger(): LoggerInterface
    {
        /** @var LoggerInterface $logger */
        $logger = self::getService('logger');
        return $logger;
    }

    public function getTemplateRenderer(): Environment
    {
        /** @var Environment $templatesEnvironment */
        $templatesEnvironment = self::getService('templates');
        return $templatesEnvironment;
    }

    /**
     * The limit is read from `memory_limit` on every call and converted only when its text changed, so a limit set
     * later in the process (`ini_set('memory_limit', …)` in a command) is honoured.
     *
     * @return int The PHP memory limit in bytes; PHP_INT_MAX when the limit is off (`-1`) or unreadable
     */
    public function getMemoryLimitInBytes(): int
    {
        $memoryLimitSetting = $this->getMemoryLimitSetting();
        if ($memoryLimitSetting !== self::$convertedMemoryLimitSetting || self::$convertedMemoryLimitInBytes === null) {
            self::$convertedMemoryLimitInBytes = self::convertMemoryLimitToBytes($memoryLimitSetting);
            self::$convertedMemoryLimitSetting = $memoryLimitSetting;
        }
        return self::$convertedMemoryLimitInBytes;
    }

    /**
     * @return string The raw `memory_limit` setting of the process
     */
    protected function getMemoryLimitSetting(): string
    {
        return (string)ini_get('memory_limit');
    }

    /**
     * @return int The memory the process uses now, in bytes (`memory_get_usage()`)
     */
    protected function getCurrentMemoryUsageInBytes(): int
    {
        return memory_get_usage();
    }

    /**
     * A `memory_limit` value in bytes: plain bytes, a `K` / `M` / `G` suffix in either case; `-1` (no limit), an empty
     * or an unreadable value is PHP_INT_MAX. Before, `-1` and plain byte values came back as a string and failed the
     * int return type.
     */
    public static function convertMemoryLimitToBytes(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        if (!preg_match('/^(\d+)\s*([kmg]?)$/i', $memoryLimit, $matches)) {
            return PHP_INT_MAX;
        }
        $multiplier = match (strtolower($matches[2])) {
            'k' => 1024,
            'm' => 1024 * 1024,
            'g' => 1024 * 1024 * 1024,
            default => 1,
        };
        $memoryLimitInBytes = (int)$matches[1] * $multiplier;
        return $memoryLimitInBytes > 0 ? $memoryLimitInBytes : PHP_INT_MAX;
    }

    /**
     * Raises the PHP memory limit to the requested one; never lowers it. For code that runs inside a process it does
     * not own (a message handler: in a worker, but also in-process from a console command with a higher limit), where
     * `ini_set('memory_limit', …)` would lower the limit of that process, or, with the usage already above the
     * requested value, make PHP warn "Failed to set memory limit" (an exception under the dev kernel).
     *
     * - The current limit is off (`-1`) or already at or above the requested one: nothing changes.
     * - The requested limit is at or below the current usage: nothing changes (a value PHP would refuse with a
     *   warning). This cannot happen while the current limit is below the requested one, since the usage never
     *   exceeds the current limit; the guard keeps the method warning-free whatever the seams report.
     * - Else the limit is set to the requested value; `-1` lifts it.
     *
     * @param string $memoryLimit A `memory_limit` value: plain bytes, a `K` / `M` / `G` suffix in either case, or `-1`
     * @return bool True when the limit was changed
     * @throws \InvalidArgumentException When the value is no `memory_limit` value (or zero)
     */
    public function raiseMemoryLimitToAtLeast(string $memoryLimit): bool
    {
        $memoryLimit = trim($memoryLimit);
        $requestedMemoryLimitInBytes = self::convertMemoryLimitToBytes($memoryLimit);
        if ($requestedMemoryLimitInBytes === PHP_INT_MAX && $memoryLimit !== '-1') {
            throw new \InvalidArgumentException("'{$memoryLimit}' is no memory_limit value (bytes, K, M, G or -1)");
        }
        $currentMemoryLimitInBytes = $this->getMemoryLimitInBytes();
        if ($currentMemoryLimitInBytes === PHP_INT_MAX || $currentMemoryLimitInBytes >= $requestedMemoryLimitInBytes) {
            return false;
        }
        if ($requestedMemoryLimitInBytes <= $this->getCurrentMemoryUsageInBytes()) {
            return false;
        }
        return $this->setMemoryLimitSetting($memoryLimit);
    }

    /**
     * @param string $memoryLimit The `memory_limit` value to set
     * @return bool True when PHP accepted the value
     */
    protected function setMemoryLimitSetting(string $memoryLimit): bool
    {
        return ini_set('memory_limit', $memoryLimit) !== false;
    }

    /**
     * @return bool True when the usage is above half the memory limit; never true without a limit (`-1`)
     */
    public function isMemoryUsageHigh(): bool
    {
        $memoryLimitInBytes = $this->getMemoryLimitInBytes();
        if ($memoryLimitInBytes === PHP_INT_MAX) {
            return false;
        }
        return $this->getCurrentMemoryUsageInBytes() / $memoryLimitInBytes > self::MEMORY_USAGE_HIGH_SHARE_OF_LIMIT;
    }

    /**
     * Whether the process caches (Doctrine unit of work, entity registry) should be released now. Releasing helps
     * only when those caches hold the memory; a process that holds it for another reason stays above the threshold
     * after a release, and releasing on every read then costs a full clear and cycle collection per read while the
     * registry stores nothing. So:
     * - below the threshold ({@see self::isMemoryUsageHigh()}): never due;
     * - the first crossing of the threshold (no release noted above it): due;
     * - after a release that left the usage above the threshold: due again only once the usage has grown by the
     *   release step ({@see self::getMemoryReleaseStepInBytes()}) beyond the usage noted after that release.
     * Falling below the threshold re-arms the first crossing.
     *
     * The caller that releases calls {@see self::noteMemoryReleased()} afterwards
     * ({@see \DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory::clearAllInstanceCaches()} does).
     *
     * @return bool True when a release of the process caches is due
     */
    public function isMemoryReleaseDue(): bool
    {
        if (!$this->isMemoryUsageHigh()) {
            self::$memoryUsageInBytesAfterLastRelease = null;
            return false;
        }
        if (self::$memoryUsageInBytesAfterLastRelease === null) {
            return true;
        }
        return $this->getCurrentMemoryUsageInBytes() - self::$memoryUsageInBytesAfterLastRelease
            >= $this->getMemoryReleaseStepInBytes();
    }

    /**
     * Notes that the process caches were just released: the usage now is what the next release is measured against.
     * A release that brought the usage below the threshold notes nothing, so the next crossing releases again.
     */
    public function noteMemoryReleased(): void
    {
        self::$memoryUsageInBytesAfterLastRelease = $this->isMemoryUsageHigh()
            ? $this->getCurrentMemoryUsageInBytes()
            : null;
    }

    /**
     * Forgets the last release, so the next {@see self::isMemoryReleaseDue()} above the threshold is due.
     */
    public function resetMemoryReleaseState(): void
    {
        self::$memoryUsageInBytesAfterLastRelease = null;
    }

    /**
     * @return int Bytes the usage must grow beyond the usage after the last release before the next release is due
     */
    public function getMemoryReleaseStepInBytes(): int
    {
        $memoryLimitInBytes = $this->getMemoryLimitInBytes();
        if ($memoryLimitInBytes === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }
        return max(1, (int)($memoryLimitInBytes * $this->getMemoryReleaseStepPercentOfLimit() / 100));
    }

    /**
     * The release step in percent of the memory limit: the environment variable
     * {@see self::MEMORY_RELEASE_STEP_PERCENT_ENV_NAME}, else the committed
     * {@see self::MEMORY_RELEASE_STEP_PERCENT_CONFIG_KEY} (`config/app/Base/Repo.php`, under `database`), else
     * {@see self::DEFAULT_MEMORY_RELEASE_STEP_PERCENT_OF_LIMIT}. Read once per process; a value that is not a positive
     * number falls back to the default.
     *
     * @return float The release step in percent of the memory limit
     */
    protected function getMemoryReleaseStepPercentOfLimit(): float
    {
        if (self::$memoryReleaseStepPercentOfLimit !== null) {
            return self::$memoryReleaseStepPercentOfLimit;
        }
        $configuredPercent = Config::getEnv(self::MEMORY_RELEASE_STEP_PERCENT_ENV_NAME);
        if ($configuredPercent === null || $configuredPercent === '' || $configuredPercent === false) {
            $configuredPercent = Config::get(self::MEMORY_RELEASE_STEP_PERCENT_CONFIG_KEY);
        }
        self::$memoryReleaseStepPercentOfLimit = is_numeric($configuredPercent) && (float)$configuredPercent > 0
            ? (float)$configuredPercent
            : self::DEFAULT_MEMORY_RELEASE_STEP_PERCENT_OF_LIMIT;
        return self::$memoryReleaseStepPercentOfLimit;
    }

    /**
     * Pushes the current Entity rights restriction state onto the snapshot stack.
     * Every push must be matched by exactly one {@see self::restoreEntityRightsRestrictionsStateSnapshot()},
     * typically in a `finally` block; a push without a matching restore stays on the stack.
     * @return void
     */
    public function createEntityRightsRestrictionsStateSnapshot(): void
    {
        $currentState = DBEntity::getApplyRightsRestrictions();
        self::$entityRightsRestrictionsStateStack[] = $currentState;
        self::$entityRightsRestrictionStates = [
            DBEntity::class => $currentState
        ];
        self::$entityRightsRestrictionSnapshotSet = true;
    }

    /**
     * Pops the most recent snapshot and restores that Entity rights restriction state. No-op on an empty stack, so it
     * is safe in a `finally` block. A restore only undoes its own matching create/deactivate: restoring twice for one
     * deactivate pops the ENCLOSING snapshot and re-activates restrictions before the outer scope has finished.
     * @return void
     */
    public function restoreEntityRightsRestrictionsStateSnapshot(): void
    {
        if (!self::$entityRightsRestrictionsStateStack) {
            return;
        }
        DBEntity::setApplyRightsRestrictions(array_pop(self::$entityRightsRestrictionsStateStack));
        self::$entityRightsRestrictionSnapshotSet = self::$entityRightsRestrictionsStateStack !== [];
        if (self::$entityRightsRestrictionSnapshotSet) {
            self::$entityRightsRestrictionStates = [
                DBEntity::class => self::$entityRightsRestrictionsStateStack[array_key_last(self::$entityRightsRestrictionsStateStack)]
            ];
        }
    }

    /**
     * A new worker job starts with rights restrictions ON and an EMPTY snapshot stack — a job that died inside a
     * deactivate/restore pair would otherwise leave every later job of the process without rights, and its stale
     * frames would be popped by restores that never pushed them. Called by the handler base's job reset only.
     */
    public function resetEntityRightsRestrictionsStateForNewJob(): void
    {
        self::$entityRightsRestrictionsStateStack = [];
        self::$entityRightsRestrictionSnapshotSet = false;
        self::$entityRightsRestrictionStates = [DBEntity::class => true];
        DBEntity::setApplyRightsRestrictions(true);
    }

    /**
     * Snapshots the current Entity rights restriction state and deactivates restrictions. Pair every call with
     * {@see self::restoreEntityRightsRestrictionsStateSnapshot()} in a `finally`; pairs may be nested.
     * @return void
     */
    public function deactivateEntityRightsRestrictions(): void
    {
        self::createEntityRightsRestrictionsStateSnapshot();
        DBEntity::setApplyRightsRestrictions(false);
    }

    /**
     * Returns the configured default Admin Account for CLI operations since they are not run with a Account logged in but often require an admin Account
     * @return Account
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getDefaultAccountForCliOperations(): ?Account
    {
        $this->deactivateEntityRightsRestrictions();
        $account = Account::byId(Config::getEnv('CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS'));
        $this->restoreEntityRightsRestrictionsStateSnapshot();
        return $account;
    }
}