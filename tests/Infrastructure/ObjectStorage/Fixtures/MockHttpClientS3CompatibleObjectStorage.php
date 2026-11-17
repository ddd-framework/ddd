<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage\Fixtures;

use Closure;
use DDD\Infrastructure\ObjectStorage\S3CompatibleObjectStorage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** An S3 store whose transport is a MockHttpClient; exposes the HTTP client the S3 client gets */
class MockHttpClientS3CompatibleObjectStorage extends S3CompatibleObjectStorage
{
    public ?MockHttpClient $mockHttpClient = null;

    /** @var (Closure(): MockHttpClient)|null Builds a new transport per base client (a new connection); wins over $mockHttpClient */
    public ?Closure $mockHttpClientFactory = null;

    protected function createBaseHttpClient(): HttpClientInterface
    {
        return $this->mockHttpClientFactory !== null ? ($this->mockHttpClientFactory)() : $this->mockHttpClient;
    }

    public function getHttpClientForS3Client(): ?HttpClientInterface
    {
        return $this->createHttpClient();
    }
}
