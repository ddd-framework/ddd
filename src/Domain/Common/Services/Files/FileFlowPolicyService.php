<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\Files;

use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowTarget;
use DDD\Infrastructure\Services\Service;

/**
 * The flow rule: may a file of one audience reach a destination. Every sink asks it before it writes or shows a file
 * (a drive import, a media library import, a picture for the model, the file route), after the source's read right
 * and before the destination's write right. A copy lives under the rights of its destination, so this is the one
 * place that keeps a file from becoming visible to more people than where it lies.
 *
 * This default knows no application: a file may go where the audience is as narrow as its own or narrower, to the
 * model and to the operator; personal files are copied nowhere. An application states its own matrix in a class of
 * the same path under its root namespace (`App\Domain\Common\Services\Files\FileFlowPolicyService`), which replaces
 * this one for every sink.
 */
class FileFlowPolicyService extends Service
{
    public function decideFileFlow(FileFacts $fileFacts, FileFlowTarget $fileFlowTarget): FileFlowDecision
    {
        if (!$fileFlowTarget->isStore()) {
            $fileFlowDecision = FileFlowDecision::allow();
            $fileFlowDecision->isDurableCopyAllowed = $fileFacts->audience !== FileAudience::PERSONAL;
            return $fileFlowDecision;
        }
        if (!FileAudience::isKnown($fileFacts->audience) || !FileAudience::isKnown($fileFlowTarget->audience)) {
            return FileFlowDecision::refuse(sprintf(
                '%s was not copied: who may see the file or the destination is not known.',
                $fileFacts->fileReference->toString()
            ));
        }
        if ($fileFacts->audience === FileAudience::PERSONAL) {
            return FileFlowDecision::refuse(sprintf(
                '%s was not copied: it is a person\'s own file and stays where it is. It can be looked at and read in place.',
                $fileFacts->fileReference->toString()
            ));
        }
        $sourceRank = FileAudience::getRestrictionRank($fileFacts->audience);
        $targetRank = FileAudience::getRestrictionRank($fileFlowTarget->audience);
        if ($targetRank < $sourceRank) {
            return FileFlowDecision::refuse(sprintf(
                '%s was not copied: more people see %s than see the file where it lies.',
                $fileFacts->fileReference->toString(),
                $fileFlowTarget->containerLabel ?? 'the destination'
            ));
        }
        $fileFlowDecision = FileFlowDecision::allow();
        $fileFlowDecision->stripsMetadata = $this->isMetadataStrippedForAudience($fileFlowTarget->audience);
        return $fileFlowDecision;
    }

    /**
     * Whether a copy that lands in a destination of this audience loses its metadata (EXIF: place, time, device).
     * A file that becomes public does; everything narrower keeps what helps the people who work with it.
     */
    public function isMetadataStrippedForAudience(?string $audience): bool
    {
        return $audience === FileAudience::PUBLIC;
    }
}
