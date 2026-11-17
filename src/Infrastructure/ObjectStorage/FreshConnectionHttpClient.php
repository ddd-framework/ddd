<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use Closure;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * An HTTP client that can drop its connections: {@see self::openFreshConnection()} replaces the inner client by a new
 * one from the factory, so the next request opens a new connection instead of reusing one from the pool.
 *
 * Why: over HTTP/2 the curl client multiplexes every request to a host on one connection and keeps that connection when
 * a request times out (only the stream is reset). A connection that stalls therefore stalls every retry of the same
 * process. {@see FreshConnectionRetryStrategy} calls it before a retry after a transport error (timeout, reset). The old
 * client closes its connections once its last response is gone.
 */
class FreshConnectionHttpClient implements HttpClientInterface, ResetInterface
{
    protected HttpClientInterface $httpClient;

    /** How often the inner client was replaced */
    protected int $freshConnectionCount = 0;

    /**
     * @param Closure(): HttpClientInterface $createHttpClient builds the inner client, with all its options
     */
    public function __construct(protected Closure $createHttpClient)
    {
        $this->httpClient = ($this->createHttpClient)();
    }

    /** The next request runs on a new inner client, i.e. on a new connection */
    public function openFreshConnection(): void
    {
        $this->httpClient = ($this->createHttpClient)();
        $this->freshConnectionCount++;
    }

    public function getFreshConnectionCount(): int
    {
        return $this->freshConnectionCount;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->httpClient->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->httpClient->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $createHttpClient = $this->createHttpClient;
        return new static(static fn(): HttpClientInterface => $createHttpClient()->withOptions($options));
    }

    public function reset(): void
    {
        if ($this->httpClient instanceof ResetInterface) {
            $this->httpClient->reset();
        }
    }
}
