<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use Throwable;

/**
 * The configuration of one named object store. Committed in the application's `config/app/Common/ObjectStorage.php`
 * (`Config::get('Common.ObjectStorage')`, `['stores' => ['<name>' => ['driver' => 's3', 'endpoint' => …]]]`); the
 * environment variable `OBJECT_STORAGE_<NAME>_<SETTING>` overrides a committed value (NAME upper case, every other
 * character than A-Z and 0-9 an underscore). The credentials come from the environment only:
 * `OBJECT_STORAGE_<NAME>_ACCESS_KEY` / `_SECRET_KEY`.
 *
 * Drivers: `'drivers' => ['<driver name>' => <class>]` in the same file maps a driver name to a class implementing
 * {@see ObjectStorageDriver}; `s3` and `filesystem` are registered the same way ({@see self::BUILT_IN_DRIVER_CLASSES})
 * and an application entry of the same name replaces them. A driver reads its own keys with {@see self::getOption()}.
 *
 * A store is configured when it has a registered driver and the driver's settings are usable
 * ({@see self::getNotConfiguredReason()}): an s3 store needs both keys, or `credentials: ambient`
 * (`OBJECT_STORAGE_<NAME>_CREDENTIALS=ambient`) to use the credential chain of the host on purpose. A store that is not
 * configured gets null from {@see \DDD\Infrastructure\Services\ObjectStorageService} and its consumers degrade; a
 * driver name that is not registered is an error.
 */
class ObjectStorageSettings extends ValueObject
{
    public const string CONFIG_PATH = 'Common.ObjectStorage';
    public const string ENV_PREFIX = 'OBJECT_STORAGE_';

    /** Any S3-compatible service: AWS S3, Hetzner Object Storage, MinIO, Cloudflare R2 — only the settings differ */
    public const string DRIVER_S3 = 's3';
    /** A local directory: tests and development */
    public const string DRIVER_FILESYSTEM = 'filesystem';
    /** The built-in drivers; an application adds its own in `Common.ObjectStorage.drivers` */
    public const array DRIVERS = [self::DRIVER_S3, self::DRIVER_FILESYSTEM];
    public const array BUILT_IN_DRIVER_CLASSES = [
        self::DRIVER_S3 => S3CompatibleObjectStorage::class,
        self::DRIVER_FILESYSTEM => FilesystemObjectStorage::class,
    ];
    /** The key of the driver map in `Common.ObjectStorage` */
    public const string CONFIG_KEY_DRIVERS = 'drivers';
    /** The key of the store map in `Common.ObjectStorage` */
    public const string CONFIG_KEY_STORES = 'stores';

    /** `credentials: ambient`: an s3 store without keys uses the credential chain of the host on purpose */
    public const string CREDENTIALS_AMBIENT = 'ambient';

    public const string SETTING_DRIVER = 'DRIVER';
    public const string SETTING_ENDPOINT = 'ENDPOINT';
    public const string SETTING_REGION = 'REGION';
    public const string SETTING_BUCKET = 'BUCKET';
    public const string SETTING_ACCESS_KEY = 'ACCESS_KEY';
    public const string SETTING_SECRET_KEY = 'SECRET_KEY';
    public const string SETTING_PATH_STYLE = 'PATH_STYLE';
    public const string SETTING_PREFIX = 'PREFIX';
    public const string SETTING_PATH = 'PATH';
    public const string SETTING_TIMEOUT_SECONDS = 'TIMEOUT_SECONDS';
    public const string SETTING_MAX_DURATION_SECONDS = 'MAX_DURATION_SECONDS';
    public const string SETTING_MAX_RETRIES = 'MAX_RETRIES';
    public const string SETTING_HTTP_VERSION = 'HTTP_VERSION';
    public const string SETTING_CONDITIONAL_WRITES = 'CONDITIONAL_WRITES';
    public const string SETTING_CREDENTIALS = 'CREDENTIALS';

    public const string HTTP_VERSION_1_1 = '1.1';
    public const string HTTP_VERSION_2_0 = '2.0';
    public const array HTTP_VERSIONS = [self::HTTP_VERSION_1_1, self::HTTP_VERSION_2_0];

    public const string DEFAULT_REGION = 'us-east-1';

