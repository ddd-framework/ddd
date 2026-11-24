<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage;

use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\ObjectStorage\ParallelReadableObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStoragePresignedRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The contract every {@see ObjectStorage} adapter fulfils; one subclass per adapter.
 */
abstract class ObjectStorageContractTestCase extends TestCase
{
    abstract protected function createObjectStorage(): ObjectStorage;

    public function testAnObjectIsWrittenReadListedAndDeleted(): void
    {
        $objectStorage = $this->createObjectStorage();
        $this->assertNull($objectStorage->getObjectContent('sourcemaps/r1/missing.js.map'));
        $this->assertFalse($objectStorage->objectExists('sourcemaps/r1/missing.js.map'));

        $objectStorage->putObject('sourcemaps/r1/_next/static/chunks/b.js.map', '{"b":1}', 'application/json');
        $objectStorage->putObject('sourcemaps/r1/_next/static/chunks/a.js.map', '{"a":1}');
        $objectStorage->putObject('sourcemaps/r2/a.js.map', '{"other":1}');
        $objectStorage->putObject('sourcemaps/r1/_next/static/chunks/a.js.map', '{"a":2}');

        $this->assertSame('{"a":2}', $objectStorage->getObjectContent('sourcemaps/r1/_next/static/chunks/a.js.map'), 'a put replaces');
        $this->assertTrue($objectStorage->objectExists('sourcemaps/r1/_next/static/chunks/b.js.map'));
        $objectStorageObjects = $objectStorage->listObjects('sourcemaps/r1/');
        $this->assertSame(
            ['sourcemaps/r1/_next/static/chunks/a.js.map', 'sourcemaps/r1/_next/static/chunks/b.js.map'],
            $objectStorageObjects->getKeys()
        );
        $this->assertSame(7, $objectStorageObjects->first()->size);
        $this->assertNotNull($objectStorageObjects->first()->lastModified);
        $this->assertCount(1, $objectStorage->listObjects('sourcemaps/r1/', limit: 1)->getKeys());
        $this->assertNotSame('', $objectStorage->createTemporaryUrl('sourcemaps/r1/_next/static/chunks/a.js.map', 60));

        $objectStorage->deleteObject('sourcemaps/r1/_next/static/chunks/a.js.map');
        $objectStorage->deleteObject('sourcemaps/r1/_next/static/chunks/a.js.map');
        $this->assertFalse($objectStorage->objectExists('sourcemaps/r1/_next/static/chunks/a.js.map'));
        $this->assertSame(['sourcemaps/r1/_next/static/chunks/b.js.map'], $objectStorage->listObjects('sourcemaps/r1/')->getKeys());
    }

    public function testSeveralObjectsAreReadAtOnceAndAMissingOneIsNull(): void
    {
        $objectStorage = $this->createObjectStorage();
        $this->assertInstanceOf(ParallelReadableObjectStorage::class, $objectStorage);
        $objectStorage->putObject('images/1/a', 'first');
        $objectStorage->putObject('images/1/b', 'second');

        $this->assertSame(
            ['images/1/a' => 'first', 'images/1/missing' => null, 'images/1/b' => 'second'],
            $objectStorage->getObjectContents(['images/1/a', 'images/1/missing', 'images/1/b'])
        );
        $this->assertSame([], $objectStorage->getObjectContents([]));
    }

    public function testACreateOnlyPutKeepsTheFirstObject(): void
    {
        $objectStorage = $this->createObjectStorage();
        $this->assertTrue($objectStorage->putObjectIfAbsent('blobs/a', 'first', 'text/plain'));
        $this->assertFalse($objectStorage->putObjectIfAbsent('blobs/a', 'second'));
        $this->assertSame('first', $objectStorage->getObjectContent('blobs/a'));
    }

