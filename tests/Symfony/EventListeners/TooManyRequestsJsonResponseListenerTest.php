<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\EventListeners;

use DDD\Symfony\EventListeners\TooManyRequestsJsonResponseListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * A QuotaSubscriber 429 on a configured route must become the `{"error": "<message>"}` JSON body with the
 * `Retry-After` header, instead of Symfony's HTML error page; every other route and every other exception is left to
 * the other listeners.
 */
class TooManyRequestsJsonResponseListenerTest extends TestCase
{
    protected const string CLIENT_API_PATH_PREFIX = '/app/api/client';

    public function testTooManyRequestsOnAConfiguredRouteBecomesJsonWithRetryAfter(): void
    {
        $exceptionEvent = $this->buildExceptionEvent(
            Request::create('https://www.example.test/app/api/client/common/auth/login', 'POST'),
            new TooManyRequestsHttpException(37, 'Too many sign-in attempts. Please try again later.'),
        );

        $this->buildListener()->answerTooManyRequestsWithJson($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('37', $response->headers->get('Retry-After'));
        self::assertSame(
            '{"error":"Too many sign-in attempts. Please try again later."}',
            $response->getContent(),
        );
    }

    public function testEmptyMessageFallsBackToTheStatusText(): void
    {
        $exceptionEvent = $this->buildExceptionEvent(
            Request::create('https://www.example.test/app/api/client/common/account/links', 'POST'),
            new TooManyRequestsHttpException(60),
        );

        $this->buildListener()->answerTooManyRequestsWithJson($exceptionEvent);

        self::assertSame('{"error":"Too Many Requests"}', $exceptionEvent->getResponse()?->getContent());
    }

    public function testOtherHttpExceptionsOnAConfiguredRouteAreLeftToTheOtherListeners(): void
    {
        $exceptionEvent = $this->buildExceptionEvent(
            Request::create('https://www.example.test/app/api/client/common/unknownRoute'),
            new NotFoundHttpException('No route found for "GET /app/api/client/common/unknownRoute"'),
        );

        $this->buildListener()->answerTooManyRequestsWithJson($exceptionEvent);

        self::assertNull($exceptionEvent->getResponse());
    }

    public function testTooManyRequestsOutsideTheConfiguredRoutesIsLeftToTheOtherListeners(): void
    {
        foreach (['/app/api/partner/insights/tiles', '/app/api/public/versions/latest', '/app/oauth/token'] as $otherPath) {
            $exceptionEvent = $this->buildExceptionEvent(
                Request::create('https://www.example.test' . $otherPath, 'POST'),
                new TooManyRequestsHttpException(60, 'rate_limited'),
            );

            $this->buildListener()->answerTooManyRequestsWithJson($exceptionEvent);

            self::assertNull($exceptionEvent->getResponse(), $otherPath);
        }
    }

    public function testWithoutConfiguredPrefixesNothingIsAnswered(): void
    {
        $exceptionEvent = $this->buildExceptionEvent(
            Request::create('https://www.example.test/app/api/client/common/auth/login', 'POST'),
            new TooManyRequestsHttpException(60, 'rate_limited'),
        );

        (new FixedPathPrefixesTooManyRequestsJsonResponseListener([]))->answerTooManyRequestsWithJson($exceptionEvent);

        self::assertNull($exceptionEvent->getResponse());
    }

    public function testErrorsAsJsonDebugParameterIsLeftToTheOtherListeners(): void
    {
        $exceptionEvent = $this->buildExceptionEvent(
            Request::create('https://www.example.test/app/api/client/common/auth/login?errors_as_json=1', 'POST'),
            new TooManyRequestsHttpException(60, 'Too many sign-in attempts. Please try again later.'),
        );

        $this->buildListener()->answerTooManyRequestsWithJson($exceptionEvent);

        self::assertNull($exceptionEvent->getResponse());
    }

    public function testRunsBetweenTheExceptionListenerAndSymfonysHtmlErrorListener(): void
    {
        $subscribedEvents = TooManyRequestsJsonResponseListener::getSubscribedEvents();
        $priority = $subscribedEvents[KernelEvents::EXCEPTION][1];

        // IssuesCaptureSubscriber runs at 100, the ExceptionListener at 0, Symfony's ErrorListener::onKernelException at -128.
        self::assertLessThan(0, $priority);
        self::assertGreaterThan(-128, $priority);
    }

    protected function buildListener(): TooManyRequestsJsonResponseListener
    {
        return new FixedPathPrefixesTooManyRequestsJsonResponseListener([self::CLIENT_API_PATH_PREFIX]);
    }

    protected function buildExceptionEvent(Request $request, Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
