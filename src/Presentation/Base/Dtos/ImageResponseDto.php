<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * An image served inline and cached. Only image types are served as such: JPEG, PNG, GIF, WebP, HEIC, AVIF, BMP, ICO
 * and SVG (SVG under the sandbox CSP every file response carries, so a script in it never runs). Any other type
 * (`text/html`, a client's claim) goes out as `application/octet-stream` attachment.
 */
class ImageResponseDto extends FileResponseDto
{
    /** @var string[] Types served as images */
    public const array IMAGE_CONTENT_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif', 'image/avif', 'image/bmp',
        'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml',
    ];

    /**
     * @var ResponseHeaderBag
     */
    #[HideProperty]
    public ResponseHeaderBag $headers;

    public function __construct(
        ?string $content = '',
        int $status = 200,
        array $headers = [],
        string $contentType = 'image/jpeg'
    ) {
        $normalizedContentType = UntrustedContentHeaders::normalizeContentType($contentType);
        $headers = ['Pragma' => 'cache', 'Cache-Control' => 'public, max-age=15552000'];
        if (in_array($normalizedContentType, static::IMAGE_CONTENT_TYPES, true)) {
            $headers['Content-Type'] = $normalizedContentType;
        } else {
            $headers['Content-Type'] = UntrustedContentHeaders::OPAQUE_CONTENT_TYPE;
            $headers['Content-Disposition'] = UntrustedContentHeaders::buildContentDisposition(false, null);
        }
        parent::__construct($content, $status, $headers);
    }
}