    public function testFilesAreWrittenAndReadWithoutTheContentInMemory(): void
    {
        $objectStorage = $this->createObjectStorage();
        $sourceFilePath = tempnam(sys_get_temp_dir(), 'ddd-os-');
        $targetFilePath = tempnam(sys_get_temp_dir(), 'ddd-os-');
        file_put_contents($sourceFilePath, str_repeat('0123456789', 1000));
        try {
            $objectStorage->putObjectFromFile('files/big.txt', $sourceFilePath, 'text/plain');
            $this->assertTrue($objectStorage->saveObjectToFile('files/big.txt', $targetFilePath));
            $this->assertSame(10000, filesize($targetFilePath));
            $this->assertFalse($objectStorage->saveObjectToFile('files/missing.txt', $targetFilePath));
            $this->assertSame('2345', $objectStorage->getObjectContentRange('files/big.txt', 2, 4));
            $this->assertSame('89', $objectStorage->getObjectContentRange('files/big.txt', 9998, 10));
            $this->assertNull($objectStorage->getObjectContentRange('files/missing.txt', 0, 4));
        } finally {
            @unlink($sourceFilePath);
            @unlink($targetFilePath);
        }
    }

    public function testHeadAnswersSizeWithoutContentAndNullForAMissingObject(): void
    {
        $objectStorage = $this->createObjectStorage();
        $objectStorage->putObject('head/a.json', '{"a":1}', 'application/json');
        $objectStorageObject = $objectStorage->headObject('head/a.json');
        $this->assertNotNull($objectStorageObject);
        $this->assertSame('head/a.json', $objectStorageObject->key);
        $this->assertSame(7, $objectStorageObject->size);
        $this->assertNotNull($objectStorageObject->lastModified);
        $this->assertNull($objectStorage->headObject('head/missing.json'));
    }

    public function testACopyStaysInTheStoreAndACreateOnlyCopyKeepsTheTarget(): void
    {
        $objectStorage = $this->createObjectStorage();
        $objectStorage->putObject('copy/source.txt', 'source');
        $objectStorage->putObject('copy/existing.txt', 'existing');
        $this->assertTrue($objectStorage->copyObject('copy/source.txt', 'copy/target.txt', createOnly: true));
        $this->assertSame('source', $objectStorage->getObjectContent('copy/target.txt'));
        $this->assertFalse($objectStorage->copyObject('copy/source.txt', 'copy/existing.txt', createOnly: true));
        $this->assertSame('existing', $objectStorage->getObjectContent('copy/existing.txt'));
        $this->assertTrue($objectStorage->copyObject('copy/source.txt', 'copy/existing.txt'));
        $this->assertSame('source', $objectStorage->getObjectContent('copy/existing.txt'));
    }

    public function testSeveralObjectsAreDeletedAtOnceAndAListingContinuesAfterAKey(): void
    {
        $objectStorage = $this->createObjectStorage();
        foreach (['list/a', 'list/b', 'list/c', 'list/d'] as $key) {
            $objectStorage->putObject($key, $key);
        }
        $this->assertSame(['list/c', 'list/d'], $objectStorage->listObjects('list/', startAfterKey: 'list/b')->getKeys());
        $objectStorage->deleteObjects(['list/a', 'list/c', 'list/missing']);
        $this->assertSame(['list/b', 'list/d'], $objectStorage->listObjects('list/')->getKeys());
    }

    public function testAPresignedCreateOnlyUploadWritesOnceAndNeverReplaces(): void
    {
        $objectStorage = $this->createObjectStorage();
        $uploadRequest = $objectStorage->createTemporaryUploadRequest('uploads/a.txt', 60, 'text/plain');
        $this->assertSame(ObjectStoragePresignedRequest::METHOD_PUT, $uploadRequest->method);
        $this->assertSame('*', $uploadRequest->headers['If-None-Match'] ?? null);
        $this->assertTrue($this->sendPresignedRequest($uploadRequest, 'first'));
        $this->assertSame('first', $objectStorage->getObjectContent('uploads/a.txt'));
        $this->assertFalse($this->sendPresignedRequest($objectStorage->createTemporaryUploadRequest('uploads/a.txt', 60, 'text/plain'), 'second'));
        $this->assertSame('first', $objectStorage->getObjectContent('uploads/a.txt'));
    }

