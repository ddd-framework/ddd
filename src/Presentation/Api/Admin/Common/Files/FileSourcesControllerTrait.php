<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Files;

use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowTarget;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Admin\Common\Files\Dtos\FileSourceFileContentGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Files\Dtos\FileSourceFileFactsGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Files\Dtos\FileSourceFileGetRequestDto;
use DDD\Presentation\Base\Dtos\UntrustedContentResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;

/**
 * The file route of an admin API: facts and bytes of any file a registered
 * {@see \DDD\Domain\Common\Services\Files\FileSource} holds, named by its reference `<source>/<id>`. It is the only
 * way a browser gets such bytes: through the backend and the session, never a URL of a store. An application mounts
 * it with one controller of its own, which sets the path prefix, the tag and the access:
 *
 *     #[Route('/common/files')]
 *     #[Tag(group: 'Common', name: 'Files', description: 'Files of every file source by reference')]
 *     class AdminFilesController extends AdminController
 *     {
 *         use FileSourcesControllerTrait;
 *
 *         protected function throwForbiddenUnlessFileSourcesAccessAllowed(): void { … }
 *     }
 *
 * Who may read which file is decided per file by its source (it reads through its entity's service, under the
 * signed-in account's rights); a file the caller may not read is a 404. The application's flow rule
 * ({@see FileFlowPolicyService}) is asked as for every other exit. Bytes are answered as
 * {@see UntrustedContentResponseDto}: inline only for raster pictures, everything else an attachment with `nosniff`
 * and the sandbox CSP. Mount it only for callers the application trusts with every registered source's own rights
 * check (an admin firewall); partner or client audiences keep their own routes and projections.
 */
trait FileSourcesControllerTrait
{
    /**
     * Name, family, measured type and size, audience and container of one file.
     *
     * @throws ForbiddenException
     * @throws BadRequestException When the source is not registered
     * @throws NotFoundException When there is no such file for the caller
     */
    #[Get('/{source}/{id}/facts', requirements: FileSourceFileGetRequestDto::PATH_REQUIREMENTS)]
    #[Summary('File Facts')]
    public function getFileFacts(
        FileSourceFileGetRequestDto $requestDto,
        FileSourcesService $fileSourcesService
    ): FileSourceFileFactsGetResponseDto {
        $this->throwForbiddenUnlessFileSourcesAccessAllowed();
        $fileFacts = $fileSourcesService->requireFileFacts($requestDto->getFileReference());
        $this->throwForbiddenUnlessFileMayBeShown($fileFacts);
        $responseDto = new FileSourceFileFactsGetResponseDto();
        $responseDto->fileFacts = $fileFacts;
        return $responseDto;
    }

    /**
     * The bytes of one file. `variant=THUMBNAIL` and `PREVIEW` answer a picture rendered by the server (upright,
     * without metadata, at most 320 or 2048 pixels on its longest edge) and exist for pictures only; `ORIGINAL`
     * (default) answers the stored bytes, a raster picture inline and anything else as an attachment.
     *
     * @throws ForbiddenException
     * @throws BadRequestException When the source is not registered, the file is too large, or a rendered variant is asked of a file that is no picture
     * @throws NotFoundException When there is no such file for the caller
     */
    #[Get('/{source}/{id}/content', requirements: FileSourceFileGetRequestDto::PATH_REQUIREMENTS)]
    #[Summary('File Content')]
    public function getFileContent(
        FileSourceFileContentGetRequestDto $requestDto,
        FileSourcesService $fileSourcesService
    ): UntrustedContentResponseDto {
        $this->throwForbiddenUnlessFileSourcesAccessAllowed();
        $fileReference = $requestDto->getFileReference();
        $fileFacts = $fileSourcesService->requireFileFacts($fileReference);
        $this->throwForbiddenUnlessFileMayBeShown($fileFacts);
        $headers = ['Cache-Control' => $this->getFileContentCacheControl($fileFacts)];
        if ($requestDto->variant === FileSourceFileContentGetRequestDto::VARIANT_ORIGINAL) {
            $fileContent = $fileSourcesService->readFileContent($fileReference, $this->getFileContentMaxBytes());
            return new UntrustedContentResponseDto(
                $fileContent->getContent(),
                $fileContent->fileFacts->contentType,
                $fileContent->fileFacts->name,
                true,
                200,
                $headers
            );
        }
        if (!$fileFacts->isImage()) {
            throw new BadRequestException(sprintf(
                '%s is no picture: only variant=ORIGINAL exists for it.',
                $fileReference->toString()
            ));
        }
        $fileImagePreview = $fileSourcesService->renderImagePreview(
            $fileReference,
            $requestDto->variant === FileSourceFileContentGetRequestDto::VARIANT_THUMBNAIL
                ? $this->getFileThumbnailEdgePixels()
                : $this->getFilePreviewEdgePixels()
        );
        return new UntrustedContentResponseDto(
            $fileImagePreview->getContent(),
            $fileImagePreview->contentType,
            pathinfo($fileFacts->name, PATHINFO_FILENAME) . ($fileImagePreview->contentType === 'image/png' ? '.png' : '.jpg'),
            true,
            200,
            $headers
        );
    }

    /**
     * The access check of both endpoints, called first by each action. Empty by default: access is what the firewall
     * and `access_control` grant. An application whose rule is an account predicate (internal admins only) overrides
     * it and throws {@see ForbiddenException} (403).
     *
     * @throws ForbiddenException When the current request must not reach these endpoints
     */
    protected function throwForbiddenUnlessFileSourcesAccessAllowed(): void
    {
    }

    /**
     * Asks the application's flow rule whether this file may be shown to the operator.
     *
     * @throws ForbiddenException When the rule refuses
     */
    protected function throwForbiddenUnlessFileMayBeShown(FileFacts $fileFacts): void
    {
        /** @var FileFlowPolicyService $fileFlowPolicyService */
        $fileFlowPolicyService = DDDService::instance()->getService(FileFlowPolicyService::class);
        $fileFlowDecision = $fileFlowPolicyService->decideFileFlow($fileFacts, FileFlowTarget::forOperator());
        if (!$fileFlowDecision->isAllowed) {
            throw new ForbiddenException($fileFlowDecision->refusalReason ?? 'This file must not be shown here.');
        }
    }

    /** The largest file `variant=ORIGINAL` answers; larger files are refused (their own store serves them) */
    protected function getFileContentMaxBytes(): int
    {
        return 52428800;
    }

    /** The longest edge of `variant=THUMBNAIL` */
    protected function getFileThumbnailEdgePixels(): int
    {
        return 320;
    }

    /** The longest edge of `variant=PREVIEW` */
    protected function getFilePreviewEdgePixels(): int
    {
        return FileSourcesService::PREVIEW_MAX_EDGE_PIXELS;
    }

    /** A browser may keep a file for a short while, never a shared cache; a person's own file is not kept at all */
    protected function getFileContentCacheControl(FileFacts $fileFacts): string
    {
        return $fileFacts->audience === FileAudience::PERSONAL ? 'private, no-store' : 'private, max-age=300';
    }
}
