<?php

declare(strict_types=1);

namespace DDD\Symfony\EventListeners;

use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers a rate-limit rejection on the configured routes with the JSON body `{"error": "<message>"}` and keeps the
 * exception's `Retry-After` header.
 *
 * {@see QuotaSubscriber} rejects a request over its `#[Quota]` with Symfony's TooManyRequestsHttpException. The
 * {@see ExceptionListener} converts only DDD exceptions, so the 429 falls through to Symfony's HTML error page, which a
 * client that reads the error text from `error` in a JSON body cannot show. Only 429s are converted here; any other
 * Symfony HttpException keeps its current response.
 *
 * The routes are the path prefixes of `Common.TooManyRequestsJsonResponse` (`pathPrefixes`); the framework default is
 * none, so the listener answers nothing until an application names its prefixes.
 *
 * Priority -64 runs after {@see IssuesCaptureSubscriber} (100) and the {@see ExceptionListener} (0) and before
 * Symfony's ErrorListener (-128). Skipped with `?errors_as_json`.
 */
class TooManyRequestsJsonResponseListener implements EventSubscriberInterface
{
    /** @var string The config path of the listener's settings */
    public const string CONFIG_PATH = 'Common.TooManyRequestsJsonResponse';

    /** @var string The key of the path prefixes in the settings */
    public const string CONFIG_KEY_PATH_PREFIXES = 'pathPrefixes';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['answerTooManyRequestsWithJson', -64],
        ];
    }

    /**
     * Sets the JSON response on the event when it carries a TooManyRequestsHttpException of a configured route.
     *
     * @param ExceptionEvent $event
     * @return void
     */
    public function answerTooManyRequestsWithJson(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isRequestAnsweredWithJson($request) || $request->query->has('errors_as_json')) {
            return;
        }

        $tooManyRequestsException = $event->getThrowable();
        if (!$tooManyRequestsException instanceof TooManyRequestsHttpException) {
            return;
        }

        $errorMessage = $tooManyRequestsException->getMessage() !== ''
            ? $tooManyRequestsException->getMessage()
            : Response::$statusTexts[Response::HTTP_TOO_MANY_REQUESTS];

        $event->setResponse(
            new RestResponseDto(
                $this->buildErrorJson($errorMessage),
                $tooManyRequestsException->getStatusCode(),
                $tooManyRequestsException->getHeaders(),
                true,
            ),
        );
    }

    /**
     * Whether the request's path starts with one of the configured prefixes.
     *
     * @param Request $request
     * @return bool
     */
    protected function isRequestAnsweredWithJson(Request $request): bool
    {
        foreach ($this->getPathPrefixes() as $pathPrefix) {
            if ($pathPrefix !== '' && str_starts_with($request->getPathInfo(), $pathPrefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The configured path prefixes; an absent or malformed setting is none.
     *
     * @return string[]
     */
    protected function getPathPrefixes(): array
    {
        $settings = Config::get(self::CONFIG_PATH);
        $pathPrefixes = is_array($settings) ? ($settings[self::CONFIG_KEY_PATH_PREFIXES] ?? []) : [];
        return is_array($pathPrefixes) ? array_values(array_filter($pathPrefixes, 'is_string')) : [];
    }

    /**
     * The `{"error": "<message>"}` body with the flags SerializerTrait::toJSON() uses for the DDD listener's body, plus
     * substitution of malformed UTF-8 bytes so the encode cannot fail.
     *
     * @param string $errorMessage
     * @return string
     */
    protected function buildErrorJson(string $errorMessage): string
    {
        return json_encode(
            ['error' => $errorMessage],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ) ?: '{"error":"Too Many Requests"}';
    }
}
