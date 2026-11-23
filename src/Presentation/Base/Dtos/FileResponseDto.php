<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * A file response. Every file response carries `X-Content-Type-Options: nosniff` (the browser keeps the type we send)
 * and, unless the caller sets its own, `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline';
 * sandbox` (a document opened from it runs no script and loads nothing; PDFs are left out, browsers refuse to show them
 * sandboxed). Bytes the server did not produce go through {@see UntrustedContentResponseDto}.
 */
class FileResponseDto extends Response
{
    /**
     * @var ResponseHeaderBag
     */
    #[HideProperty]
    public ResponseHeaderBag $headers;

    public function __construct(
        ?string $content = '',
        int $status = 200,
        array $headers = [],
        string $contentType = 'application/octet-stream'
    ) {
        // put content type into header
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = $contentType;
        }

        parent::__construct($content, $status, $headers);
        $this->headers->set('X-Content-Type-Options', 'nosniff');
        if (!$this->headers->has('Content-Security-Policy')
            && UntrustedContentHeaders::normalizeContentType($this->headers->get('Content-Type')) !== 'application/pdf'
        ) {
            $this->headers->set('Content-Security-Policy', UntrustedContentHeaders::CONTENT_SECURITY_POLICY);
        }
    }

    /**
     * Sends content for the current web response.
     *
     * @return $this
     */
    public function sendContent(): static
    {
        echo $this->getContent();

        return $this;
    }
}