<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

/**
 * An object store that streams: reads an object (or a byte range of it) in chunks, writes an object or one part of a
 * multipart upload from a stream, and lists the open multipart uploads. Nothing of it holds a whole object in memory,
 * so an application can pass every byte through its own backend (no presigned URL reaches a client).
 *
 * Both Core drivers implement it ({@see S3CompatibleObjectStorage}, {@see FilesystemObjectStorage}); a driver of an
 * application that only implements {@see ObjectStorage} keeps working, callers check `instanceof`.
 */
interface StreamingObjectStorage extends ObjectStorage
{
    /** Default size of the chunks {@see readObjectChunks()} yields */
    public const int DEFAULT_CHUNK_BYTES = 1048576;

    /**
     * The bytes of an object, or of an inclusive byte range of it, in chunks of at most `$chunkBytes` (the last may
     * be shorter). A ranged read asks the store for the range only, never for the whole object.
     *
     * @param string $key
     * @param ObjectStorageByteRange|null $byteRange null = the whole object; a range reaching beyond the end is cut
     *                                               at the end; a range starting at or beyond the end yields nothing
     * @param int $chunkBytes
     * @return iterable<string>|null null when the object does not exist
     */
    public function readObjectChunks(string $key, ?ObjectStorageByteRange $byteRange = null, int $chunkBytes = self::DEFAULT_CHUNK_BYTES): ?iterable;

    /**
     * Writes an object from a seekable stream without reading it into memory. The stream's whole content, from its
     * start, is the object; it holds exactly `$contentLength` bytes. The S3 driver may spool the bytes once to
     * `php://temp` (2 MB in memory, the rest on disk) to sign and retry the request.
     *
     * @param string $key
     * @param resource $stream a seekable stream (a file, `php://temp`, `php://input`)
     * @param int $contentLength
     * @param string|null $contentType
     * @return void
     */
    public function putObjectFromStream(string $key, mixed $stream, int $contentLength, ?string $contentType = null): void;

    /**
     * Uploads one part of a multipart upload from a seekable stream whose whole content, from its start, is the part
     * (exactly `$contentLength` bytes), without reading it into memory; uploading a part number again replaces that
     * part.
     *
     * @param string $key
     * @param string $uploadId
     * @param int $partNumber 1 to 10,000
     * @param resource $stream a seekable stream
     * @param int $contentLength
     * @param string|null $contentMd5 base64 MD5 of the part: the store refuses a part that does not match it
     * @return ObjectStorageUploadedPart number, ETag and size the store recorded
     */
    public function uploadPartFromStream(
        string $key,
        string $uploadId,
        int $partNumber,
        mixed $stream,
        int $contentLength,
        ?string $contentMd5 = null
    ): ObjectStorageUploadedPart;

    /**
     * The multipart uploads that were started and neither completed nor aborted, oldest first where the store says
     * when they started; abort them with {@see ObjectStorage::abortMultipartUpload()}.
     *
     * @param string $keyPrefix
     * @param int $limit
     * @return ObjectStorageMultipartUploads
     */
    public function listMultipartUploads(string $keyPrefix = '', int $limit = 1000): ObjectStorageMultipartUploads;
}
