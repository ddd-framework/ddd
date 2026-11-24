<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Base\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A raw binary body stays unread by the DTO hydration, so a controller can stream it into an object store.
 */
class RequestDtoRawBodyTest extends TestCase
{
    public function testOctetStreamIsARawBody(): void
    {
        self::assertTrue(RequestDto::isRawBodyContentType('application/octet-stream'));
        self::assertTrue(RequestDto::isRawBodyContentType('Application/Octet-Stream; charset=binary'));
        self::assertFalse(RequestDto::isRawBodyContentType('application/json'));
        self::assertFalse(RequestDto::isRawBodyContentType(''));
    }

    public function testARawBodyIsNotDecodedAsJson(): void
    {
        $request = Request::create('/upload', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], '{"not":"a dto"}');
        $requestDto = new RequestDto();
        $requestDto->setPropertiesFromRequest($request);
        self::assertSame('{"not":"a dto"}', stream_get_contents($request->getContent(true)), 'the body is still there for the controller');
    }
}