    public function testAMultipartUploadIsCompletedFromThePartsTheStoreHolds(): void
    {
        $objectStorage = $this->createObjectStorage();
        $uploadId = $objectStorage->createMultipartUpload('multipart/big.bin', 'application/octet-stream');
        $firstPart = str_repeat('a', 5 * 1024 * 1024);
        $this->assertTrue($this->sendPresignedRequest($objectStorage->createTemporaryUploadPartRequest('multipart/big.bin', $uploadId, 1, 60, base64_encode(md5($firstPart, true))), $firstPart));
        $this->assertTrue($this->sendPresignedRequest($objectStorage->createTemporaryUploadPartRequest('multipart/big.bin', $uploadId, 2, 60), 'tail'));
        $uploadedParts = $objectStorage->listUploadedParts('multipart/big.bin', $uploadId);
        $this->assertCount(2, $uploadedParts->getElements());
        $this->assertSame(5 * 1024 * 1024 + 4, $uploadedParts->getTotalSize());

        $this->assertTrue($objectStorage->completeMultipartUpload('multipart/big.bin', $uploadId));

        $this->assertSame(5 * 1024 * 1024 + 4, $objectStorage->headObject('multipart/big.bin')?->size);
        $this->assertSame('atail', $objectStorage->getObjectContentRange('multipart/big.bin', 5 * 1024 * 1024 - 1, 5));
    }

    public function testAMultipartCompletionNeverReplacesAnExistingObject(): void
    {
        $objectStorage = $this->createObjectStorage();
        $objectStorage->putObject('multipart/existing.bin', 'existing');
        $uploadId = $objectStorage->createMultipartUpload('multipart/existing.bin');
        $this->assertTrue($this->sendPresignedRequest($objectStorage->createTemporaryUploadPartRequest('multipart/existing.bin', $uploadId, 1, 60), 'new'));
        $this->assertFalse($objectStorage->completeMultipartUpload('multipart/existing.bin', $uploadId));
        $this->assertSame('existing', $objectStorage->getObjectContent('multipart/existing.bin'));
        $objectStorage->abortMultipartUpload('multipart/existing.bin', $uploadId);
    }

    public function testAPresignedUploadWithAnMd5SignsTheHeader(): void
    {
        $objectStorage = $this->createObjectStorage();
        $contentMd5 = base64_encode(md5('checked', true));
        $uploadRequest = $objectStorage->createTemporaryUploadRequest('uploads/md5.txt', 60, contentMd5: $contentMd5);
        if (!str_starts_with($uploadRequest->url, 'file://')) {
            $this->assertSame($contentMd5, $uploadRequest->headers['Content-MD5'] ?? null);
            $this->assertStringContainsString('content-md5', $uploadRequest->url);
        }
        $this->assertTrue($this->sendPresignedRequest($uploadRequest, 'checked'));
        $this->assertSame('checked', $objectStorage->getObjectContent('uploads/md5.txt'));
    }

    public function testAnAbortedMultipartUploadLeavesNoObject(): void
    {
        $objectStorage = $this->createObjectStorage();
        $uploadId = $objectStorage->createMultipartUpload('multipart/aborted.bin');
        $this->assertTrue($this->sendPresignedRequest($objectStorage->createTemporaryUploadPartRequest('multipart/aborted.bin', $uploadId, 1, 60), 'x'));
        $objectStorage->abortMultipartUpload('multipart/aborted.bin', $uploadId);
        $objectStorage->abortMultipartUpload('multipart/aborted.bin', $uploadId);
        $this->assertFalse($objectStorage->objectExists('multipart/aborted.bin'));
        $this->assertSame([], $objectStorage->listObjects('multipart/')->getKeys());
    }

    /** Sends a presigned request as a client without credentials would; true on success, false when the store refused it */
    protected function sendPresignedRequest(ObjectStoragePresignedRequest $presignedRequest, string $body): bool
    {
        if (str_starts_with($presignedRequest->url, 'file://')) {
            $filePath = substr($presignedRequest->url, strlen('file://'));
            if (($presignedRequest->headers['If-None-Match'] ?? null) === '*' && is_file($filePath)) {
                return false;
            }
            return file_put_contents($filePath, $body) !== false;
        }
        $response = HttpClient::create()->request($presignedRequest->method, $presignedRequest->url, [
            'headers' => $presignedRequest->headers,
            'body' => $body,
        ]);
        return $response->getStatusCode() < 300;
    }
}
