<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\BasicObjectStorage;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use DDD\Infrastructure\ObjectStorage\S3CompatibleObjectStorage;
use DDD\Tests\Infrastructure\ObjectStorage\Fixtures\FixtureConfigObjectStorageService;
use DDD\Tests\Infrastructure\ObjectStorage\Fixtures\FixtureConfigObjectStorageSettings;
use DDD\Tests\Infrastructure\ObjectStorage\Fixtures\InMemoryPictureObjectStorage;
use PHPUnit\Framework\TestCase;

/**
 * Drivers registered by configuration, the narrow contract through the service, and s3 credentials: keys, ambient
 * on purpose, or not configured with the reason.
 */
class ObjectStorageDriversTest extends TestCase
{
    /** @var string[] Environment variables a test set */
    protected array $setEnvNames = [];

    protected function tearDown(): void
    {
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = [];
        foreach ($this->setEnvNames as $envName) {
            putenv($envName);
            unset($_SERVER[$envName], $_ENV[$envName]);
        }
        $this->setEnvNames = [];
    }

    protected function setEnv(string $storeName, string $setting, string $value): void
    {
        $envName = ObjectStorageSettings::getEnvName($storeName, $setting);
        putenv("{$envName}={$value}");
        $_SERVER[$envName] = $value;
        $this->setEnvNames[] = $envName;
    }

    public function testAnApplicationDriverIsRegisteredAndConfiguredByConfig(): void
    {
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = [
            'drivers' => ['Pictures-Gateway' => '\\' . InMemoryPictureObjectStorage::class],
            'stores' => ['pictures' => ['driver' => 'pictures-gateway', 'gatewayUrl' => 'https://pictures.example.test']],
        ];
        $objectStorageService = new FixtureConfigObjectStorageService();

        self::assertTrue($objectStorageService->isObjectStorageConfigured('pictures'));
        self::assertNull($objectStorageService->getObjectStorageNotConfiguredReason('pictures'));
        self::assertSame(['pictures'], $objectStorageService->getObjectStorageNames());
        $pictureObjectStorage = $objectStorageService->getBasicObjectStorage('pictures');
        self::assertInstanceOf(InMemoryPictureObjectStorage::class, $pictureObjectStorage);
        self::assertSame('https://pictures.example.test', $pictureObjectStorage->gatewayUrl, 'the driver reads its own committed key');
        self::assertSame($pictureObjectStorage, $objectStorageService->getBasicObjectStorage('pictures'), 'built once per process');

        $pictureObjectStorage->putObject('pictures/1.jpg', 'jpeg');
        self::assertTrue($pictureObjectStorage->objectExists('pictures/1.jpg'));
        self::assertSame('jpeg', $pictureObjectStorage->getObjectContent('pictures/1.jpg'));
        $pictureObjectStorage->deleteObject('pictures/1.jpg');
        self::assertFalse($pictureObjectStorage->objectExists('pictures/1.jpg'));
    }

    public function testTheEnvironmentOverridesADriverOption(): void
    {
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = [
            'drivers' => ['pictures-gateway' => InMemoryPictureObjectStorage::class],
            'stores' => ['pictures' => ['driver' => 'pictures-gateway', 'gatewayUrl' => 'https://committed.example.test', 'retryCount' => 2, 'signed' => true]],
        ];
        $this->setEnv('pictures', 'GATEWAY_URL', 'https://host.example.test');
        $objectStorageSettings = FixtureConfigObjectStorageSettings::fromEnvironment('pictures');

        self::assertSame('GATEWAY_URL', ObjectStorageSettings::getOptionSettingName('gatewayUrl'));
        self::assertSame('https://host.example.test', $objectStorageSettings->getOption('gatewayUrl'));
        self::assertSame(2, $objectStorageSettings->getIntOption('retryCount'));
        self::assertTrue($objectStorageSettings->getBoolOption('signed'));
        self::assertSame('fallback', $objectStorageSettings->getOption('missingOption', 'fallback'));
    }

    public function testANarrowDriverIsRefusedWhereTheFullContractIsAsked(): void
    {
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = [
            'drivers' => ['pictures-gateway' => InMemoryPictureObjectStorage::class],
            'stores' => ['pictures' => ['driver' => 'pictures-gateway', 'gatewayUrl' => 'https://pictures.example.test']],
        ];
        $objectStorageService = new FixtureConfigObjectStorageService();

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessageMatches('/implements only .*BasicObjectStorage/');
        $objectStorageService->getObjectStorage('pictures');
    }

    public function testANarrowStoreSetForATestIsServedAsBasic(): void
    {
        $objectStorageService = new FixtureConfigObjectStorageService();
        $objectStorageService->setBasicObjectStorage('pictures', new InMemoryPictureObjectStorage('https://pictures.example.test'));

        self::assertTrue($objectStorageService->isObjectStorageConfigured('pictures'));
        self::assertInstanceOf(BasicObjectStorage::class, $objectStorageService->getBasicObjectStorage('pictures'));
    }

