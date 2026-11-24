<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Base\Dtos;

use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Presentation\Base\Dtos\ObjectStorageStreamResponseDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The streamed download of an object: 200 / 206 / 416 / 304, HEAD, the security and buffering headers.
 */
class ObjectStorageStreamResponseDtoTest extends TestCase
{
    protected string $rootDirectory;

    protected FilesystemObjectStorage $objectStorage;

    protected function setUp(): void
    {
        $this->rootDirectory = sys_get_temp_dir() . '/ddd-stream-response-' . bin2hex(random_bytes(4));
        $this->objectStorage = new FilesystemObjectStorage($this->rootDirectory);
        $this->objectStorage->putObject('files/a.txt', '0123456789');
    }

    protected function tearDown(): void
    {
        exec('rm -r ' . escapeshellarg($this->rootDirectory));
    }

    /** @param string[] $headers */
    protected function createResponse(array $headers = [], string $method = 'GET', ?string $contentType = 'text/html', bool $inlineWanted = false, ?string $vettedInlineContentType = null): ObjectStorageStreamResponseDto
    {
        $request = Request::create('/content', $method);
        foreach ($headers as $headerName => $headerValue) {
            $request->headers->set($headerName, $headerValue);
        }
        return ObjectStorageStreamResponseDto::fromObjectStorage($this->objectStorage, 'files/a.txt', $request, $contentType, 'a.txt', $inlineWanted, $vettedInlineContentType, chunkBytes: 4);
    }

    protected function readBody(ObjectStorageStreamResponseDto $response): string
    {
        ob_start();
        $response->sendContent();
        return (string)ob_get_clean();
    }

    public function testTheWholeObjectWithTheSecurityAndBufferingHeaders(): void
    {
        $response = $this->createResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('0123456789', $this->readBody($response));
        self::assertSame('10', $response->headers->get('Content-Length'));
        self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'), 'HTML is never served in its type');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment', (string)$response->headers->get('Content-Disposition'));
        self::assertStringContainsString('sandbox', (string)$response->headers->get('Content-Security-Policy'));
        self::assertSame('"' . md5('0123456789') . '"', $response->headers->get('ETag'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testARangeAnswers206(): void
    {
        $response = $this->createResponse(['Range' => 'bytes=2-5']);
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 2-5/10', $response->headers->get('Content-Range'));
        self::assertSame('4', $response->headers->get('Content-Length'));
        self::assertSame('2345', $this->readBody($response));

        self::assertSame('789', $this->readBody($this->createResponse(['Range' => 'bytes=-3'])), 'suffix range');
        self::assertSame('89', $this->readBody($this->createResponse(['Range' => 'bytes=8-'])), 'open range');
        self::assertSame('89', $this->readBody($this->createResponse(['Range' => 'bytes=8-99'])), 'cut at the end');
    }

    public function testARangeBeyondTheEndAnswers416(): void
    {
        $response = $this->createResponse(['Range' => 'bytes=10-20']);
        self::assertSame(416, $response->getStatusCode());
        self::assertSame('bytes */10', $response->headers->get('Content-Range'));
        self::assertSame('', $this->readBody($response));
    }

    public function testSeveralRangesOrAStaleIfRangeAnswerTheWholeObject(): void
    {
        self::assertSame(200, $this->createResponse(['Range' => 'bytes=0-1,4-5'])->getStatusCode());
        self::assertSame(200, $this->createResponse(['Range' => 'bytes=0-1', 'If-Range' => '"stale"'])->getStatusCode());
        $etag = '"' . md5('0123456789') . '"';
        self::assertSame(206, $this->createResponse(['Range' => 'bytes=0-1', 'If-Range' => $etag])->getStatusCode());
    }

    public function testAMatchingIfNoneMatchAnswers304(): void
    {
        $response = $this->createResponse(['If-None-Match' => '"' . md5('0123456789') . '"']);
        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $this->readBody($response));
    }

    public function testHeadSendsNoBody(): void
    {
        $response = $this->createResponse([], 'HEAD');
        self::assertSame('10', $response->headers->get('Content-Length'));
        self::assertSame('', $this->readBody($response));
    }

    public function testInlineOnlyForTheRasterTypesOrAVettedType(): void
    {
        self::assertStringStartsWith('attachment', (string)$this->createResponse([], 'GET', 'text/plain', true)->headers->get('Content-Disposition'));
        $imageResponse = $this->createResponse([], 'GET', 'image/png', true);
        self::assertStringStartsWith('inline', (string)$imageResponse->headers->get('Content-Disposition'));
        $pdfResponse = $this->createResponse([], 'GET', 'application/pdf', true, 'application/pdf');
        self::assertStringStartsWith('inline', (string)$pdfResponse->headers->get('Content-Disposition'));
        self::assertSame('application/pdf', $pdfResponse->headers->get('Content-Type'));
        self::assertFalse($pdfResponse->headers->has('Content-Security-Policy'), 'a PDF does not render under the sandbox CSP');
    }

    public function testAMissingObjectIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ObjectStorageStreamResponseDto::fromObjectStorage($this->objectStorage, 'files/missing.txt', Request::create('/content'), 'text/plain');
    }
}
