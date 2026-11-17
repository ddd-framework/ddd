<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Decides like the wrapped strategy; when it retries after a transport error (timeout, connection reset), the retry
 * runs on a new connection ({@see FreshConnectionHttpClient::openFreshConnection()}) instead of the one that failed.
 * A retry after an HTTP answer (429, 5xx) keeps the connection: it answered.
 */
class FreshConnectionRetryStrategy implements RetryStrategyInterface
{
    public function __construct(
        protected RetryStrategyInterface $retryStrategy,
        protected FreshConnectionHttpClient $freshConnectionHttpClient,
        protected ?LoggerInterface $logger = null
    ) {
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        $shouldRetry = $this->retryStrategy->shouldRetry($context, $responseContent, $exception);
        if ($shouldRetry === true && $exception !== null) {
            $this->freshConnectionHttpClient->openFreshConnection();
            $this->logger?->info('Object storage: retrying {url} on a new connection after: {error}', [
                'url' => $context->getInfo('url'),
                'error' => $exception->getMessage(),
            ]);
        }
        return $shouldRetry;
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        return $this->retryStrategy->getDelay($context, $responseContent, $exception);
    }
}
