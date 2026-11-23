<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers for bytes the server did not produce. A browser must never run them in the application's origin:
 * - `X-Content-Type-Options: nosniff` always (the browser keeps the type we send);
 * - `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` (no script, no request, a
 *   unique origin even when the document is opened directly);
 * - inline only for the raster allow-list (JPEG, PNG, GIF, WebP); everything else as an attachment with a
 *   server-chosen type (`application/octet-stream` when the caller has none, and always for a type a browser would
 *   run: HTML, SVG, XML, JavaScript);
 * - the file name RFC 6266-encoded (a quote or a newline in a foreign name cannot inject header parameters).
 */
class UntrustedContentHeaders
{
    /** @var string CSP of a response that carries foreign bytes */
    public const string CONTENT_SECURITY_POLICY = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    /** @var string[] Content types a browser may show inline (decoded as images only, never as a document) */
    public const array INLINE_CONTENT_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** @var string[] Types a browser would run as a document or script: always sent as the opaque type */
    public const array ACTIVE_CONTENT_TYPES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml', 'text/xsl',
        'application/javascript', 'text/javascript', 'application/x-javascript', 'application/ecmascript',
        'text/ecmascript', 'application/x-shockwave-flash', 'text/vtt', 'multipart/x-mixed-replace',
    ];

    /** @var string Type of bytes whose type the server does not vouch for */
    public const string OPAQUE_CONTENT_TYPE = 'application/octet-stream';

    /**
     * The base type of a Content-Type value, lower case, without parameters (`Text/HTML; charset=utf-8` → `text/html`).
     * @param string|null $contentType
     * @return string
     */
    public static function normalizeContentType(?string $contentType): string
    {
        $baseType = strtolower(trim(explode(';', (string)$contentType)[0]));
        return preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+$#', $baseType) === 1 ? $baseType : '';
    }

    /**
     * @param string|null $contentType
     * @return bool true when the type may be shown inline
     */
    public static function isInlineContentType(?string $contentType): bool
    {
        return in_array(static::normalizeContentType($contentType), static::INLINE_CONTENT_TYPES, true);
    }

    /**
     * Sets the headers on a response that carries foreign bytes.
     * @param Response $response
     * @param string|null $contentType the server's type for the bytes (from the magic bytes or its own records),
     *                                 never a client's claim; null = opaque
     * @param string|null $fileName the name offered for saving (foreign names are fine: they are encoded)
     * @param bool $inlineWanted inline is granted only for an inline content type
     * @return Response
     */
    public static function applyToResponse(Response $response, ?string $contentType, ?string $fileName = null, bool $inlineWanted = false): Response
    {
        $normalizedContentType = static::normalizeContentType($contentType);
        $isInline = $inlineWanted && static::isInlineContentType($normalizedContentType);
        if (!$isInline && ($normalizedContentType === '' || in_array($normalizedContentType, static::ACTIVE_CONTENT_TYPES, true))) {
            $normalizedContentType = static::OPAQUE_CONTENT_TYPE;
        }
        $response->headers->set('Content-Type', $normalizedContentType);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', static::CONTENT_SECURITY_POLICY);
        $response->headers->set('Content-Disposition', static::buildContentDisposition($isInline, $fileName));
        return $response;
    }

    /**
     * @param bool $isInline
     * @param string|null $fileName
     * @return string RFC 6266 value with an ASCII fallback name
     */
    public static function buildContentDisposition(bool $isInline, ?string $fileName): string
    {
        $disposition = $isInline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT;
        $cleanFileName = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', (string)$fileName) ?? '');
        if ($cleanFileName === '') {
            return $disposition;
        }
        $asciiFallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $cleanFileName) ?: 'download';
        return HeaderUtils::makeDisposition($disposition, $cleanFileName, trim($asciiFallback, '_') ?: 'download');
    }
}
