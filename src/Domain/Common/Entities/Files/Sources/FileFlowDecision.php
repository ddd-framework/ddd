<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Files\Sources;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The flow rule's answer for one file and one destination. A sink obeys every field: it refuses with the reason,
 * asks the owner where consent is required, removes the metadata where the destination's audience asks for it, and
 * keeps nothing durable where that is forbidden.
 */
class FileFlowDecision extends ValueObject
{
    /** @var bool False: the file must not reach the destination */
    public bool $isAllowed = false;

    /** @var string|null Why not, written for the caller: what did not happen and what would be allowed */
    public ?string $refusalReason = null;

    /** @var bool True: allowed only after the owner said yes to this step */
    public bool $requiresOwnerConsent = false;

    /**
     * @var bool True: the copy is written without its metadata (EXIF: place, time, device), turned upright first.
     * A property of the destination's audience: a file that becomes public loses it, an internal copy keeps it.
     */
    public bool $stripsMetadata = false;

    /** @var bool False: the bytes may be held in volatile caches only, never in a durable store beside their source */
    public bool $isDurableCopyAllowed = true;

    /** @var int|null The copy must be removed after this many days; null = it stays */
    public ?int $expiresAfterDays = null;

    public static function allow(): FileFlowDecision
    {
        $fileFlowDecision = new static();
        $fileFlowDecision->isAllowed = true;
        return $fileFlowDecision;
    }

    public static function refuse(string $refusalReason): FileFlowDecision
    {
        $fileFlowDecision = new static();
        $fileFlowDecision->isAllowed = false;
        $fileFlowDecision->refusalReason = $refusalReason;
        $fileFlowDecision->isDurableCopyAllowed = false;
        return $fileFlowDecision;
    }
}
