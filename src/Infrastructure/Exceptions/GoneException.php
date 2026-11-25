<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Gone: the target existed but is no longer available and will not come back (e.g. an expired ring or link);
 * retrying the same request will not succeed
 */
class GoneException extends Exception
{
    protected static int $defaultCode = Response::HTTP_GONE;
}
