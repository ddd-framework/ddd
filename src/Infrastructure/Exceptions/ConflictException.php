<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Conflict: the request is valid, but the current state of the target refuses it (e.g. it is running right now);
 * retrying later may succeed
 */
class ConflictException extends Exception
{
    protected static int $defaultCode = Response::HTTP_CONFLICT;
}
