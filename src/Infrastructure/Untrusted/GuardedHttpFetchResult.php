<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Untrusted;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer of {@see \DDD\Infrastructure\Services\GuardedHttpFetchService::fetch()}: the body of a 2xx answer, or the
 * reason nothing was read. The body is foreign bytes: parse it with the helpers of this namespace, serve it with
 * {@see UntrustedContentHeaders}.
 */
class GuardedHttpFetchResult extends ValueObject
{
    public const string REASON_BLOCKED = 'BLOCKED';
    public const string REASON_UNRESOLVABLE = 'UNRESOLVABLE';
    public const string REASON_TIMEOUT = 'TIMEOUT';
    public const string REASON_FETCH_FAILED = 'FETCH_FAILED';
    public const string REASON_HTTP_ERROR = 'HTTP_ERROR';
    public const string REASON_UNSUPPORTED_TYPE = 'UNSUPPORTED_TYPE';
    public const string REASON_TOO_MANY_REDIRECTS = 'TOO_MANY_REDIRECTS';

    /** @var string The URL asked for */
    public string $requestedUrl;

    /** @var string The URL of the last hop */
    public string $finalUrl;

    /** @var int|null HTTP status of the last hop, null when no answer came */
    public ?int $statusCode = null;

    /** @var string Base Content-Type the server of the last hop declared (lower case, no parameters) */
    public string $contentType = '';

    /** @var string Body of a 2xx answer, at most the byte cap */
    public string $body = '';

    /** @var bool The body was cut at the byte cap */
    public bool $isTruncated = false;

    /** @var string|null Why no body was read (REASON_*), null on success */
    public ?string $refusalReason = null;

    /** @var int Redirects followed */
    public int $redirectCount = 0;

    /**
     * @return bool a 2xx answer was read
     */
    public function isSuccessful(): bool
    {
        return $this->refusalReason === null && $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
