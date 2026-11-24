<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\Exception\NoSuchUploadException;
use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\Core\AwsError\XmlAwsErrorFactory;
use AsyncAws\Core\HttpClient\AwsRetryStrategy;
use AsyncAws\S3\S3Client;
use DateTimeImmutable;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * An object store in any S3-compatible service — AWS S3, Hetzner Object Storage, MinIO, Cloudflare R2; only the
 * settings differ (endpoint, region, path style). Built on `async-aws/s3`, which the application requires
 * (`composer require async-aws/s3`): Core only suggests it.
 *
 * async-aws retries a throttled or failed request itself (3 retries, 1 s / 2 s / 4 s: transport errors, 429, 5xx, and
 * SlowDown / Throttling answers); with a logger each retry is logged at level info ("Try #n after …ms").
 *
 * Per store (see {@see ObjectStorageSettings}): `timeoutSeconds` (the HTTP client's `timeout`, idle time of one attempt),
 * `maxDurationSeconds` (its `max_duration`), `maxRetries` (the RetryableHttpClient's retry count) and `httpVersion`
 * (`1.1` / `2.0`, its `http_version`) apply to every operation; a retry after a transport error uses a new connection.
 * Unset, async-aws builds its own client: default socket timeout (usually 60 s), 3 retries.
 */
class S3CompatibleObjectStorage implements StreamingObjectStorage, ParallelReadableObjectStorage
{
    public const string REQUIRED_PACKAGE = 'async-aws/s3';
    /** async-aws's own retry count, kept when a store sets only a timeout */
    public const int DEFAULT_MAX_RETRIES = 3;
    /** The largest object a single CopyObject request copies; above it the copy runs as a multipart copy */
    public const int MAX_SINGLE_COPY_BYTES = 5368709120;
    /** Part size of a multipart copy */
    public const int MULTIPART_COPY_PART_BYTES = 1073741824;
    /** Keys per DeleteObjects request */
    public const int MAX_KEYS_PER_DELETE = 1000;
    public const int HTTP_STATUS_NOT_FOUND = 404;
    public const int HTTP_STATUS_CONFLICT = 409;
    public const int HTTP_STATUS_PRECONDITION_FAILED = 412;
    public const int HTTP_STATUS_RANGE_NOT_SATISFIABLE = 416;

    protected ?S3Client $s3Client = null;

    public function __construct(
        protected ObjectStorageSettings $objectStorageSettings,
        protected ?LoggerInterface $logger = null
    ) {
        if (!class_exists(S3Client::class)) {
            throw new InternalErrorException(sprintf(
                'Object storage "%s" uses the s3 driver, which needs the package %s: composer require %s',
                $objectStorageSettings->name,
                self::REQUIRED_PACKAGE,
                self::REQUIRED_PACKAGE
            ));
        }
        if ($objectStorageSettings->bucket === null) {
            throw new InternalErrorException(sprintf(
                'Object storage "%s": the s3 driver needs %s',
                $objectStorageSettings->name,
                ObjectStorageSettings::getEnvName($objectStorageSettings->name, ObjectStorageSettings::SETTING_BUCKET)
            ));
        }
    }

    public function putObject(string $key, string $content, ?string $contentType = null): void
    {
        try {
            $this->getS3Client()->putObject(array_filter([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
                'Body' => $content,
                'ContentType' => $contentType,
            ], static fn(mixed $value): bool => $value !== null))->resolve();
        } catch (Throwable $throwable) {
            throw $this->createStorageException('put', $key, $throwable);
        }
    }

    public function getObjectContent(string $key): ?string
    {
        try {
            return $this->getS3Client()->getObject([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
            ])->getBody()->getContentAsString();
        } catch (NoSuchKeyException) {
            return null;
        } catch (Throwable $throwable) {
            throw $this->createStorageException('get', $key, $throwable);
        }
    }

    /**
     * Every GET is sent before the first answer is read: async-aws starts a request when it is created and the HTTP
     * client keeps all of them in flight, so N objects cost about the time of the slowest one.
     */
    public function getObjectContents(array $keys): array
    {
        $getObjectResultsByKey = [];
        foreach (array_values(array_unique($keys)) as $key) {
            try {
                $getObjectResultsByKey[$key] = $this->getS3Client()->getObject([
                    'Bucket' => $this->objectStorageSettings->bucket,
                    'Key' => $this->getStorageKey($key),
                ]);
            } catch (Throwable $throwable) {
                throw $this->createStorageException('get', $key, $throwable);
            }
        }
        $contentsByKey = [];
        foreach ($getObjectResultsByKey as $key => $getObjectResult) {
            try {
                $contentsByKey[$key] = $getObjectResult->getBody()->getContentAsString();
            } catch (NoSuchKeyException) {
                $contentsByKey[$key] = null;
            } catch (Throwable $throwable) {
                // a 404 without the NoSuchKey code (some S3-compatible services) is a missing object too, as in readObjectChunks()
                if ($this->getHttpStatusCode($throwable) === self::HTTP_STATUS_NOT_FOUND) {
                    $contentsByKey[$key] = null;
                    continue;
                }
                throw $this->createStorageException('get', (string)$key, $throwable);
            }
        }
        return $contentsByKey;
    }

    public function deleteObject(string $key): void
    {
        try {
            $this->getS3Client()->deleteObject([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
            ])->resolve();
        } catch (Throwable $throwable) {
            throw $this->createStorageException('delete', $key, $throwable);
        }
    }

    public function objectExists(string $key): bool
    {
        try {
            return $this->getS3Client()->objectExists([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
            ])->isSuccess();
        } catch (Throwable $throwable) {
            throw $this->createStorageException('check', $key, $throwable);
        }
    }

    public function listObjects(string $keyPrefix = '', int $limit = 1000, ?string $startAfterKey = null): ObjectStorageObjects
    {
        $objectStorageObjects = new ObjectStorageObjects();
        try {
            $listObjectsRequest = [
                'Bucket' => $this->objectStorageSettings->bucket,
                'Prefix' => $this->getStorageKey($keyPrefix, allowEmpty: true),
            ];
            if ($startAfterKey !== null && $startAfterKey !== '') {
                $listObjectsRequest['StartAfter'] = $this->getStorageKey($startAfterKey);
            }
            $listObjectsOutput = $this->getS3Client()->listObjectsV2($listObjectsRequest);
            foreach ($listObjectsOutput->getContents() as $awsObject) {
                if ($objectStorageObjects->count() >= $limit) {
                    break;
                }
                $objectStorageObject = new ObjectStorageObject();
                $objectStorageObject->key = substr((string)$awsObject->getKey(), strlen($this->objectStorageSettings->prefix));
                $objectStorageObject->size = $awsObject->getSize();
                $objectStorageObject->lastModified = $awsObject->getLastModified() !== null
                    ? DateTime::fromTimestamp($awsObject->getLastModified()->getTimestamp())
                    : null;
                $objectStorageObjects->add($objectStorageObject);
            }
        } catch (Throwable $throwable) {
            throw $this->createStorageException('list', $keyPrefix, $throwable);
        }
        return $objectStorageObjects;
    }

    public function createTemporaryUrl(
        string $key,
        int $expiresInSeconds = 3600,
        ?string $responseContentType = null,
        ?string $responseContentDisposition = null
    ): string {
        $queryParameters = [];
        if ($responseContentType !== null) {
            $queryParameters['response-content-type'] = $responseContentType;
        }
        if ($responseContentDisposition !== null) {
            $queryParameters['response-content-disposition'] = $responseContentDisposition;
        }
        try {
            if ($queryParameters === [] || !$this->hasCredentials()) {
                return $this->getS3Client()->presign(
                    new GetObjectRequest(array_merge(
                        ['Bucket' => $this->objectStorageSettings->bucket, 'Key' => $this->getStorageKey($key)],
                        $responseContentType !== null ? ['ResponseContentType' => $responseContentType] : [],
                        $responseContentDisposition !== null ? ['ResponseContentDisposition' => $responseContentDisposition] : []
                    )),
                    new DateTimeImmutable('+' . max(1, $expiresInSeconds) . ' seconds')
                );
            }
            return $this->createPresignedRequestSigner()->createPresignedRequest(
                ObjectStoragePresignedRequest::METHOD_GET,
                $this->getStorageKey($key),
                $expiresInSeconds,
                $queryParameters
            )->url;
        } catch (Throwable $throwable) {
            throw $this->createStorageException('presign', $key, $throwable);
        }
    }

    public function putObjectIfAbsent(string $key, string $content, ?string $contentType = null): bool
    {
        $putObjectRequest = [
            'Bucket' => $this->objectStorageSettings->bucket,
            'Key' => $this->getStorageKey($key),
            'Body' => $content,
            'IfNoneMatch' => '*',
        ];
        if ($contentType !== null) {
            $putObjectRequest['ContentType'] = $contentType;
        }
        try {
            $this->getS3Client()->putObject($putObjectRequest)->resolve();
            return true;
        } catch (Throwable $throwable) {
            if ($this->isConditionFailure($throwable)) {
                return false;
            }
            throw $this->createStorageException('put', $key, $throwable);
        }
    }

    public function putObjectFromFile(string $key, string $filePath, ?string $contentType = null): void
    {
        $fileHandle = @fopen($filePath, 'rb');
        if ($fileHandle === false) {
            throw new InternalErrorException(sprintf('Object storage "%s": cannot read %s', $this->objectStorageSettings->name, $filePath));
        }
        $putObjectRequest = [
            'Bucket' => $this->objectStorageSettings->bucket,
            'Key' => $this->getStorageKey($key),
            'Body' => $fileHandle,
            'ContentLength' => (int)filesize($filePath),
        ];
        if ($contentType !== null) {
            $putObjectRequest['ContentType'] = $contentType;
        }
        try {
            $this->getS3Client()->putObject($putObjectRequest)->resolve();
        } catch (Throwable $throwable) {
            throw $this->createStorageException('put', $key, $throwable);
        } finally {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
        }
    }

    public function saveObjectToFile(string $key, string $filePath): bool
    {
        try {
            $getObjectOutput = $this->getS3Client()->getObject([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
            ]);
            $fileHandle = @fopen($filePath, 'wb');
            if ($fileHandle === false) {
                throw new InternalErrorException('cannot write ' . $filePath);
            }
            try {
                foreach ($getObjectOutput->getBody()->getChunks() as $chunk) {
                    fwrite($fileHandle, $chunk);
                }
            } finally {
                fclose($fileHandle);
            }
            return true;
        } catch (NoSuchKeyException) {
            return false;
        } catch (Throwable $throwable) {
            if ($this->getHttpStatusCode($throwable) === self::HTTP_STATUS_NOT_FOUND) {
                return false;
            }
            throw $this->createStorageException('get', $key, $throwable);
        }
    }

    public function getObjectContentRange(string $key, int $offset, int $length): ?string
    {
        if ($length <= 0) {
            return $this->objectExists($key) ? '' : null;
        }
        try {
            return $this->getS3Client()->getObject([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
                'Range' => 'bytes=' . max(0, $offset) . '-' . (max(0, $offset) + $length - 1),
            ])->getBody()->getContentAsString();
        } catch (NoSuchKeyException) {
            return null;
        } catch (Throwable $throwable) {
            if ($this->getHttpStatusCode($throwable) === self::HTTP_STATUS_RANGE_NOT_SATISFIABLE) {
                return '';
            }
            if ($this->getHttpStatusCode($throwable) === self::HTTP_STATUS_NOT_FOUND) {
                return null;
            }
            throw $this->createStorageException('get range', $key, $throwable);
        }
    }

    public function headObject(string $key): ?ObjectStorageObject
    {
        try {
            $headObjectOutput = $this->getS3Client()->headObject([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
                'ChecksumMode' => 'ENABLED',
            ]);
            $headObjectOutput->resolve();
            $objectStorageObject = new ObjectStorageObject();
            $objectStorageObject->key = ltrim($key, '/');
            $objectStorageObject->size = $headObjectOutput->getContentLength();
            $objectStorageObject->contentType = $headObjectOutput->getContentType();
            $objectStorageObject->etag = $headObjectOutput->getEtag() !== null ? trim($headObjectOutput->getEtag(), '"') : null;
            $objectStorageObject->checksumSha256 = $headObjectOutput->getChecksumSha256();
            $objectStorageObject->lastModified = $headObjectOutput->getLastModified() !== null
                ? DateTime::fromTimestamp($headObjectOutput->getLastModified()->getTimestamp())
                : null;
            return $objectStorageObject;
        } catch (NoSuchKeyException) {
            return null;
        } catch (Throwable $throwable) {
            if ($this->getHttpStatusCode($throwable) === self::HTTP_STATUS_NOT_FOUND) {
                return null;
            }
            throw $this->createStorageException('head', $key, $throwable);
        }
    }

    public function copyObject(string $sourceKey, string $targetKey, bool $createOnly = false): bool
    {
        $sourceObject = $this->headObject($sourceKey);
        if ($sourceObject === null) {
            throw new InternalErrorException(sprintf(
                'Object storage "%s": copy of "%s" failed: no such object',
                $this->objectStorageSettings->name,
                $sourceKey
            ));
        }
        // Not every store honours If-None-Match on a copy (SeaweedFS ignores it): look first, then copy conditionally
        if ($createOnly && $this->objectExists($targetKey)) {
            return false;
        }
        if (($sourceObject->size ?? 0) > self::MAX_SINGLE_COPY_BYTES) {
            return $this->copyObjectInParts($sourceKey, $targetKey, (int)$sourceObject->size, $sourceObject->contentType, $createOnly);
        }
        $copyObjectRequest = [
            'Bucket' => $this->objectStorageSettings->bucket,
            'Key' => $this->getStorageKey($targetKey),
            'CopySource' => $this->getCopySource($sourceKey),
        ];
        if ($createOnly) {
            $copyObjectRequest['IfNoneMatch'] = '*';
        }
        try {
            $this->getS3Client()->copyObject($copyObjectRequest)->resolve();
            return true;
        } catch (Throwable $throwable) {
            if ($createOnly && $this->isConditionFailure($throwable)) {
                return false;
            }
            throw $this->createStorageException('copy to "' . $targetKey . '"', $sourceKey, $throwable);
        }
    }

    /** A copy above the single-request limit: a multipart upload whose parts are server-side copies of byte ranges */
    protected function copyObjectInParts(string $sourceKey, string $targetKey, int $size, ?string $contentType, bool $createOnly): bool
    {
        $uploadId = $this->createMultipartUpload($targetKey, $contentType);
        try {
            $uploadedParts = new ObjectStorageUploadedParts();
            $partNumber = 1;
            for ($rangeStart = 0; $rangeStart < $size; $rangeStart += self::MULTIPART_COPY_PART_BYTES) {
                $rangeEnd = min($size, $rangeStart + self::MULTIPART_COPY_PART_BYTES) - 1;
                $uploadPartCopyOutput = $this->getS3Client()->uploadPartCopy([
                    'Bucket' => $this->objectStorageSettings->bucket,
                    'Key' => $this->getStorageKey($targetKey),
                    'UploadId' => $uploadId,
                    'PartNumber' => $partNumber,
                    'CopySource' => $this->getCopySource($sourceKey),
                    'CopySourceRange' => 'bytes=' . $rangeStart . '-' . $rangeEnd,
                ]);
                $uploadedPart = new ObjectStorageUploadedPart();
                $uploadedPart->partNumber = $partNumber;
                $uploadedPart->etag = trim((string)$uploadPartCopyOutput->getCopyPartResult()?->getEtag(), '"');
                $uploadedPart->size = $rangeEnd - $rangeStart + 1;
                $uploadedParts->add($uploadedPart);
                $partNumber++;
            }
            $completed = $this->completeMultipartUpload($targetKey, $uploadId, $uploadedParts, $createOnly);
        } catch (Throwable $throwable) {
            $this->abortMultipartUpload($targetKey, $uploadId);
            throw $throwable instanceof InternalErrorException
                ? $throwable
                : $this->createStorageException('copy to "' . $targetKey . '"', $sourceKey, $throwable);
        }
        if (!$completed) {
            $this->abortMultipartUpload($targetKey, $uploadId);
        }
        return $completed;
    }

    public function deleteObjects(array $keys): void
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), 'strlen')));
        foreach (array_chunk($keys, self::MAX_KEYS_PER_DELETE) as $keyChunk) {
            $objectIdentifiers = [];
            foreach ($keyChunk as $key) {
                $objectIdentifiers[] = ['Key' => $this->getStorageKey($key)];
            }
            try {
                $this->getS3Client()->deleteObjects([
                    'Bucket' => $this->objectStorageSettings->bucket,
                    'Delete' => ['Objects' => $objectIdentifiers, 'Quiet' => true],
                ])->resolve();
            } catch (Throwable $throwable) {
                throw $this->createStorageException('delete', implode('", "', array_slice($keyChunk, 0, 3)) . (count($keyChunk) > 3 ? '" …' : ''), $throwable);
            }
        }
    }

    public function createTemporaryUploadRequest(
        string $key,
        int $expiresInSeconds = 3600,
        ?string $contentType = null,
        bool $createOnly = true,
        ?string $checksumSha256 = null,
        ?string $contentMd5 = null
    ): ObjectStoragePresignedRequest {
        $headers = [];
        if ($contentMd5 !== null) {
            $headers['Content-MD5'] = $contentMd5;
        }
        if ($createOnly && $this->supportsConditionalWrites()) {
            $headers['If-None-Match'] = '*';
        }
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        if ($checksumSha256 !== null) {
            $headers['x-amz-checksum-sha256'] = $checksumSha256;
        }
        try {
            return $this->createPresignedRequestSigner()->createPresignedRequest(
                ObjectStoragePresignedRequest::METHOD_PUT,
                $this->getStorageKey($key),
                $expiresInSeconds,
                headers: $headers
            );
        } catch (Throwable $throwable) {
            throw $throwable instanceof InternalErrorException ? $throwable : $this->createStorageException('presign upload', $key, $throwable);
        }
    }

    public function createMultipartUpload(string $key, ?string $contentType = null): string
    {
        $createMultipartUploadRequest = [
            'Bucket' => $this->objectStorageSettings->bucket,
            'Key' => $this->getStorageKey($key),
        ];
        if ($contentType !== null) {
            $createMultipartUploadRequest['ContentType'] = $contentType;
        }
        try {
            $uploadId = $this->getS3Client()->createMultipartUpload($createMultipartUploadRequest)->getUploadId();
        } catch (Throwable $throwable) {
            throw $this->createStorageException('start multipart upload', $key, $throwable);
        }
        if ($uploadId === null || $uploadId === '') {
            throw new InternalErrorException(sprintf('Object storage "%s": start multipart upload of "%s" failed: no upload id', $this->objectStorageSettings->name, $key));
        }
        return $uploadId;
    }

    public function createTemporaryUploadPartRequest(
        string $key,
        string $uploadId,
        int $partNumber,
        int $expiresInSeconds = 3600,
        ?string $contentMd5 = null
    ): ObjectStoragePresignedRequest {
        try {
            return $this->createPresignedRequestSigner()->createPresignedRequest(
                ObjectStoragePresignedRequest::METHOD_PUT,
                $this->getStorageKey($key),
                $expiresInSeconds,
                ['partNumber' => (string)$partNumber, 'uploadId' => $uploadId],
                $contentMd5 !== null ? ['Content-MD5' => $contentMd5] : []
            );
        } catch (Throwable $throwable) {
            throw $throwable instanceof InternalErrorException ? $throwable : $this->createStorageException('presign part', $key, $throwable);
        }
    }

    public function listUploadedParts(string $key, string $uploadId): ObjectStorageUploadedParts
    {
        $uploadedParts = new ObjectStorageUploadedParts();
        try {
            $listPartsOutput = $this->getS3Client()->listParts([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
                'UploadId' => $uploadId,
            ]);
            $partsByNumber = [];
            foreach ($listPartsOutput->getParts() as $awsPart) {
                $uploadedPart = new ObjectStorageUploadedPart();
                $uploadedPart->partNumber = (int)$awsPart->getPartNumber();
                $uploadedPart->etag = trim((string)$awsPart->getEtag(), '"');
                $uploadedPart->size = $awsPart->getSize();
                $partsByNumber[$uploadedPart->partNumber] = $uploadedPart;
            }
            ksort($partsByNumber);
            foreach ($partsByNumber as $uploadedPart) {
                $uploadedParts->add($uploadedPart);
            }
        } catch (Throwable $throwable) {
            throw $this->createStorageException('list parts', $key, $throwable);
        }
        return $uploadedParts;
    }

    public function completeMultipartUpload(
        string $key,
        string $uploadId,
        ?ObjectStorageUploadedParts $uploadedParts = null,
        bool $createOnly = true
    ): bool {
        // not every store honours If-None-Match on the completion (Hetzner replaces the object): look first
        if ($createOnly && $this->objectExists($key)) {
            return false;
        }
        $uploadedParts ??= $this->listUploadedParts($key, $uploadId);
        $completedParts = [];
        foreach ($uploadedParts->getElements() as $uploadedPart) {
            $completedParts[] = ['PartNumber' => $uploadedPart->partNumber, 'ETag' => '"' . trim($uploadedPart->etag, '"') . '"'];
        }
        $completeMultipartUploadRequest = [
            'Bucket' => $this->objectStorageSettings->bucket,
            'Key' => $this->getStorageKey($key),
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $completedParts],
        ];
        if ($createOnly && $this->supportsConditionalWrites()) {
            $completeMultipartUploadRequest['IfNoneMatch'] = '*';
        }
        try {
            $this->getS3Client()->completeMultipartUpload($completeMultipartUploadRequest)->resolve();
            return true;
        } catch (Throwable $throwable) {
            if ($createOnly && $this->isConditionFailure($throwable)) {
                return false;
            }
            throw $this->createStorageException('complete multipart upload', $key, $throwable);
        }
    }

    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        try {
            $this->getS3Client()->abortMultipartUpload([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
                'UploadId' => $uploadId,
            ])->resolve();
        } catch (NoSuchUploadException) {
            return;
        } catch (Throwable $throwable) {
            if ($this->getHttpStatusCode($throwable) === self::HTTP_STATUS_NOT_FOUND) {
                return;
            }
            throw $this->createStorageException('abort multipart upload', $key, $throwable);
        }
    }

    public function supportsConditionalWrites(): bool
    {
        return $this->objectStorageSettings->conditionalWrites;
    }

    public function readObjectChunks(string $key, ?ObjectStorageByteRange $byteRange = null, int $chunkBytes = self::DEFAULT_CHUNK_BYTES): ?iterable
    {
        $getObjectRequest = [
            'Bucket' => $this->objectStorageSettings->bucket,
            'Key' => $this->getStorageKey($key),
        ];
        if ($byteRange !== null) {
            $getObjectRequest['Range'] = $byteRange->toRangeHeaderValue();
        }
        try {
            $getObjectOutput = $this->getS3Client()->getObject($getObjectRequest);
            // waits for the status and the headers only; the body is read chunk by chunk below
            $getObjectOutput->resolve();
        } catch (NoSuchKeyException) {
            return null;
        } catch (Throwable $throwable) {
            $httpStatusCode = $this->getHttpStatusCode($throwable);
            if ($httpStatusCode === self::HTTP_STATUS_RANGE_NOT_SATISFIABLE) {
                return [];
            }
            if ($httpStatusCode === self::HTTP_STATUS_NOT_FOUND) {
                return null;
            }
            throw $this->createStorageException('stream', $key, $throwable);
        }
        return ObjectStorageChunks::regroupChunks($getObjectOutput->getBody()->getChunks(), $chunkBytes);
    }

    public function putObjectFromStream(string $key, mixed $stream, int $contentLength, ?string $contentType = null): void
    {
        if (!is_resource($stream)) {
            throw new InternalErrorException(sprintf('Object storage "%s": put of "%s" needs a stream', $this->objectStorageSettings->name, $key));
        }
        $putObjectRequest = [
            'Bucket' => $this->objectStorageSettings->bucket,
            'Key' => $this->getStorageKey($key),
            'Body' => $stream,
            'ContentLength' => $contentLength,
        ];
        if ($contentType !== null) {
            $putObjectRequest['ContentType'] = $contentType;
        }
        try {
            $this->getS3Client()->putObject($putObjectRequest)->resolve();
        } catch (Throwable $throwable) {
            throw $this->createStorageException('put', $key, $throwable);
        }
    }

    public function uploadPartFromStream(
        string $key,
        string $uploadId,
        int $partNumber,
        mixed $stream,
        int $contentLength,
        ?string $contentMd5 = null
    ): ObjectStorageUploadedPart {
        if (!is_resource($stream)) {
            throw new InternalErrorException(sprintf('Object storage "%s": upload part of "%s" needs a stream', $this->objectStorageSettings->name, $key));
        }
        $uploadPartRequest = [
            'Bucket' => $this->objectStorageSettings->bucket,
            'Key' => $this->getStorageKey($key),
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
            'Body' => $stream,
            'ContentLength' => $contentLength,
        ];
        if ($contentMd5 !== null) {
            $uploadPartRequest['ContentMD5'] = $contentMd5;
        }
        try {
            $uploadPartOutput = $this->getS3Client()->uploadPart($uploadPartRequest);
            $uploadPartOutput->resolve();
        } catch (Throwable $throwable) {
            throw $this->createStorageException('upload part', $key, $throwable);
        }
        $uploadedPart = new ObjectStorageUploadedPart();
        $uploadedPart->partNumber = $partNumber;
        $uploadedPart->etag = trim((string)$uploadPartOutput->getEtag(), '"');
        $uploadedPart->size = $contentLength;
        return $uploadedPart;
    }

    public function listMultipartUploads(string $keyPrefix = '', int $limit = 1000): ObjectStorageMultipartUploads
    {
        $multipartUploads = new ObjectStorageMultipartUploads();
        $storagePrefix = $this->objectStorageSettings->prefix;
        try {
            $listMultipartUploadsOutput = $this->getS3Client()->listMultipartUploads([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Prefix' => $storagePrefix . ltrim($keyPrefix, '/'),
                'MaxUploads' => min(1000, max(1, $limit)),
            ]);
            foreach ($listMultipartUploadsOutput->getUploads() as $awsMultipartUpload) {
                if (count($multipartUploads->getElements()) >= $limit) {
                    break;
                }
                $multipartUpload = new ObjectStorageMultipartUpload();
                $storageKey = (string)$awsMultipartUpload->getKey();
                $multipartUpload->key = $storagePrefix !== '' && str_starts_with($storageKey, $storagePrefix)
                    ? substr($storageKey, strlen($storagePrefix))
                    : $storageKey;
                $multipartUpload->uploadId = (string)$awsMultipartUpload->getUploadId();
                $multipartUpload->initiatedAt = $awsMultipartUpload->getInitiated() !== null
                    ? DateTime::fromTimestamp($awsMultipartUpload->getInitiated()->getTimestamp())
                    : null;
                $multipartUploads->add($multipartUpload);
            }
        } catch (Throwable $throwable) {
            throw $this->createStorageException('list multipart uploads', $keyPrefix, $throwable);
        }
        return $multipartUploads;
    }

    /** The signer of presigned requests that carry signed headers; the method a test overrides */
    protected function createPresignedRequestSigner(): S3PresignedRequestSigner
    {
        return new S3PresignedRequestSigner($this->objectStorageSettings);
    }

    protected function hasCredentials(): bool
    {
        return $this->objectStorageSettings->accessKey !== null && $this->objectStorageSettings->secretKey !== null;
    }

    /** `bucket/key` of a copy source, URL-encoded per segment */
    protected function getCopySource(string $sourceKey): string
    {
        return $this->objectStorageSettings->bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $this->getStorageKey($sourceKey))));
    }

    /** True when a conditional write was refused because an object exists (412, or 409 for a concurrent conditional write) */
    protected function isConditionFailure(Throwable $throwable): bool
    {
        $httpStatusCode = $this->getHttpStatusCode($throwable);
        return $httpStatusCode === self::HTTP_STATUS_PRECONDITION_FAILED || $httpStatusCode === self::HTTP_STATUS_CONFLICT;
    }

    protected function getHttpStatusCode(Throwable $throwable): ?int
    {
        if ($throwable instanceof ClientException) {
            try {
                return $throwable->getResponse()->getStatusCode();
            } catch (Throwable) {
                return null;
            }
        }
        return null;
    }

    protected function getS3Client(): S3Client
    {
        return $this->s3Client ??= new S3Client(array_filter([
            'region' => $this->objectStorageSettings->region,
            'endpoint' => $this->objectStorageSettings->endpoint,
            'accessKeyId' => $this->objectStorageSettings->accessKey,
            'accessKeySecret' => $this->objectStorageSettings->secretKey,
            'pathStyleEndpoint' => $this->objectStorageSettings->pathStyle ? 'true' : 'false',
        ], static fn(mixed $value): bool => $value !== null), httpClient: $this->createHttpClient(), logger: $this->logger);
    }

    /**
     * The HTTP client for the S3 client; null = async-aws builds its default (no own timeout, 3 retries). With a
     * timeout, retry or HTTP version setting: the base client with `timeout` / `max_duration` / `http_version` as
     * default options, wrapped in the same retry strategy async-aws uses (1 s / 2 s / 4 s, S3 error codes) with the
     * store's retry count. A retry after a transport error (timeout, reset) runs on a new connection
     * ({@see FreshConnectionRetryStrategy}): over HTTP/2 the client would otherwise reuse the stalled connection.
     */
    protected function createHttpClient(): ?HttpClientInterface
    {
        if (!$this->objectStorageSettings->hasHttpClientSettings()) {
            return null;
        }
        $httpClientOptions = array_filter([
            'timeout' => $this->objectStorageSettings->timeoutSeconds,
            'max_duration' => $this->objectStorageSettings->maxDurationSeconds,
            'http_version' => $this->objectStorageSettings->httpVersion,
        ], static fn(mixed $value): bool => $value !== null);
        $freshConnectionHttpClient = new FreshConnectionHttpClient(function () use ($httpClientOptions): HttpClientInterface {
            $httpClient = $this->createBaseHttpClient();
            return $httpClientOptions !== [] ? $httpClient->withOptions($httpClientOptions) : $httpClient;
        });
        return new RetryableHttpClient(
            $freshConnectionHttpClient,
            new FreshConnectionRetryStrategy(
                new AwsRetryStrategy(AwsRetryStrategy::DEFAULT_RETRY_STATUS_CODES, 1000, 2.0, 0, 0.1, new XmlAwsErrorFactory()),
                $freshConnectionHttpClient,
                $this->logger
            ),
            $this->objectStorageSettings->maxRetries ?? self::DEFAULT_MAX_RETRIES,
            $this->logger
        );
    }

    /** The transport under the timeout options and the retries; the method a test overrides with a mock client */
    protected function createBaseHttpClient(): HttpClientInterface
    {
        return HttpClient::create();
    }

    /** The key inside the bucket: the store's prefix plus the caller's key */
    protected function getStorageKey(string $key, bool $allowEmpty = false): string
    {
        $key = ltrim($key, '/');
        if (!$allowEmpty && $key === '') {
            throw new InternalErrorException('Object storage: empty key');
        }
        return $this->objectStorageSettings->prefix . $key;
    }

    protected function createStorageException(string $operation, string $key, Throwable $throwable): InternalErrorException
    {
        return new InternalErrorException(sprintf(
            'Object storage "%s": %s of "%s" failed: %s',
            $this->objectStorageSettings->name,
            $operation,
            $key,
            $throwable->getMessage()
        ));
    }
}