    public function __construct(
        /** @var string The store's name, e.g. `sourcemaps` */
        public readonly string $name = '',
        /** @var string|null The driver name, lower case: `s3`, `filesystem` or one of `Common.ObjectStorage.drivers`; null = no driver */
        public readonly ?string $driver = null,
        /** @var string|null S3: the endpoint, e.g. `https://hel1.your-objectstorage.com`; null = AWS */
        public readonly ?string $endpoint = null,
        public readonly string $region = self::DEFAULT_REGION,
        /** @var string|null S3: the bucket */
        public readonly ?string $bucket = null,
        public readonly ?string $accessKey = null,
        public readonly ?string $secretKey = null,
        /** @var bool S3: `https://endpoint/bucket/key` instead of `https://bucket.endpoint/key` (MinIO needs it) */
        public readonly bool $pathStyle = false,
        /** @var string Key prefix inside the bucket or directory, e.g. `shop/`; '' = none */
        public readonly string $prefix = '',
        /** @var string|null Filesystem: the root directory */
        public readonly ?string $path = null,
        /** @var float|null S3: idle timeout of one attempt in seconds (no byte sent or received); null = the HTTP client's default (`default_socket_timeout`, usually 60) */
        public readonly ?float $timeoutSeconds = null,
        /** @var float|null S3: the longest one attempt may take in seconds, however busy; null = unbounded */
        public readonly ?float $maxDurationSeconds = null,
        /** @var int|null S3: retries after a failed attempt; 0 = none; null = async-aws's default (3) */
        public readonly ?int $maxRetries = null,
        /** @var string|null S3: the HTTP version, one of {@see self::HTTP_VERSIONS} (the HTTP client's `http_version`); null = the client's choice (HTTP/2 over https when curl supports it) */
        public readonly ?string $httpVersion = null,
        /** @var bool S3: the store honours `If-None-Match: *` on PUT, copy and multipart completion; false = it ignores or refuses it */
        public readonly bool $conditionalWrites = true,
        /** @var string|null The class the driver name is registered for ({@see ObjectStorageDriver}); null = the name is not registered */
        public readonly ?string $driverClass = null,
        /** @var string|null {@see self::CREDENTIALS_AMBIENT}: s3 without keys uses the host's credential chain; null = keys required */
        public readonly ?string $credentials = null,
    ) {
        parent::__construct();
    }

    /** The store's settings from the committed configuration and the environment */
    public static function fromEnvironment(string $storeName): static
    {
        $driver = static::readSetting($storeName, self::SETTING_DRIVER, 'driver');
        $driver = $driver !== null ? strtolower(trim($driver)) : null;
        $credentials = strtolower(static::readSetting($storeName, self::SETTING_CREDENTIALS, 'credentials') ?? '');
        $prefix = static::readSetting($storeName, self::SETTING_PREFIX, 'prefix') ?? '';
        return new static(
            name: $storeName,
            driver: $driver,
            endpoint: ($endpoint = static::readSetting($storeName, self::SETTING_ENDPOINT, 'endpoint')) !== null ? rtrim($endpoint, '/') : null,
            region: static::readSetting($storeName, self::SETTING_REGION, 'region') ?? self::DEFAULT_REGION,
            bucket: static::readSetting($storeName, self::SETTING_BUCKET, 'bucket'),
            accessKey: static::readEnv(static::getEnvName($storeName, self::SETTING_ACCESS_KEY)),
            secretKey: static::readEnv(static::getEnvName($storeName, self::SETTING_SECRET_KEY)),
            pathStyle: in_array(strtolower(static::readSetting($storeName, self::SETTING_PATH_STYLE, 'pathStyle') ?? 'false'), ['1', 'true', 'yes', 'on'], true),
            prefix: $prefix !== '' ? trim($prefix, '/') . '/' : '',
            path: static::readSetting($storeName, self::SETTING_PATH, 'path'),
            timeoutSeconds: static::readPositiveFloatSetting($storeName, self::SETTING_TIMEOUT_SECONDS, 'timeoutSeconds'),
            maxDurationSeconds: static::readPositiveFloatSetting($storeName, self::SETTING_MAX_DURATION_SECONDS, 'maxDurationSeconds'),
            maxRetries: ($maxRetries = static::readSetting($storeName, self::SETTING_MAX_RETRIES, 'maxRetries')) !== null
                && ctype_digit($maxRetries) ? (int)$maxRetries : null,
            httpVersion: static::readHttpVersionSetting($storeName),
            conditionalWrites: !in_array(strtolower(static::readSetting($storeName, self::SETTING_CONDITIONAL_WRITES, 'conditionalWrites') ?? 'true'), ['0', 'false', 'no', 'off'], true),
            driverClass: $driver !== null ? (static::getRegisteredDriverClasses()[$driver] ?? null) : null,
            credentials: $credentials === self::CREDENTIALS_AMBIENT ? self::CREDENTIALS_AMBIENT : null,
        );
    }

