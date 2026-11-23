<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Untrusted;

use DDD\Domain\Common\Entities\MediaItems\GenericMediaItemContent;
use DDD\Domain\Common\Entities\MediaItems\PDFDocument;
use DDD\Infrastructure\Cache\Marshaller;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Datafilter;
use DDD\Infrastructure\Libs\PhotoUtils\PhotoUtils;
use DDD\Infrastructure\Untrusted\UnsupportedImageFormatException;
use DDD\Testing\UntrustedInput\UntrustedInputFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The Core call sites that took foreign bytes before the untrusted-input helpers existed.
 */
class HardenedSinksTest extends TestCase
{
    public function testClientDeclaredContentInfoIsMeasuredAgain(): void
    {
        $genericMediaItemContent = new GenericMediaItemContent();
        $genericMediaItemContent->base64EncodedContent = base64_encode($this->createTinyPng());
        $genericMediaItemContent->fileFormat = 'text/html';
        $genericMediaItemContent->width = 1;
        $genericMediaItemContent->height = 1;
        $genericMediaItemContent->fileSize = 1;
        $this->assertFalse($genericMediaItemContent->isMediaItemContentInfoGenerated());
        $genericMediaItemContent->populateMediaItemContentInfo();
        $this->assertSame('image/png', $genericMediaItemContent->fileFormat);
        $this->assertSame(3, $genericMediaItemContent->width);
        $this->assertTrue($genericMediaItemContent->isMediaItemContentInfoGenerated());
    }

    public function testHtmlDeclaredAsImageIsRefused(): void
    {
        $genericMediaItemContent = new GenericMediaItemContent();
        $genericMediaItemContent->base64EncodedContent = base64_encode('<html><script>alert(1)</script></html>');
        $genericMediaItemContent->fileFormat = 'image/png';
        $this->expectException(NotFoundException::class);
        $genericMediaItemContent->populateMediaItemContentInfo();
    }

    public function testUploadedPostScriptIsRefused(): void
    {
        $uploadedFilePath = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($uploadedFilePath, UntrustedInputFixtures::INTERPRETER_BODIES['postscript']);
        try {
            $this->expectException(UnsupportedImageFormatException::class);
            (new GenericMediaItemContent())->loadFromUploadedFile(new UploadedFile($uploadedFilePath, 'photo.jpg', 'image/jpeg', null, true));
        } finally {
            @unlink($uploadedFilePath);
        }
    }

    public function testLoadFromSourceRefusesStreamWrappers(): void
    {
        foreach (['phar:///tmp/x.phar/a.png', 'php://filter/resource=/etc/passwd', 'data:image/png;base64,AAAA', '/does/not/exist.png'] as $refusedSource) {
            try {
                (new GenericMediaItemContent())->loadFromSource($refusedSource);
                $this->fail("{$refusedSource} was read");
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPhotoUtilsNeverDecodesInterpreterBodies(): void
    {
        foreach (UntrustedInputFixtures::INTERPRETER_BODIES as $bodyName => $body) {
            $this->assertNull(PhotoUtils::adjustImageToRequirements($body, 1, 1, 100, 100), $bodyName);
            $this->assertNull(PhotoUtils::sliceImageVertically($body, 10), $bodyName);
        }
        $this->assertNotNull(PhotoUtils::adjustImageToRequirements($this->createTinyPng(), 1, 1, 100, 100));
    }

    public function testDompdfRunsNoPhp(): void
    {
        $pdfDocument = PDFDocument::fromHTML('<html><body><p>visible</p><script type="text/php">echo "PHPRAN";</script></body></html>');
        $pdfBody = (string)$pdfDocument->mediaItemContent->getBody();
        $this->assertStringStartsWith('%PDF-', $pdfBody);
        $this->assertStringNotContainsString('PHPRAN', $pdfBody);
    }

    public function testPdfPagesAndTextComeFromPopplerNotImagick(): void
    {
        if (!is_executable('/usr/bin/pdftoppm') && trim((string)shell_exec('command -v pdftoppm')) === '') {
            $this->markTestSkipped('poppler is not installed');
        }
        $generatedPdfDocument = PDFDocument::fromHTML('<html><body><p>' . str_repeat('Readable text of the page. ', 5) . '</p></body></html>');
        $pdfDocument = new PDFDocument(pdfBody: $generatedPdfDocument->mediaItemContent->getBody());
        $this->assertStringContainsString('Readable text', $pdfDocument->getTextContent());
        $pdfDocumentAsImage = $pdfDocument->getDocumentAsImage(200);
        $this->assertNotNull($pdfDocumentAsImage);
        $this->assertSame('image/png', $pdfDocumentAsImage->mediaItemContent->fileFormat);

        $postScriptDocument = new PDFDocument(pdfBody: UntrustedInputFixtures::INTERPRETER_BODIES['postscript']);
        $this->assertNull($postScriptDocument->getDocumentAsImage());
    }

    public function testCacheValuesNamingForeignClassesAreMisses(): void
    {
        $marshaller = new Marshaller();
        $this->assertNull($marshaller->unmarshall('O:8:"Evil\Gad":0:{}'));
        $this->assertNull($marshaller->unmarshall('a:1:{i:0;O:22:"Monolog\Handler\Handler":0:{}}'));
        $this->assertEquals(new \ArrayObject([1]), $marshaller->unmarshall(serialize(new \ArrayObject([1]))));
        $this->assertSame(['a' => 1], $marshaller->unmarshall(serialize(['a' => 1])));
    }

    public function testSanitizeHtmlKeepsEncodedTagsEncoded(): void
    {
        $this->assertStringNotContainsString('<script', Datafilter::sanitizeHtml('&lt;script&gt;alert(1)&lt;/script&gt;<b>ok</b>'));
        $this->assertStringContainsString('<b>ok</b>', Datafilter::sanitizeHtml('<b>ok</b><script>alert(1)</script>'));
    }

    protected function createTinyPng(): string
    {
        $gdImage = imagecreatetruecolor(3, 2);
        ob_start();
        imagepng($gdImage);
        return (string)ob_get_clean();
    }
}
