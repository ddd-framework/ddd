<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Files;

use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Entities\Files\Sources\FileReferences;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Tests\Domain\Common\Files\Fixtures\FixtureFileSource;
use DDD\Tests\Domain\Common\Files\Fixtures\FixtureImages;
use Imagick;
use PHPUnit\Framework\TestCase;

class FileSourcesServiceTest extends TestCase
{
    protected FixtureFileSource $mediaSource;

    protected FixtureFileSource $supportSource;

    protected FileSourcesService $fileSourcesService;

    protected function setUp(): void
    {
        $this->mediaSource = new FixtureFileSource(
            'media',
            [
                '1' => FixtureImages::buildJpeg(400, 200),
                '2' => FixtureImages::buildJpeg(300, 100, Imagick::ORIENTATION_RIGHTTOP),
                '3' => FixtureImages::buildTransparentPng(64, 64),
                '4' => '%PDF-1.4 not a picture',
                '5' => "%!PS-Adobe-3.0\n%%BoundingBox: 0 0 10 10\nshowpage\n",
            ],
            ['1' => 'image/jpeg', '2' => 'image/jpeg', '3' => 'image/png', '4' => 'application/pdf', '5' => 'image/jpeg']
        );
        $this->supportSource = new FixtureFileSource('support', ['7' => 'hello']);
        $this->fileSourcesService = new FileSourcesService();
        $this->fileSourcesService->setFileSources([$this->mediaSource, $this->supportSource]);
    }

    public function testAReferenceResolvesOnlyForARegisteredSource(): void
    {
        $this->assertSame(['media', 'support'], $this->fileSourcesService->getSourceNames());
        $this->assertSame('media/1', $this->fileSourcesService->resolveFileReference('media/1')->toString());
        $this->assertNull($this->fileSourcesService->findFileReference('assets/1'));
        $this->assertNull($this->fileSourcesService->findFileReference('assets/logo.png'));
        try {
            $this->fileSourcesService->resolveFileReference('ticket/1');
            $this->fail('an unknown source resolved');
        } catch (BadRequestException $badRequestException) {
            $this->assertStringContainsString('Known sources: media, support', $badRequestException->getMessage());
        }
    }

    public function testFactsKeepTheAskedOrderLeaveOutUnreadableFilesAndAskEachSourceOnce(): void
    {
        $fileFactsSet = $this->fileSourcesService->findFilesFacts(FileReferences::fromFileReferences([
            new FileReference('support', '7'),
            new FileReference('media', '3'),
            new FileReference('media', '99'),
            new FileReference('unknown', '1'),
            new FileReference('media', '1'),
        ]));
        $references = [];
        foreach ($fileFactsSet->getElements() as $fileFacts) {
            $references[] = $fileFacts->fileReference->toString();
        }
        $this->assertSame(['support/7', 'media/3', 'media/1'], $references);
        $this->assertSame(1, $this->mediaSource->findFilesFactsCallCount);
        $this->assertNull($this->fileSourcesService->findFileFacts(new FileReference('media', '99')));
        $this->expectException(NotFoundException::class);
        $this->fileSourcesService->requireFileFacts(new FileReference('media', '99'));
    }

    public function testContentOverTheCapIsRefused(): void
    {
        $this->assertSame('hello', $this->fileSourcesService->readFileContent(new FileReference('support', '7'), 5)->getContent());
        $this->expectException(BadRequestException::class);
        $this->fileSourcesService->readFileContent(new FileReference('support', '7'), 4);
    }

    public function testAPreviewIsScaledUprightAndWithoutMetadata(): void
    {
        $fileImagePreview = $this->fileSourcesService->renderImagePreview(new FileReference('media', '1'), 100);
        $this->assertSame('image/jpeg', $fileImagePreview->contentType);
        $this->assertSame([100, 50], [$fileImagePreview->width, $fileImagePreview->height]);

        $this->assertContains('exif', FixtureImages::getProfileNames($this->mediaSource->readFileContent('2', 1000000)->getContent()));
        $turnedPreview = $this->fileSourcesService->renderImagePreview(new FileReference('media', '2'), 2048);
        // 300 x 100 stored, EXIF says "turn right": shown upright it is 100 x 300, and never enlarged
        $this->assertSame([100, 300], [$turnedPreview->width, $turnedPreview->height]);
        $this->assertNotContains('exif', FixtureImages::getProfileNames($turnedPreview->getContent()));
    }

    public function testATransparentPictureStaysPng(): void
    {
        $fileImagePreview = $this->fileSourcesService->renderImagePreview(new FileReference('media', '3'), 32);
        $this->assertSame('image/png', $fileImagePreview->contentType);
        $this->assertSame([32, 32], [$fileImagePreview->width, $fileImagePreview->height]);
    }

    public function testBytesThatAreNoRasterPictureAreNeverDecoded(): void
    {
        foreach (['4', '5'] as $id) {
            try {
                $this->fileSourcesService->renderImagePreview(new FileReference('media', $id), 100);
                $this->fail('media/' . $id . ' was rendered');
            } catch (BadRequestException $badRequestException) {
                $this->assertStringContainsString('is no picture', $badRequestException->getMessage());
            }
        }
    }

    public function testAPictureWithTooManyPixelsIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->fileSourcesService->renderImagePreview(new FileReference('media', '1'), 100, null, 400 * 200 - 1);
    }

    public function testMetadataIsRemovedOnlyWhereThereIsSome(): void
    {
        $plainJpeg = FixtureImages::buildJpeg(40, 20);
        $this->assertSame($plainJpeg, $this->fileSourcesService->removeImageMetadata($plainJpeg));

        $jpegWithExif = FixtureImages::buildJpeg(40, 20, Imagick::ORIENTATION_RIGHTTOP);
        $cleanedJpeg = $this->fileSourcesService->removeImageMetadata($jpegWithExif);
        $this->assertNotSame($jpegWithExif, $cleanedJpeg);
        $this->assertNotContains('exif', FixtureImages::getProfileNames($cleanedJpeg));
        $imageSize = getimagesizefromstring($cleanedJpeg);
        $this->assertSame([20, 40, 'image/jpeg'], [$imageSize[0], $imageSize[1], $imageSize['mime']]);

        $this->expectException(BadRequestException::class);
        $this->fileSourcesService->removeImageMetadata('%PDF-1.4');
    }
}