    /** True when the store sets a timeout, a retry count or an HTTP version, i.e. the S3 client needs its own HTTP client */
    public function hasHttpClientSettings(): bool
    {
        return $this->timeoutSeconds !== null || $this->maxDurationSeconds !== null || $this->maxRetries !== null
            || $this->httpVersion !== null;
    }

    /** `1.1` or `2.0` (`2` and `1.1` as numbers accepted), else null (unset or unknown: the client's choice) */
    protected static function readHttpVersionSetting(string $storeName): ?string
    {
        $value = static::readSetting($storeName, self::SETTING_HTTP_VERSION, 'httpVersion');
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        $httpVersion = number_format((float)$value, 1, '.', '');
        return in_array($httpVersion, self::HTTP_VERSIONS, true) ? $httpVersion : null;
    }

    /** A number of seconds above 0, else null (unset, not numeric, 0 or negative: the default applies) */
    protected static function readPositiveFloatSetting(string $storeName, string $setting, string $configKey): ?float
    {
        $value = static::readSetting($storeName, $setting, $configKey);
        if ($value === null || !is_numeric($value) || (float)$value <= 0) {
            return null;
        }
        return (float)$value;
    }

    /** True when the store has a registered driver and its settings are usable ({@see self::getNotConfiguredReason()}) */
    public function isConfigured(): bool
    {
        return $this->getNotConfiguredReason() === null;
    }

    /** True when a driver name is set that no class is registered for, or whose class is no {@see ObjectStorageDriver} */
    public function hasInvalidDriver(): bool
    {
        return $this->driver !== null
            && ($this->driverClass === null || !is_a($this->driverClass, ObjectStorageDriver::class, true));
    }

    /** True when the s3 driver may use the host's credential chain (`credentials: ambient`) */
    public function usesAmbientCredentials(): bool
    {
        return $this->credentials === self::CREDENTIALS_AMBIENT;
    }

    /** Why the store is not configured, naming the settings to set; null when it is configured */
    public function getNotConfiguredReason(): ?string
    {
        if ($this->driver === null) {
            return sprintf(
                'Object storage "%s": no driver (%s, or `driver` of the store in %s)',
                $this->name,
                static::getEnvName($this->name, self::SETTING_DRIVER),
                self::CONFIG_PATH
            );
        }
        if ($this->driverClass === null) {
            return sprintf(
                'Object storage "%s": unknown driver "%s"; registered drivers: %s (an application registers its own in %s.%s)',
                $this->name,
                $this->driver,
                implode(', ', array_keys(static::getRegisteredDriverClasses())),
                self::CONFIG_PATH,
                self::CONFIG_KEY_DRIVERS
            );
        }
        if (!class_exists($this->driverClass)) {
            return sprintf('Object storage "%s": the driver "%s" is registered for %s, which does not exist', $this->name, $this->driver, $this->driverClass);
        }
        if (!is_a($this->driverClass, ObjectStorageDriver::class, true)) {
            return sprintf(
                'Object storage "%s": the driver "%s" is registered for %s, which does not implement %s',
                $this->name,
                $this->driver,
                $this->driverClass,
                ObjectStorageDriver::class
            );
        }
        if (is_a($this->driverClass, S3CompatibleObjectStorage::class, true) && !$this->usesAmbientCredentials()
            && ($this->accessKey === null || $this->secretKey === null)) {
            return sprintf(
                'Object storage "%s": the s3 driver has no credentials: set %s and %s, or declare `credentials: ambient` (%s=ambient) to use the credential chain of the host (environment, profile, instance metadata)',
                $this->name,
                static::getEnvName($this->name, self::SETTING_ACCESS_KEY),
                static::getEnvName($this->name, self::SETTING_SECRET_KEY),
                static::getEnvName($this->name, self::SETTING_CREDENTIALS)
            );
        }
        return null;
    }

