<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serves bytes the server did not produce (uploads, attachments, files of a drive or a workspace): attachment with a
 * server-chosen type, `nosniff`, `CSP: sandbox`; inline only for the raster allow-list
 * ({@see UntrustedContentHeaders}).
 */
class UntrustedContentResponseDto extends FileResponseDto
{
    /**
     * @var ResponseHeaderBag
     */
    #[HideProperty]
    public ResponseHeaderBag $headers;

    /**
     * @param string|null $content
     * @param string|null $contentType the server's type (from the magic bytes or its records), never a client's claim
     * @param string|null $fileName
     * @param bool $inlineWanted granted only for JPEG, PNG, GIF, WebP
     * @param int $status
     * @param array $headers further headers (cache headers, …); the security headers always win
     */
    public function __construct(
        ?string $content = '',
        ?string $contentType = null,
        ?string $fileName = null,
        bool $inlineWanted = false,
        int $status = 200,
        array $headers = []
    ) {
        parent::__construct($content, $status, $headers, UntrustedContentHeaders::OPAQUE_CONTENT_TYPE);
        UntrustedContentHeaders::applyToResponse($this, $contentType, $fileName, $inlineWanted);
    }
}
