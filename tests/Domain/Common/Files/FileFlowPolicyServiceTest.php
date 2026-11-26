<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Files;

use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowTarget;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService;
use PHPUnit\Framework\TestCase;

class FileFlowPolicyServiceTest extends TestCase
{
    protected function buildFileFacts(string $audience): FileFacts
    {
        $fileFacts = new FileFacts();
        $fileFacts->fileReference = new FileReference('media', '1');
        $fileFacts->audience = $audience;
        return $fileFacts;
    }

    public function testTheDefaultRuleLetsAFileGoOnlyWhereTheAudienceIsNotWider(): void
    {
        $fileFlowPolicyService = new FileFlowPolicyService();
        $expectations = [
            [FileAudience::PUBLIC, FileAudience::PUBLIC, true],
            [FileAudience::PUBLIC, FileAudience::INTERNAL, true],
            [FileAudience::SHARED, FileAudience::INTERNAL, true],
            [FileAudience::INTERNAL, FileAudience::INTERNAL, true],
            [FileAudience::INTERNAL, FileAudience::SHARED, false],
            [FileAudience::INTERNAL, FileAudience::PUBLIC, false],
            [FileAudience::SHARED, FileAudience::PUBLIC, false],
            [FileAudience::PERSONAL, FileAudience::INTERNAL, false],
            [FileAudience::PERSONAL, FileAudience::PERSONAL, false],
            ['SOMETHING', FileAudience::INTERNAL, false],
        ];
        foreach ($expectations as [$sourceAudience, $targetAudience, $isAllowed]) {
            $fileFlowDecision = $fileFlowPolicyService->decideFileFlow(
                $this->buildFileFacts($sourceAudience),
                FileFlowTarget::forStore('drive', $targetAudience, 'the space')
            );
            $this->assertSame($isAllowed, $fileFlowDecision->isAllowed, $sourceAudience . ' -> ' . $targetAudience);
            if (!$isAllowed) {
                $this->assertStringContainsString('media/1 was not copied', (string)$fileFlowDecision->refusalReason);
                $this->assertFalse($fileFlowDecision->isDurableCopyAllowed);
            }
        }
    }

    public function testMetadataIsStrippedWhereTheDestinationIsPublic(): void
    {
        $fileFlowPolicyService = new FileFlowPolicyService();
        $publicDecision = $fileFlowPolicyService->decideFileFlow($this->buildFileFacts(FileAudience::PUBLIC), FileFlowTarget::forStore('media', FileAudience::PUBLIC));
        $this->assertTrue($publicDecision->stripsMetadata);
        $internalDecision = $fileFlowPolicyService->decideFileFlow($this->buildFileFacts(FileAudience::PUBLIC), FileFlowTarget::forStore('drive', FileAudience::INTERNAL));
        $this->assertFalse($internalDecision->stripsMetadata);
    }

    public function testAPersonalFileIsShownButNeverKeptDurably(): void
    {
        $fileFlowPolicyService = new FileFlowPolicyService();
        foreach ([FileFlowTarget::forModel(), FileFlowTarget::forOperator()] as $fileFlowTarget) {
            $fileFlowDecision = $fileFlowPolicyService->decideFileFlow($this->buildFileFacts(FileAudience::PERSONAL), $fileFlowTarget);
            $this->assertTrue($fileFlowDecision->isAllowed);
            $this->assertFalse($fileFlowDecision->isDurableCopyAllowed);
            $this->assertTrue($fileFlowPolicyService->decideFileFlow($this->buildFileFacts(FileAudience::INTERNAL), $fileFlowTarget)->isDurableCopyAllowed);
        }
    }

    public function testATargetKnowsItsFolder(): void
    {
        $fileFlowTarget = FileFlowTarget::forStore('drive', FileAudience::INTERNAL, 'Own drive', true, '/customer-files/ticket-7/');
        $this->assertTrue($fileFlowTarget->isInFolder('customer-files'));
        $this->assertFalse($fileFlowTarget->isInFolder('customer'));
        $this->assertFalse(FileFlowTarget::forModel()->isInFolder('customer-files'));
    }
}
