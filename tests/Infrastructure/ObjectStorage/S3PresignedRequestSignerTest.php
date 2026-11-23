<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage;

use DDD\Infrastructure\ObjectStorage\ObjectStoragePresignedRequest;
use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use DDD\Infrastructure\ObjectStorage\S3PresignedRequestSigner;
use PHPUnit\Framework\TestCase;

class S3PresignedRequestSignerTest extends TestCase
{
    public function testTheConditionAndTheContentTypeAreSignedHeaders(): void
    {
        $presignedRequest = $this->createSigner(pathStyle: true)->createPresignedRequest(
            ObjectStoragePresignedRequest::METHOD_PUT,
            'drive/blobs/ab cd',
            900,
            headers: ['If-None-Match' => '*', 'Content-Type' => 'text/plain'],
            nowTimestamp: 1795000000
        );

        $this->assertStringStartsWith('https://hel1.example-objectstorage.test/bucket/drive/blobs/ab%20cd?', $presignedRequest->url);
        $this->assertStringContainsString('X-Amz-SignedHeaders=content-type%3Bhost%3Bif-none-match', $presignedRequest->url);
        $this->assertStringContainsString('X-Amz-Expires=900', $presignedRequest->url);
        $this->assertMatchesRegularExpression('/&X-Amz-Signature=[0-9a-f]{64}$/', $presignedRequest->url);
        $this->assertSame(['If-None-Match' => '*', 'Content-Type' => 'text/plain'], $presignedRequest->headers);
        $this->assertSame(1795000900, $presignedRequest->expiresAt->getTimestamp());
    }

    public function testVirtualHostedStylePutsTheBucketIntoTheHostAndSignsQueryParameters(): void
    {
        $presignedRequest = $this->createSigner(pathStyle: false)->createPresignedRequest(
            ObjectStoragePresignedRequest::METHOD_GET,
            'a.pdf',
            60,
            ['response-content-disposition' => 'attachment; filename="a.pdf"']
        );

        $this->assertStringStartsWith('https://bucket.hel1.example-objectstorage.test/a.pdf?', $presignedRequest->url);
        $this->assertStringContainsString('response-content-disposition=attachment%3B%20filename%3D%22a.pdf%22', $presignedRequest->url);
        $this->assertStringContainsString('X-Amz-SignedHeaders=host', $presignedRequest->url);
    }

    protected function createSigner(bool $pathStyle): S3PresignedRequestSigner
    {
        return new S3PresignedRequestSigner(new ObjectStorageSettings(
            name: 'test',
            driver: ObjectStorageSettings::DRIVER_S3,
            endpoint: 'https://hel1.example-objectstorage.test',
            region: 'hel1',
            bucket: 'bucket',
            accessKey: 'key',
            secretKey: 'secret',
            pathStyle: $pathStyle,
        ));
    }
}
