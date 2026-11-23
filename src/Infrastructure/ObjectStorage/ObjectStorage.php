<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * A key-addressed object store (an S3 bucket, a directory). Keys are `/`-separated paths without a leading slash, e.g.
 * `sourcemaps/<release>/_next/static/chunks/app/page-3f2a.js.map`; a store's configured prefix is applied by the
 * adapter and never part of the keys a caller sees. Obtain a store by name from
 * {@see \DDD\Infrastructure\Services\ObjectStorageService::getObjectStorage()}.
 *
 * Every method throws {@see InternalErrorException} when the backend fails; a missing object is not an error.
 */
interface ObjectStorage
{
    /**
     * Writes the object, replacing an existing one.
     *
     * @throws InternalErrorException
     */
    public function putObject(string $key, string $content, ?string $contentType = null): void;

    /**
     * The object's content; null when there is no object under the key.
     *
     * @throws InternalErrorException
     */
    public function getObjectContent(string $key): ?string;

    /**
     * Removes the object; a missing object is fine.
     *
     * @throws InternalErrorException
     */
    public function deleteObject(string $key): void;

    /** @throws InternalErrorException */
    public function objectExists(string $key): bool;

    /**
     * The objects whose key starts with the prefix, in key order, at most $limit.
     *
     * @throws InternalErrorException
     */
    public function listObjects(string $keyPrefix = '', int $limit = 1000, ?string $startAfterKey = null): ObjectStorageObjects;

    /**
     * A URL that reads the object without credentials until it expires (S3: a presigned GET; filesystem: a `file://`
     * URL, for development only). The response type and disposition override what the store would answer, e.g.
     * `attachment; filename="Plan.pdf"`.
     *
     * @throws InternalErrorException
     */
    public function createTemporaryUrl(
        string $key,
        int $expiresInSeconds = 3600,
        ?string $responseContentType = null,
        ?string $responseContentDisposition = null
    ): string;

    /**
     * Writes the object only when no object exists under the key (S3: `If-None-Match: *`); false when one existed.
     *
     * @throws InternalErrorException
     */
    public function putObjectIfAbsent(string $key, string $content, ?string $contentType = null): bool;

    /**
     * Writes the object from a local file without reading it into memory, replacing an existing one.
     *
     * @throws InternalErrorException
     */
    public function putObjectFromFile(string $key, string $filePath, ?string $contentType = null): void;

    /**
     * Streams the object into a local file; false when there is no object under the key.
     *
     * @throws InternalErrorException
     */
    public function saveObjectToFile(string $key, string $filePath): bool;

    /**
     * $length bytes from $offset (fewer at the end of the object); null when there is no object under the key.
     *
     * @throws InternalErrorException
     */
    public function getObjectContentRange(string $key, int $offset, int $length): ?string;

    /**
     * Size, type, modification, ETag and checksum of the object without its content; null when there is none.
     *
     * @throws InternalErrorException
     */
    public function headObject(string $key): ?ObjectStorageObject;

    /**
     * Copies an object inside the store without moving its bytes through PHP (above 5 GiB as a multipart copy). With
     * $createOnly the copy is refused when the target exists: false (checked before the copy and, where the store
     * honours it, by `If-None-Match: *` on the copy itself). A missing source throws.
     *
     * @throws InternalErrorException
     */
    public function copyObject(string $sourceKey, string $targetKey, bool $createOnly = false): bool;

    /**
     * Removes the objects (S3: 1,000 keys per request); missing objects are fine.
     *
     * @param string[] $keys
     * @throws InternalErrorException
     */
    public function deleteObjects(array $keys): void;

    /**
     * A request that uploads the object without credentials: a presigned PUT. With $createOnly (the default) the
     * header `If-None-Match: *` is signed into it, so the URL can never replace an existing object. A given
     * SHA-256 (base64) is signed as `x-amz-checksum-sha256`: the store refuses other bytes.
     *
     * @throws InternalErrorException
     */
    public function createTemporaryUploadRequest(
        string $key,
        int $expiresInSeconds = 3600,
        ?string $contentType = null,
        bool $createOnly = true,
        ?string $checksumSha256 = null
    ): ObjectStoragePresignedRequest;

    /**
     * Starts a multipart upload; answers its upload id.
     *
     * @throws InternalErrorException
     */
    public function createMultipartUpload(string $key, ?string $contentType = null): string;

    /**
     * A presigned PUT for one part (1 to 10,000; every part but the last at least 5 MiB).
     *
     * @throws InternalErrorException
     */
    public function createTemporaryUploadPartRequest(
        string $key,
        string $uploadId,
        int $partNumber,
        int $expiresInSeconds = 3600
    ): ObjectStoragePresignedRequest;

    /**
     * The parts the store holds for the upload, in part number order.
     *
     * @throws InternalErrorException
     */
    public function listUploadedParts(string $key, string $uploadId): ObjectStorageUploadedParts;

    /**
     * Completes the upload from the given parts, or from the parts the store holds when none are given. With
     * $createOnly the completion is refused when an object exists under the key: false (the upload stays open).
     *
     * @throws InternalErrorException
     */
    public function completeMultipartUpload(
        string $key,
        string $uploadId,
        ?ObjectStorageUploadedParts $uploadedParts = null,
        bool $createOnly = true
    ): bool;

    /**
     * Drops the upload and its parts; an unknown upload is fine.
     *
     * @throws InternalErrorException
     */
    public function abortMultipartUpload(string $key, string $uploadId): void;

    /**
     * True when the store honours `If-None-Match: *` on writes (setting `conditionalWrites`, default true). Without
     * it a create-only flow uploads to a staging key and copies to the final key on the server.
     */
    public function supportsConditionalWrites(): bool;
}
