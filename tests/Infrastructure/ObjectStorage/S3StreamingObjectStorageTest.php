<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage;

use DDD\Infrastructure\ObjectStorage\ObjectStorageByteRange;
use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use DDD\Tests\Infrastructure\ObjectStorage\Fixtures\MockHttpClientS3CompatibleObjectStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The streaming side of the S3 driver against a fake transport: a ranged read asks the store for the range only, a
 * part goes up from a stream, open uploads are listed with the store's prefix removed.
 */
class S3StreamingObjectStorageTest extends TestCase
{
    /** @var string[] */
    protected array $recordedRequests = [];

    protected function createObjectStorage(MockHttpClient $mockHttpClient): MockHttpClientS3CompatibleObjectStorage
    {
        $objectStorage = new MockHttpClientS3CompatibleObjectStorage(new ObjectStorageSettings(
            name: 'stream-test',
            driver: ObjectStorageSettings::DRIVER_S3,
            endpoint: 'http://127.0.0.1:9',
            bucket: 'files',
            accessKey: 'ddd-test',
            secretKey: 'ddd-test-secret',
            prefix: 'tenant/',
            pathStyle: true,
            timeoutSeconds: 5.0,
            maxRetries: 0,
        ));
        $objectStorage->mockHttpClient = $mockHttpClient;
        return $objectStorage;
    }

    public function testARangedReadAsksForTheRangeOnly(): void
    {
        $recordedRanges = [];
        $mockHttpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$recordedRanges): MockResponse {
            foreach ($options['headers'] as $header) {
                if (stripos($header, 'range:') === 0) {
                    $recordedRanges[] = trim(substr($header, 6));
                }
            }
            return new MockResponse(['cdef', 'gh'], ['http_code' => 206, 'response_headers' => ['content-range' => 'bytes 2-7/100', 'content-length' => '6']]);
        });
        $objectStorage = $this->createObjectStorage($mockHttpClient);

        $chunks = $objectStorage->readObjectChunks('files/a.bin', ObjectStorageByteRange::fromFirstAndLastByte(2, 7), 3);
        self::assertSame(['cde', 'fgh'], iterator_to_array($chunks, false));
        self::assertSame(['bytes=2-7'], $recordedRanges);
    }

    public function testAMissingObjectIsNullAndAnUnsatisfiableRangeEmpty(): void
    {
        $statusCodes = [404, 416];
        $mockHttpClient = new MockHttpClient(static function () use (&$statusCodes): MockResponse {
            $statusCode = array_shift($statusCodes);
            return new MockResponse('<?xml version="1.0"?><Error><Code>' . ($statusCode === 404 ? 'NoSuchKey' : 'InvalidRange') . '</Code></Error>', ['http_code' => $statusCode]);
        });
        $objectStorage = $this->createObjectStorage($mockHttpClient);
        self::assertNull($objectStorage->readObjectChunks('files/missing.bin'));
        self::assertSame([], $objectStorage->readObjectChunks('files/a.bin', ObjectStorageByteRange::fromFirstAndLastByte(500)));
    }

    public function testAPartGoesUpFromAStream(): void
    {
        $recordedRequest = [];
        $mockHttpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$recordedRequest): MockResponse {
            $body = $options['body'];
            $recordedRequest = ['method' => $method, 'url' => $url, 'body' => is_string($body) ? $body : implode('', iterator_to_array((static fn () => yield from (is_callable($body) ? (static function () use ($body) { while (($chunk = $body(65536)) !== '') { yield $chunk; } })() : $body))(), false))];
            return new MockResponse('', ['http_code' => 200, 'response_headers' => ['etag' => '"part-etag"']]);
        });
        $objectStorage = $this->createObjectStorage($mockHttpClient);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'part bytes');
        rewind($stream);

        $uploadedPart = $objectStorage->uploadPartFromStream('files/big.bin', 'upload-1', 3, $stream, 10);
        self::assertSame('PUT', $recordedRequest['method']);
        self::assertStringContainsString('/files/tenant/files/big.bin', $recordedRequest['url']);
        self::assertStringContainsString('partNumber=3', $recordedRequest['url']);
        self::assertStringContainsString('part bytes', $recordedRequest['body']);
        self::assertSame('part-etag', $uploadedPart->etag);
        self::assertSame(10, $uploadedPart->size);
    }

    public function testOpenUploadsAreListedWithoutTheStorePrefix(): void
    {
        $mockHttpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '<?xml version="1.0" encoding="UTF-8"?><ListMultipartUploadsResult><Bucket>files</Bucket><IsTruncated>false</IsTruncated>'
            . '<Upload><Key>tenant/space-1/a.bin</Key><UploadId>upload-1</UploadId><Initiated>2026-11-20T10:00:00.000Z</Initiated></Upload>'
            . '</ListMultipartUploadsResult>',
            ['http_code' => 200]
        ));
        $multipartUploads = $this->createObjectStorage($mockHttpClient)->listMultipartUploads('space-1/');
        self::assertCount(1, $multipartUploads->getElements());
        self::assertSame('space-1/a.bin', $multipartUploads->first()?->key);
        self::assertSame('upload-1', $multipartUploads->first()?->uploadId);
        self::assertNotNull($multipartUploads->first()?->initiatedAt);
    }
}
