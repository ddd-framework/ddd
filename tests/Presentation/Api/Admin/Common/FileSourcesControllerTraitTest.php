<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common;

use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Admin\Common\Files\Dtos\FileSourceFileContentGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Files\Dtos\FileSourceFileGetRequestDto;
use DDD\Tests\Domain\Common\Files\Fixtures\FixtureFileSource;
use DDD\Tests\Domain\Common\Files\Fixtures\FixtureImages;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureFileSourcesController;
use DDD\Tests\Presentation\Api\Admin\Common\Fixtures\FixtureForbiddingFileSourcesController;
use ReflectionClass;

class FileSourcesControllerTraitTest extends AdminControllerTraitTestCase
{
    protected function getFixtureControllerClass(): string
    {
        return FixtureFileSourcesController::class;
    }

    protected function getForbiddingFixtureControllerClass(): string
    {
        return FixtureForbiddingFileSourcesController::class;
    }

    protected function getAccessHookMethodName(): string
    {
        return 'throwForbiddenUnlessFileSourcesAccessAllowed';
    }

    public function testTheTraitMountsItsRoutesUnderTheControllerPrefix(): void
    {
        $this->assertSame([
            'GET /common/files/{source}/{id}/content',
            'GET /common/files/{source}/{id}/facts',
        ], $this->getMethodAndPathOfEveryRoute());
    }

    public function testAReferenceInThePathIsNeverAPath(): void
    {
        foreach ($this->loadRouteCollection() as $route) {
            $this->assertSame('[a-z][a-z0-9-]{0,31}', $route->getRequirement('source'), $route->getPath());
            $this->assertSame('[A-Za-z0-9_-]{1,128}', $route->getRequirement('id'), $route->getPath());
        }
    }

    public function testTheOpenApiDocumentDocumentsBothRoutes(): void
    {
        $document = $this->buildOpenApiDocument();
        $this->assertCount(2, $document->paths);
        $schemaNames = $this->getDocumentedSchemaNames($document);
        $this->assertContains('DDD.Presentation.Api.Admin.Common.Files.Dtos.FileSourceFileFactsGetResponseDto', $schemaNames);
        $this->assertContains('DDD.Domain.Common.Entities.Files.Sources.FileFacts', $schemaNames);
    }

    protected function buildFileSourcesService(string $audience = FileAudience::INTERNAL): FileSourcesService
    {
        $fileSourcesService = new FileSourcesService();
        $fileSourcesService->setFileSources([
            new FixtureFileSource(
                'media',
                ['1' => FixtureImages::buildJpeg(800, 400), '2' => '<html><script>alert(1)</script></html>'],
                ['1' => 'image/jpeg', '2' => 'text/html'],
                $audience
            ),
        ]);
        return $fileSourcesService;
    }

    protected function buildContentRequest(string $id, string $variant): FileSourceFileContentGetRequestDto
    {
        /** @var FileSourceFileContentGetRequestDto $requestDto */
        $requestDto = (new ReflectionClass(FileSourceFileContentGetRequestDto::class))->newInstanceWithoutConstructor();
        $requestDto->source = 'media';
        $requestDto->id = $id;
        $requestDto->variant = $variant;
        return $requestDto;
    }

    public function testFactsOfAReadableFileAndA404ForAnyOther(): void
    {
        $controller = (new ReflectionClass(FixtureFileSourcesController::class))->newInstanceWithoutConstructor();
        /** @var FileSourceFileGetRequestDto $requestDto */
        $requestDto = (new ReflectionClass(FileSourceFileGetRequestDto::class))->newInstanceWithoutConstructor();
        $requestDto->source = 'media';
        $requestDto->id = '1';
        $responseDto = $controller->getFileFacts($requestDto, $this->buildFileSourcesService());
        $this->assertSame('media/1', $responseDto->fileFacts->fileReference->toString());
        $requestDto->id = '99';
        $this->expectException(NotFoundException::class);
        $controller->getFileFacts($requestDto, $this->buildFileSourcesService());
    }

    public function testAPictureIsServedInlineAndForeignBytesAsAnAttachment(): void
    {
        $controller = (new ReflectionClass(FixtureFileSourcesController::class))->newInstanceWithoutConstructor();
        $fileSourcesService = $this->buildFileSourcesService();

        $thumbnailResponse = $controller->getFileContent($this->buildContentRequest('1', FileSourceFileContentGetRequestDto::VARIANT_THUMBNAIL), $fileSourcesService);
        $this->assertSame('image/jpeg', $thumbnailResponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string)$thumbnailResponse->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $thumbnailResponse->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string)$thumbnailResponse->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', (string)$thumbnailResponse->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string)$thumbnailResponse->headers->get('Cache-Control'));
        $thumbnailSize = getimagesizefromstring((string)$thumbnailResponse->getContent());
        $this->assertSame([320, 160], [$thumbnailSize[0], $thumbnailSize[1]]);

        $originalResponse = $controller->getFileContent($this->buildContentRequest('2', FileSourceFileContentGetRequestDto::VARIANT_ORIGINAL), $fileSourcesService);
        $this->assertStringStartsWith('attachment', (string)$originalResponse->headers->get('Content-Disposition'));
        $this->assertNotSame('text/html', $originalResponse->headers->get('Content-Type'));

        try {
            $controller->getFileContent($this->buildContentRequest('2', FileSourceFileContentGetRequestDto::VARIANT_PREVIEW), $fileSourcesService);
            $this->fail('a preview of a file that is no picture was answered');
        } catch (BadRequestException $badRequestException) {
            $this->assertStringContainsString('only variant=ORIGINAL', $badRequestException->getMessage());
        }
    }

    public function testAPersonalFileIsNotKeptByTheBrowser(): void
    {
        $controller = (new ReflectionClass(FixtureFileSourcesController::class))->newInstanceWithoutConstructor();
        $response = $controller->getFileContent(
            $this->buildContentRequest('1', FileSourceFileContentGetRequestDto::VARIANT_PREVIEW),
            $this->buildFileSourcesService(FileAudience::PERSONAL)
        );
        $this->assertStringContainsString('no-store', (string)$response->headers->get('Cache-Control'));
    }
}
