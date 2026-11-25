<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Exceptions;

use DDD\Infrastructure\Exceptions\BadGatewayException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\Exception;
use DDD\Infrastructure\Exceptions\GoneException;
use PHPUnit\Framework\TestCase;

/**
 * The exception listener answers with the exception's code, so each class carries its HTTP status.
 */
class HttpStatusExceptionsTest extends TestCase
{
    /** @return array<string, array{0: class-string<Exception>, 1: int}> */
    public static function exceptionClassesWithStatus(): array
    {
        return [
            'conflict' => [ConflictException::class, 409],
            'gone' => [GoneException::class, 410],
            'bad gateway' => [BadGatewayException::class, 502],
        ];
    }

    /** @dataProvider exceptionClassesWithStatus */
    public function testTheExceptionCarriesItsHttpStatus(string $exceptionClassName, int $expectedStatus): void
    {
        $exception = new $exceptionClassName('message');
        $this->assertSame($expectedStatus, $exception->getCode());
    }
}
