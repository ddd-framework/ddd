<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use DDD\Infrastructure\Http\PublicUrlGuard;
use DDD\Infrastructure\Untrusted\GuardedHttpFetchResult;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches a URL that came from outside (a link in a message, a URL a client registered, a page a model chose):
 * - every hop is checked by {@see PublicUrlGuard} (http/https, every resolved address public) and the connect is
 *   pinned to the checked address, so DNS rebinding cannot swap in an internal one;
 * - redirects are followed by hand, each target guarded and pinned again;
 * - a byte cap, a time cap, an optional Content-Type allow-list (checked before the body is read).
 *
 * Our own APIs with configured hosts keep using their own clients; a URL from outside goes through here.
 */
class GuardedHttpFetchService extends Service
{
    public const int DEFAULT_MAX_BYTES = 5242880;
    public const float DEFAULT_TIMEOUT_SECONDS = 10.0;
    public const int DEFAULT_MAX_REDIRECTS = 3;
    public const string DEFAULT_USER_AGENT = 'Mozilla/5.0 (compatible; DDDGuardedFetch/1.0)';

    /**
     * GET of a URL from outside.
     *
     * @param string $url
     * @param int $maxBytes body cap; a longer body is cut and marked truncated
     * @param float $timeoutSeconds idle and total time per hop
     * @param int $maxRedirects
     * @param string[] $allowedContentTypes base types (`text/html`, `image/png`); empty = any
     * @param string[] $requestHeaders header name => value (no Host, no Cookie, no Authorization of ours)
     * @return GuardedHttpFetchResult
     */
    public function fetch(
        string $url,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $maxRedirects = self::DEFAULT_MAX_REDIRECTS,
        array $allowedContentTypes = [],
        array $requestHeaders = []
    ): GuardedHttpFetchResult {
        $guardedHttpFetchResult = new GuardedHttpFetchResult();
        $guardedHttpFetchResult->requestedUrl = $url;
        $currentUrl = $url;
        $httpClient = $this->createHttpClient();
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $guardedHttpFetchResult->finalUrl = $currentUrl;
            $guardedHttpFetchResult->redirectCount = $hop;
            $publicIp = $this->findGuardedPublicIp($guardedHttpFetchResult, $currentUrl);
            if ($publicIp === null) {
                return $guardedHttpFetchResult;
            }
            $redirectTarget = $this->fetchHop($httpClient, $guardedHttpFetchResult, $currentUrl, $publicIp, $maxBytes, $timeoutSeconds, $allowedContentTypes, $requestHeaders);
            if ($redirectTarget === null) {
                return $guardedHttpFetchResult;
            }
            $currentUrl = $redirectTarget;
        }
        $guardedHttpFetchResult->refusalReason = GuardedHttpFetchResult::REASON_TOO_MANY_REDIRECTS;
        return $guardedHttpFetchResult;
    }

    /**
     * One hop: request pinned to the checked address, redirects off.
     *
     * @param HttpClientInterface $httpClient
     * @param GuardedHttpFetchResult $guardedHttpFetchResult filled in place
     * @param string $url
     * @param string $publicIp
     * @param int $maxBytes
     * @param float $timeoutSeconds
     * @param string[] $allowedContentTypes
     * @param string[] $requestHeaders
     * @return string|null the next hop's URL for a redirect, else null (result final)
     */
    protected function fetchHop(
        HttpClientInterface $httpClient,
        GuardedHttpFetchResult $guardedHttpFetchResult,
        string $url,
        string $publicIp,
        int $maxBytes,
        float $timeoutSeconds,
        array $allowedContentTypes,
        array $requestHeaders
    ): ?string {
        $headers = array_merge(['User-Agent' => static::DEFAULT_USER_AGENT], $requestHeaders);
        try {
            $response = $httpClient->request('GET', $url, [
                'max_redirects' => 0,
                'resolve' => [(string)parse_url($url, PHP_URL_HOST) => $publicIp],
                'timeout' => $timeoutSeconds,
                'max_duration' => $timeoutSeconds,
                'headers' => $headers,
            ]);
            $statusCode = $response->getStatusCode();
            $guardedHttpFetchResult->statusCode = $statusCode;
            $responseHeaders = $response->getHeaders(false);
            if ($statusCode >= 300 && $statusCode < 400) {
                $response->cancel();
                return $this->resolveRedirectTarget($guardedHttpFetchResult, $url, (string)($responseHeaders['location'][0] ?? ''));
            }
            $guardedHttpFetchResult->contentType = UntrustedContentHeaders::normalizeContentType((string)($responseHeaders['content-type'][0] ?? ''));
            if ($statusCode < 200 || $statusCode >= 300) {
                $response->cancel();
                $guardedHttpFetchResult->refusalReason = GuardedHttpFetchResult::REASON_HTTP_ERROR;
                return null;
            }
            if ($allowedContentTypes !== [] && !in_array($guardedHttpFetchResult->contentType, $allowedContentTypes, true)) {
                $response->cancel();
                $guardedHttpFetchResult->refusalReason = GuardedHttpFetchResult::REASON_UNSUPPORTED_TYPE;
                return null;
            }
            $this->readBody($httpClient, $response, $guardedHttpFetchResult, $maxBytes);
        } catch (TimeoutExceptionInterface) {
            $guardedHttpFetchResult->refusalReason = GuardedHttpFetchResult::REASON_TIMEOUT;
        } catch (TransportExceptionInterface $transportException) {
            $guardedHttpFetchResult->refusalReason = preg_match('/time(d)? ?out|duration/i', $transportException->getMessage()) === 1
                ? GuardedHttpFetchResult::REASON_TIMEOUT
                : GuardedHttpFetchResult::REASON_FETCH_FAILED;
        }
        return null;
    }

    /**
     * Reads the body up to the cap.
     *
     * @param HttpClientInterface $httpClient
     * @param ResponseInterface $response
     * @param GuardedHttpFetchResult $guardedHttpFetchResult
     * @param int $maxBytes
     * @return void
     * @throws TransportExceptionInterface
     */
    protected function readBody(HttpClientInterface $httpClient, ResponseInterface $response, GuardedHttpFetchResult $guardedHttpFetchResult, int $maxBytes): void
    {
        $body = '';
        foreach ($httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                throw new TimeoutException('Idle timeout reached while reading the body.');
            }
            $body .= $chunk->getContent();
            if (strlen($body) > $maxBytes) {
                $body = substr($body, 0, $maxBytes);
                $guardedHttpFetchResult->isTruncated = true;
                $response->cancel();
                break;
            }
        }
        $guardedHttpFetchResult->body = $body;
    }

    /**
     * @param GuardedHttpFetchResult $guardedHttpFetchResult
     * @param string $baseUrl
     * @param string $location
     * @return string|null absolute http(s) target, null (with the reason set) when none
     */
    protected function resolveRedirectTarget(GuardedHttpFetchResult $guardedHttpFetchResult, string $baseUrl, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            $guardedHttpFetchResult->refusalReason = GuardedHttpFetchResult::REASON_FETCH_FAILED;
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $baseParts = parse_url($baseUrl);
        $origin = ($baseParts['scheme'] ?? 'https') . '://' . ($baseParts['host'] ?? '') . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($baseParts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $basePath = $baseParts['path'] ?? '/';
        $baseDirectory = substr($basePath, 0, (int)strrpos($basePath, '/') + 1);
        return $origin . ($baseDirectory !== '' ? $baseDirectory : '/') . $location;
    }

    /**
     * The guard of one hop: the checked public address to connect to, or null with the reason set.
     *
     * @param GuardedHttpFetchResult $guardedHttpFetchResult
     * @param string $url
     * @return string|null
     */
    protected function findGuardedPublicIp(GuardedHttpFetchResult $guardedHttpFetchResult, string $url): ?string
    {
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            $guardedHttpFetchResult->refusalReason = GuardedHttpFetchResult::REASON_BLOCKED;
            return null;
        }
        $publicIps = $this->resolvePublicIps($url);
        if ($publicIps === null) {
            $guardedHttpFetchResult->refusalReason = $this->classifyHost($url) === PublicUrlGuard::HOST_UNRESOLVABLE
                ? GuardedHttpFetchResult::REASON_UNRESOLVABLE
                : GuardedHttpFetchResult::REASON_BLOCKED;
            return null;
        }
        return $publicIps[0];
    }

    /**
     * Seam for tests.
     * @param string $url
     * @return string[]|null
     */
    protected function resolvePublicIps(string $url): ?array
    {
        return PublicUrlGuard::resolvePublicIps($url);
    }

    /**
     * Seam for tests.
     * @param string $url
     * @return string
     */
    protected function classifyHost(string $url): string
    {
        return PublicUrlGuard::classifyHost($url);
    }

    /**
     * Seam for tests (a MockHttpClient).
     * @return HttpClientInterface
     */
    protected function createHttpClient(): HttpClientInterface
    {
        return HttpClient::create();
    }
}
