<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage\Fixtures;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\ObjectStorageDriver;
use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use Psr\Log\LoggerInterface;

/** An application driver that implements only the narrow contract and reads its own option `gatewayUrl` */
class InMemoryPictureObjectStorage implements ObjectStorageDriver
{
    /** @var string[] Key => content */
    public array $contentsByKey = [];

    public function __construct(public string $gatewayUrl)
    {
    }

    public static function fromSettings(ObjectStorageSettings $objectStorageSettings, ?LoggerInterface $logger = null): static
    {
        $gatewayUrl = $objectStorageSettings->getOption('gatewayUrl');
        if ($gatewayUrl === null) {
            throw new InternalErrorException(sprintf('Object storage "%s": the picture driver needs gatewayUrl', $objectStorageSettings->name));
        }
        return new static($gatewayUrl);
    }

    public function putObject(string $key, string $content, ?string $contentType = null): void
    {
        $this->contentsByKey[$key] = $content;
    }

    public function getObjectContent(string $key): ?string
    {
        return $this->contentsByKey[$key] ?? null;
    }

    public function deleteObject(string $key): void
    {
        unset($this->contentsByKey[$key]);
    }

    public function objectExists(string $key): bool
    {
        return isset($this->contentsByKey[$key]);
    }
}
