<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use DDD\Tests\Infrastructure\ObjectStorage\Fixtures\MockHttpClientS3CompatibleObjectStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Per-store timeout and retries: read from the configuration / environment, unset keeps async-aws's own client, set
 * reaches every request of the S3 client.
 */
class S3CompatibleObjectStorageHttpClientSettingsTest extends TestCase
{
    protected const string STORE_NAME = 'thumbs-timeout-test';

    protected function tearDown(): void
    {
        foreach ([ObjectStorageSettings::SETTING_TIMEOUT_SECONDS, ObjectStorageSettings::SETTING_MAX_DURATION_SECONDS, ObjectStorageSettings::SETTING_MAX_RETRIES] as $setting) {
            $envName = ObjectStorageSettings::getEnvName(self::STORE_NAME, $setting);
            putenv($envName);
            unset($_SERVER[$envName], $_ENV[$envName]);
        }
    }

    protected function setEnv(string $setting, string $value): void
    {
        $envName = ObjectStorageSettings::getEnvName(self::STORE_NAME, $setting);
        putenv("{$envName}={$value}");
        $_SERVER[$envName] = $value;
    }

    public function testUnsetSettingsKeepTheDefaults(): void
    {
        $objectStorageSettings = ObjectStorageSettings::fromEnvironment(self::STORE_NAME);
        self::assertNull($objectStorageSettings->timeoutSeconds);
        self::assertNull($objectStorageSettings->maxDurationSeconds);
        self::assertNull($objectStorageSettings->maxRetries);
        self::assertFalse($objectStorageSettings->hasHttpClientSettings());

        $objectStorage = $this->createObjectStorage(new ObjectStorageSettings(...$this->getBaseSettings()));
        self::assertNull($objectStorage->getHttpClientForS3Client(), 'unset: async-aws builds its default client');
    }

    public function testSettingsAreReadFromTheEnvironment(): void
    {
        $this->setEnv(ObjectStorageSettings::SETTING_TIMEOUT_SECONDS, '5');
        $this->setEnv(ObjectStorageSettings::SETTING_MAX_DURATION_SECONDS, '12.5');
        $this->setEnv(ObjectStorageSettings::SETTING_MAX_RETRIES, '0');
        $objectStorageSettings = ObjectStorageSettings::fromEnvironment(self::STORE_NAME);
        self::assertSame(5.0, $objectStorageSettings->timeoutSeconds);
        self::assertSame(12.5, $objectStorageSettings->maxDurationSeconds);
        self::assertSame(0, $objectStorageSettings->maxRetries);
        self::assertTrue($objectStorageSettings->hasHttpClientSettings());
    }

    public function testInvalidValuesFallBackToTheDefault(): void
    {
        $this->setEnv(ObjectStorageSettings::SETTING_TIMEOUT_SECONDS, '0');
        $this->setEnv(ObjectStorageSettings::SETTING_MAX_RETRIES, '-1');
        $objectStorageSettings = ObjectStorageSettings::fromEnvironment(self::STORE_NAME);
        self::assertNull($objectStorageSettings->timeoutSeconds);
        self::assertNull($objectStorageSettings->maxRetries);
    }

    public function testTimeoutReachesEveryRequestOfTheS3Client(): void
    {
        $recordedOptions = [];
        $mockHttpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$recordedOptions): MockResponse {
            $recordedOptions[] = ['method' => $method, 'timeout' => $options['timeout'], 'max_duration' => $options['max_duration']];
            return new MockResponse('', ['http_code' => 200]);
        });
        $objectStorage = $this->createObjectStorage(
            new ObjectStorageSettings(...$this->getBaseSettings(), timeoutSeconds: 5.0, maxDurationSeconds: 20.0),
            $mockHttpClient
        );

        $objectStorage->putObject('thumb.jpg', 'jpeg-bytes', 'image/jpeg');
        $objectStorage->deleteObject('thumb.jpg');

        self::assertCount(2, $recordedOptions);
        foreach ($recordedOptions as $recordedOption) {
            self::assertSame(5.0, $recordedOption['timeout']);
            self::assertSame(20.0, $recordedOption['max_duration']);
        }
        self::assertSame(['PUT', 'DELETE'], array_column($recordedOptions, 'method'));
    }

    public function testMaxRetriesLimitsTheAttempts(): void
    {
        $attemptCount = 0;
        $mockHttpClient = new MockHttpClient(static function () use (&$attemptCount): MockResponse {
            $attemptCount++;
            return new MockResponse('', ['http_code' => 503]);
        });
        $objectStorage = $this->createObjectStorage(
            new ObjectStorageSettings(...$this->getBaseSettings(), timeoutSeconds: 1.0, maxRetries: 0),
            $mockHttpClient
        );

        try {
            $objectStorage->putObject('thumb.jpg', 'jpeg-bytes');
            self::fail('a 503 must fail the put');
        } catch (InternalErrorException $internalErrorException) {
            self::assertStringContainsString('put of "thumb.jpg" failed', $internalErrorException->getMessage());
        }
        self::assertSame(1, $attemptCount, 'maxRetries 0: one attempt, no retry');
    }

    /** @return array<string, mixed> */
    protected function getBaseSettings(): array
    {
        return [
            'name' => self::STORE_NAME,
            'driver' => ObjectStorageSettings::DRIVER_S3,
            'endpoint' => 'http://127.0.0.1:9',
            'bucket' => 'thumbs',
            'accessKey' => 'ddd-test',
            'secretKey' => 'ddd-test-secret',
            'pathStyle' => true,
        ];
    }

    protected function createObjectStorage(ObjectStorageSettings $objectStorageSettings, ?MockHttpClient $mockHttpClient = null): MockHttpClientS3CompatibleObjectStorage
    {
        $objectStorage = new MockHttpClientS3CompatibleObjectStorage($objectStorageSettings);
        $objectStorage->mockHttpClient = $mockHttpClient ?? new MockHttpClient();
        return $objectStorage;
    }
}
