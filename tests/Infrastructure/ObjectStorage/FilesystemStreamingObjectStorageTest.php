<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorageByteRange;
use PHPUnit\Framework\TestCase;

/**
 * The streaming side of the filesystem driver: chunked and ranged reads, writes and parts from a stream, the list of
 * open multipart uploads.
 */
class FilesystemStreamingObjectStorageTest extends TestCase
{
    protected string $rootDirectory;

    protected function setUp(): void
    {
        $this->rootDirectory = sys_get_temp_dir() . '/ddd-object-storage-stream-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->rootDirectory)) {
            exec('rm -r ' . escapeshellarg($this->rootDirectory));
        }
    }

    protected function createObjectStorage(): FilesystemObjectStorage
    {
        return new FilesystemObjectStorage($this->rootDirectory, 'tenant/');
    }

    /** @return resource */
    protected function createStream(string $content): mixed
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
    }

    public function testChunksOfTheWholeObjectAndOfARange(): void
    {
        $objectStorage = $this->createObjectStorage();
        $objectStorage->putObject('files/a.bin', 'abcdefghij');

        self::assertSame(['abcd', 'efgh', 'ij'], iterator_to_array($objectStorage->readObjectChunks('files/a.bin', null, 4), false));
        self::assertSame(['cde', 'f'], iterator_to_array($objectStorage->readObjectChunks('files/a.bin', ObjectStorageByteRange::fromFirstAndLastByte(2, 5), 3), false));
        self::assertSame('hij', implode('', iterator_to_array($objectStorage->readObjectChunks('files/a.bin', ObjectStorageByteRange::fromFirstAndLastByte(7, 99)), false)), 'cut at the end');
        self::assertSame('ghij', implode('', iterator_to_array($objectStorage->readObjectChunks('files/a.bin', ObjectStorageByteRange::fromFirstAndLastByte(6)), false)), 'open range');
        self::assertSame([], $objectStorage->readObjectChunks('files/a.bin', ObjectStorageByteRange::fromFirstAndLastByte(10)), 'beyond the end');
        self::assertNull($objectStorage->readObjectChunks('files/missing.bin'));
    }

    public function testPutFromAStream(): void
    {
        $objectStorage = $this->createObjectStorage();
        $objectStorage->putObjectFromStream('files/b.bin', $this->createStream('streamed bytes'), 14, 'application/octet-stream');
        self::assertSame('streamed bytes', $objectStorage->getObjectContent('files/b.bin'));
    }

    public function testAStreamOfAnotherLengthIsRefused(): void
    {
        $objectStorage = $this->createObjectStorage();
        try {
            $objectStorage->putObjectFromStream('files/c.bin', $this->createStream('short'), 10);
            self::fail('a short stream must be refused');
        } catch (InternalErrorException) {
            self::assertFalse($objectStorage->objectExists('files/c.bin'));
        }
    }

    public function testPartsFromStreamsAreCompletedAndARepeatedPartReplacesTheFirst(): void
    {
        $objectStorage = $this->createObjectStorage();
        $uploadId = $objectStorage->createMultipartUpload('files/big.bin');
        $objectStorage->uploadPartFromStream('files/big.bin', $uploadId, 1, $this->createStream('broken'), 6);
        $firstPart = $objectStorage->uploadPartFromStream('files/big.bin', $uploadId, 1, $this->createStream('first-'), 6, base64_encode(md5('first-', true)));
        $objectStorage->uploadPartFromStream('files/big.bin', $uploadId, 2, $this->createStream('second'), 6);
        self::assertSame(md5('first-'), $firstPart->etag);
        self::assertSame(6, $firstPart->size);

        $uploadedParts = $objectStorage->listUploadedParts('files/big.bin', $uploadId);
        self::assertCount(2, $uploadedParts->getElements(), 'the key file is no part');
        self::assertTrue($objectStorage->completeMultipartUpload('files/big.bin', $uploadId, $uploadedParts));
        self::assertSame('first-second', $objectStorage->getObjectContent('files/big.bin'));
    }

    public function testAPartWithAWrongMd5IsRefused(): void
    {
        $objectStorage = $this->createObjectStorage();
        $uploadId = $objectStorage->createMultipartUpload('files/big.bin');
        $this->expectException(InternalErrorException::class);
        $objectStorage->uploadPartFromStream('files/big.bin', $uploadId, 1, $this->createStream('bytes'), 5, base64_encode(md5('other', true)));
    }

    public function testOpenUploadsAreListedAndGoneAfterTheAbort(): void
    {
        $objectStorage = $this->createObjectStorage();
        $firstUploadId = $objectStorage->createMultipartUpload('space-1/a.bin');
        $secondUploadId = $objectStorage->createMultipartUpload('space-2/b.bin');

        $multipartUploads = $objectStorage->listMultipartUploads();
        self::assertCount(2, $multipartUploads->getElements());
        self::assertSame('space-1/a.bin', $multipartUploads->getByUniqueKey(\DDD\Infrastructure\ObjectStorage\ObjectStorageMultipartUpload::uniqueKeyStatic($firstUploadId))?->key);
        self::assertNotNull($multipartUploads->first()?->initiatedAt);
        self::assertCount(1, $objectStorage->listMultipartUploads('space-2/')->getElements());

        $objectStorage->abortMultipartUpload('space-1/a.bin', $firstUploadId);
        $remainingUploads = $objectStorage->listMultipartUploads();
        self::assertCount(1, $remainingUploads->getElements());
        self::assertSame($secondUploadId, $remainingUploads->first()?->uploadId);
    }
}