    /**
     * A setting of the store's driver: `OBJECT_STORAGE_<NAME>_<OPTION>` (the option name in upper snake case,
     * `gatewayUrl` → `GATEWAY_URL`), else the store's committed key of that name, else $default.
     */
    public function getOption(string $optionName, ?string $default = null): ?string
    {
        return static::readSetting($this->name, static::getOptionSettingName($optionName), $optionName) ?? $default;
    }

    /** {@see self::getOption()} as a flag: `1`, `true`, `yes`, `on` are true, `0`, `false`, `no`, `off` false, anything else $default */
    public function getBoolOption(string $optionName, bool $default = false): bool
    {
        $value = $this->getOption($optionName);
        if ($value === null) {
            return $default;
        }
        $value = strtolower($value);
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    /** {@see self::getOption()} as a whole number; $default when unset or not a whole number */
    public function getIntOption(string $optionName, ?int $default = null): ?int
    {
        $value = $this->getOption($optionName);
        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int)$value : $default;
    }

    /** `gatewayUrl` → `GATEWAY_URL` */
    public static function getOptionSettingName(string $optionName): string
    {
        return strtoupper((string)preg_replace(['/(?<=[a-z0-9])([A-Z])/', '/[^A-Za-z0-9]+/'], ['_$1', '_'], $optionName));
    }

    /**
     * Driver name (lower case) => class: the built-in drivers, then the application's `Common.ObjectStorage.drivers`
     * (an entry of the same name replaces a built-in one).
     *
     * @return array<string, string>
     */
    public static function getRegisteredDriverClasses(): array
    {
        $driverClasses = self::BUILT_IN_DRIVER_CLASSES;
        $committedDriverClasses = static::readCommittedObjectStorageConfig()[self::CONFIG_KEY_DRIVERS] ?? null;
        if (is_array($committedDriverClasses)) {
            foreach ($committedDriverClasses as $driverName => $driverClass) {
                if (is_string($driverName) && is_string($driverClass) && $driverClass !== '') {
                    $driverClasses[strtolower(trim($driverName))] = ltrim($driverClass, '\\');
                }
            }
        }
        return $driverClasses;
    }

    /**
     * The names of the stores in the committed configuration (a store set only by environment variables is not listed).
     *
     * @return string[]
     */
    public static function getCommittedStoreNames(): array
    {
        $storeSettingsByName = static::readCommittedObjectStorageConfig()[self::CONFIG_KEY_STORES] ?? null;
        return is_array($storeSettingsByName) ? array_values(array_map('strval', array_keys($storeSettingsByName))) : [];
    }

    /** `OBJECT_STORAGE_<NAME>_<SETTING>` */
    public static function getEnvName(string $storeName, string $setting): string
    {
        return self::ENV_PREFIX . strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '_', $storeName)) . '_' . $setting;
    }

    /** The environment variable when set, else the committed value of the store, else null */
    protected static function readSetting(string $storeName, string $setting, string $configKey): ?string
    {
        $value = static::readEnv(static::getEnvName($storeName, $setting))
            ?? static::readCommittedStoreSettings($storeName)[$configKey]
            ?? null;
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }

    /**
     * The committed settings of the store; empty when the application has none.
     *
     * @return array<string, mixed>
     */
    protected static function readCommittedStoreSettings(string $storeName): array
    {
        $storeSettings = static::readCommittedObjectStorageConfig()[self::CONFIG_KEY_STORES][$storeName] ?? null;
        return is_array($storeSettings) ? $storeSettings : [];
    }

    /**
     * `Common.ObjectStorage` (`stores`, `drivers`); empty when the application has none. The method a test overrides.
     *
     * @return array<string, mixed>
     */
    protected static function readCommittedObjectStorageConfig(): array
    {
        try {
            $committedConfig = Config::get(self::CONFIG_PATH);
        } catch (Throwable) {
            return [];
        }
        return is_array($committedConfig) ? $committedConfig : [];
    }

    /** From .env (Config) or the process environment */
    protected static function readEnv(string $name): ?string
    {
        $value = Config::getEnv($name);
        if ($value === null || $value === '') {
            $value = $_SERVER[$name] ?? getenv($name);
        }
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }
}
