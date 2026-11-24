<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\ObjectStorage\ObjectStorageByteRange;
use DDD\Infrastructure\ObjectStorage\ObjectStorageObject;
use DDD\Infrastructure\ObjectStorage\StreamingObjectStorage;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an object of an object store through the application, chunk by chunk, never buffered: the client never
 * sees the store (no presigned URL, no CORS rule, no bucket policy).
 *
 * - `Range: bytes=a-b` / `bytes=a-` / `bytes=-n` answers 206 with `Content-Range` (a ranged GET on the store, never
 *   the whole object); a range starting beyond the end answers 416 with `Content-Range: bytes * /<size>`; several
 *   ranges, or an `If-Range` that does not match, answer the whole object (200);
 * - `If-None-Match` matching the object's ETag answers 304 without a body; HEAD answers the headers only;
 * - `Content-Length`, `Accept-Ranges: bytes`, `ETag`, `Cache-Control` (private), `X-Accel-Buffering: no`;
 * - the security headers of foreign bytes ({@see UntrustedContentHeaders}): `nosniff`, `Content-Disposition`
 *   (attachment unless inline is granted), `Content-Security-Policy: sandbox` (not on a PDF, which a browser
 *   would not render under it);
 * - the body: output buffers are closed before the first chunk, each chunk is flushed; a client that went away
 *   stops the read.
 *
 * Host requirements (web server in front of PHP-FPM): see `ddd-endpoint-specialist` › "Streamed downloads".
 */
class ObjectStorageStreamResponseDto extends StreamedResponse
{
    public const string BUFFERING_HEADER = 'X-Accel-Buffering';

    public const string CACHE_CONTROL_PRIVATE_NO_STORE = 'private, no-store';

    /**
     * @var ResponseHeaderBag
     */
    #[HideProperty]
    public ResponseHeaderBag $headers;

