<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Bad gateway: an upstream service this request depends on failed or answered with something unusable (e.g. a
 * provider that is unavailable); the request itself was valid and may succeed later
 */
class BadGatewayException extends Exception
{
    protected static int $defaultCode = Response::HTTP_BAD_GATEWAY;
}