    public function testBuiltInDriversServeTheBasicAndTheFullContract(): void
    {
        $rootDirectory = sys_get_temp_dir() . '/ddd-object-storage-drivers-' . bin2hex(random_bytes(4));
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = [
            'stores' => ['exports' => ['driver' => 'FileSystem', 'path' => $rootDirectory]],
        ];
        $objectStorageService = new FixtureConfigObjectStorageService();

        self::assertInstanceOf(FilesystemObjectStorage::class, $objectStorageService->getObjectStorage('exports'));
        self::assertSame($objectStorageService->getObjectStorage('exports'), $objectStorageService->getBasicObjectStorage('exports'));
        self::assertSame(
            [ObjectStorageSettings::DRIVER_S3 => S3CompatibleObjectStorage::class, ObjectStorageSettings::DRIVER_FILESYSTEM => FilesystemObjectStorage::class],
            FixtureConfigObjectStorageSettings::getRegisteredDriverClasses()
        );
    }

    public function testAnUnknownDriverIsReportedNotSilentlyNull(): void
    {
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = ['stores' => ['pictures' => ['driver' => 'gcs']]];
        $objectStorageService = new FixtureConfigObjectStorageService();

        self::assertFalse($objectStorageService->isObjectStorageConfigured('pictures'));
        self::assertStringContainsString('unknown driver "gcs"; registered drivers: s3, filesystem', (string)$objectStorageService->getObjectStorageNotConfiguredReason('pictures'));
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('unknown driver "gcs"');
        $objectStorageService->getBasicObjectStorage('pictures');
    }

    public function testADriverClassThatIsNoDriverIsReported(): void
    {
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = [
            'drivers' => ['broken' => self::class],
            'stores' => ['pictures' => ['driver' => 'broken']],
        ];
        $objectStorageService = new FixtureConfigObjectStorageService();

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('does not implement DDD\Infrastructure\ObjectStorage\ObjectStorageDriver');
        $objectStorageService->getObjectStorage('pictures');
    }

    public function testAStoreWithoutADriverStaysSilent(): void
    {
        $objectStorageService = new FixtureConfigObjectStorageService();

        self::assertNull($objectStorageService->getObjectStorage('nothing-here'));
        self::assertStringContainsString('no driver (OBJECT_STORAGE_NOTHING_HERE_DRIVER', (string)$objectStorageService->getObjectStorageNotConfiguredReason('nothing-here'));
        self::assertSame([], $objectStorageService->recordingLogger->loggedLines);
    }

    public function testS3WithoutKeysIsNotConfiguredAndSaysWhy(): void
    {
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = [
            'stores' => ['media' => ['driver' => 's3', 'region' => 'eu-central-1', 'bucket' => 'media']],
        ];
        $this->setEnv('media', ObjectStorageSettings::SETTING_ACCESS_KEY, 'only-the-access-key');
        $objectStorageService = new FixtureConfigObjectStorageService();

        self::assertFalse($objectStorageService->isObjectStorageConfigured('media'));
        $notConfiguredReason = (string)$objectStorageService->getObjectStorageNotConfiguredReason('media');
        self::assertStringContainsString('OBJECT_STORAGE_MEDIA_ACCESS_KEY', $notConfiguredReason);
        self::assertStringContainsString('OBJECT_STORAGE_MEDIA_SECRET_KEY', $notConfiguredReason);
        self::assertStringContainsString('OBJECT_STORAGE_MEDIA_CREDENTIALS=ambient', $notConfiguredReason);
        self::assertNull($objectStorageService->getObjectStorage('media'));
        self::assertNull($objectStorageService->getBasicObjectStorage('media'));
        self::assertCount(1, $objectStorageService->recordingLogger->loggedLines, 'the reason is logged once per process');
        self::assertStringStartsWith('warning Object storage "media": the s3 driver has no credentials', $objectStorageService->recordingLogger->loggedLines[0]);
    }

    public function testS3WithKeysIsConfigured(): void
    {
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = [
            'stores' => ['media' => ['driver' => 's3', 'bucket' => 'media']],
        ];
        $this->setEnv('media', ObjectStorageSettings::SETTING_ACCESS_KEY, 'access');
        $this->setEnv('media', ObjectStorageSettings::SETTING_SECRET_KEY, 'secret');
        $objectStorageService = new FixtureConfigObjectStorageService();

        self::assertTrue($objectStorageService->isObjectStorageConfigured('media'));
        self::assertInstanceOf(S3CompatibleObjectStorage::class, $objectStorageService->getObjectStorage('media'));
    }

    public function testS3WithAmbientCredentialsDeclaredInConfigIsConfigured(): void
    {
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = [
            'stores' => ['media' => ['driver' => 's3', 'bucket' => 'media', 'credentials' => 'ambient']],
        ];
        $objectStorageService = new FixtureConfigObjectStorageService();

        self::assertTrue($objectStorageService->isObjectStorageConfigured('media'));
        self::assertTrue($objectStorageService->getObjectStorageSettings('media')->usesAmbientCredentials());
        self::assertInstanceOf(S3CompatibleObjectStorage::class, $objectStorageService->getObjectStorage('media'));
        self::assertSame([], $objectStorageService->recordingLogger->loggedLines);
    }

    public function testS3WithAmbientCredentialsDeclaredInTheEnvironmentIsConfigured(): void
    {
        FixtureConfigObjectStorageSettings::$committedObjectStorageConfig = [
            'stores' => ['media' => ['driver' => 's3', 'bucket' => 'media']],
        ];
        $this->setEnv('media', ObjectStorageSettings::SETTING_CREDENTIALS, 'Ambient');
        $objectStorageService = new FixtureConfigObjectStorageService();

        self::assertTrue($objectStorageService->isObjectStorageConfigured('media'));
        self::assertInstanceOf(S3CompatibleObjectStorage::class, $objectStorageService->getObjectStorage('media'));
    }
}