    /**
     * @param StreamingObjectStorage $objectStorage
     * @param string $key
     * @param Request $request the current request (Range, If-Range, If-None-Match, HEAD)
     * @param string|null $contentType the server's type for the bytes (its records or the magic bytes), never a
     *                                 client's claim
     * @param string|null $fileName the name offered in `Content-Disposition`
     * @param bool $inlineWanted inline is granted for the raster allow-list only ({@see UntrustedContentHeaders})
     * @param string|null $vettedInlineContentType a type the caller vetted for inline display (a PDF, a text served as
     *                                             `text/plain; charset=utf-8`): served inline in this type
     * @param ObjectStorageObject|null $objectStorageObject the object's metadata when the caller has it (size, ETag);
     *                                                     otherwise it is read with a HEAD on the store
     * @param int $cacheMaxAgeSeconds 0 = `private, no-store`; otherwise `private, max-age=<n>`
     * @param int $chunkBytes
     * @return static
     * @throws NotFoundException when the object does not exist
     */
    public static function fromObjectStorage(
        StreamingObjectStorage $objectStorage,
        string $key,
        Request $request,
        ?string $contentType,
        ?string $fileName = null,
        bool $inlineWanted = false,
        ?string $vettedInlineContentType = null,
        ?ObjectStorageObject $objectStorageObject = null,
        int $cacheMaxAgeSeconds = 0,
        int $chunkBytes = StreamingObjectStorage::DEFAULT_CHUNK_BYTES
    ): static {
        $objectStorageObject ??= $objectStorage->headObject($key);
        if ($objectStorageObject === null || $objectStorageObject->size === null) {
            throw new NotFoundException('The content does not exist');
        }
        $objectSize = (int)$objectStorageObject->size;
        $etag = $objectStorageObject->etag !== null && $objectStorageObject->etag !== '' ? '"' . trim($objectStorageObject->etag, '"') . '"' : null;

        $response = new static();
        static::applyContentHeaders($response, $contentType, $fileName, $inlineWanted, $vettedInlineContentType);
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->headers->set(static::BUFFERING_HEADER, 'no');
        $response->headers->set('Cache-Control', $cacheMaxAgeSeconds > 0 ? 'private, max-age=' . $cacheMaxAgeSeconds : static::CACHE_CONTROL_PRIVATE_NO_STORE);
        if ($etag !== null) {
            $response->headers->set('ETag', $etag);
        }

        if ($etag !== null && static::isMatchingIfNoneMatch((string)$request->headers->get('If-None-Match', ''), $etag)) {
            $response->setStatusCode(304);
            $response->headers->remove('Content-Length');
            $response->setCallback(static function (): void {});
            return $response;
        }

        $byteRange = static::parseSingleRange($request, $etag, $objectSize);
        if ($byteRange === false) {
            $response->setStatusCode(416);
            $response->headers->set('Content-Range', 'bytes */' . $objectSize);
            $response->headers->set('Content-Length', '0');
            $response->setCallback(static function (): void {});
            return $response;
        }
        if ($byteRange !== null) {
            $response->setStatusCode(206);
            $response->headers->set('Content-Range', sprintf('bytes %d-%d/%d', $byteRange->firstByte, (int)$byteRange->lastByte, $objectSize));
            $response->headers->set('Content-Length', (string)$byteRange->getLength());
        } else {
            $response->headers->set('Content-Length', (string)$objectSize);
        }

        $isHeadRequest = $request->isMethod('HEAD');
        $response->setCallback(static function () use ($objectStorage, $key, $byteRange, $chunkBytes, $isHeadRequest, $objectSize): void {
            if ($isHeadRequest || $objectSize === 0) {
                return;
            }
            static::closePhpOutputBuffersBeforeStreaming();
            $objectChunks = $objectStorage->readObjectChunks($key, $byteRange, $chunkBytes) ?? [];
            foreach ($objectChunks as $chunk) {
                echo $chunk;
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
        });
        return $response;
    }

    /** Content type, disposition, nosniff and the sandbox CSP (none on a PDF) */
    protected static function applyContentHeaders(
        self $response,
        ?string $contentType,
        ?string $fileName,
        bool $inlineWanted,
        ?string $vettedInlineContentType
    ): void {
        if ($vettedInlineContentType !== null) {
            $response->headers->set('Content-Type', $vettedInlineContentType);
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Content-Disposition', UntrustedContentHeaders::buildContentDisposition(true, $fileName));
            if (UntrustedContentHeaders::normalizeContentType($vettedInlineContentType) !== 'application/pdf') {
                $response->headers->set('Content-Security-Policy', UntrustedContentHeaders::CONTENT_SECURITY_POLICY);
            }
            return;
        }
        UntrustedContentHeaders::applyToResponse($response, $contentType, $fileName, $inlineWanted);
    }

    /**
     * The one range the request asks for, cut to the object: null = the whole object (no Range, several ranges,
     * a syntax the server ignores, an If-Range that does not match); false = not satisfiable (416).
     */
    public static function parseSingleRange(Request $request, ?string $etag, int $objectSize): ObjectStorageByteRange|false|null
    {
        $rangeHeader = trim((string)$request->headers->get('Range', ''));
        if ($rangeHeader === '' || !$request->isMethodSafe()) {
            return null;
        }
        $ifRange = trim((string)$request->headers->get('If-Range', ''));
        if ($ifRange !== '' && ($etag === null || $ifRange !== $etag)) {
            return null;
        }
        if (!preg_match('/^bytes=(\d*)-(\d*)$/i', $rangeHeader, $rangeMatches) || ($rangeMatches[1] === '' && $rangeMatches[2] === '')) {
            return null;
        }
        if ($rangeMatches[1] === '') {
            $suffixLength = (int)$rangeMatches[2];
            if ($suffixLength === 0 || $objectSize === 0) {
                return false;
            }
            return ObjectStorageByteRange::fromFirstAndLastByte(max(0, $objectSize - $suffixLength), $objectSize - 1);
        }
        $requestedRange = ObjectStorageByteRange::fromFirstAndLastByte(
            (int)$rangeMatches[1],
            $rangeMatches[2] === '' ? null : (int)$rangeMatches[2]
        );
        if ($requestedRange->lastByte !== null && $requestedRange->lastByte < $requestedRange->firstByte) {
            return null;
        }
        return $requestedRange->cutToObjectSize($objectSize) ?? false;
    }

    protected static function isMatchingIfNoneMatch(string $ifNoneMatch, string $etag): bool
    {
        if (trim($ifNoneMatch) === '') {
            return false;
        }
        foreach (explode(',', $ifNoneMatch) as $candidateEtag) {
            $candidateEtag = trim($candidateEtag);
            if ($candidateEtag === '*' || preg_replace('/^W\//', '', $candidateEtag) === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * Closes PHP's output buffers (output_buffering, zlib.output_compression's handler) so every chunk leaves at once.
     * Not on the command line: a test client reads a streamed body through its own output buffer.
     */
    protected static function closePhpOutputBuffersBeforeStreaming(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        while (ob_get_level() > 0) {
            if (!@ob_end_flush()) {
                break;
            }
        }
    }
}
