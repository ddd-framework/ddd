<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Files\Sources;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Where a file is about to go: a store that keeps a copy (a drive space, a media library), the model, or the
 * operator's screen. A sink builds it and asks the flow rule
 * ({@see \DDD\Domain\Common\Services\Files\FileFlowPolicyService::decideFileFlow()}) before it writes or shows
 * anything.
 */
class FileFlowTarget extends ValueObject
{
    /** @var string A store keeps a copy of the file under its own rights */
    public const string KIND_STORE = 'STORE';

    /** @var string The bytes are sent to the model provider for one answer; nothing is kept there */
    public const string KIND_MODEL = 'MODEL';

    /** @var string The file is shown to the signed-in operator through the backend */
    public const string KIND_OPERATOR = 'OPERATOR';

    /** @var string[] */
    public const array KINDS = [self::KIND_STORE, self::KIND_MODEL, self::KIND_OPERATOR];

    /** @var string One of KINDS */
    public string $kind = self::KIND_STORE;

    /** @var string|null KIND_STORE: who sees a file in the destination, one of FileAudience::AUDIENCES */
    public ?string $audience = null;

    /** @var string|null KIND_STORE: the source name of the destination store (`drive`, `media`) */
    public ?string $storeName = null;

    /** @var bool KIND_STORE: true when the destination belongs to the caller alone (the own drive of an agent space) */
    public bool $isOwnContainer = false;

    /** @var string|null KIND_STORE: the folder inside the destination the file goes to, without leading slash */
    public ?string $folderPath = null;

    /** @var string|null The destination for people ("Team Musterstadt", "Media library") */
    public ?string $containerLabel = null;

    public static function forStore(string $storeName, string $audience, ?string $containerLabel = null, bool $isOwnContainer = false, ?string $folderPath = null): FileFlowTarget
    {
        $fileFlowTarget = new static();
        $fileFlowTarget->kind = static::KIND_STORE;
        $fileFlowTarget->storeName = $storeName;
        $fileFlowTarget->audience = $audience;
        $fileFlowTarget->containerLabel = $containerLabel;
        $fileFlowTarget->isOwnContainer = $isOwnContainer;
        $fileFlowTarget->folderPath = $folderPath === null ? null : trim($folderPath, '/');
        return $fileFlowTarget;
    }

    public static function forModel(): FileFlowTarget
    {
        $fileFlowTarget = new static();
        $fileFlowTarget->kind = static::KIND_MODEL;
        return $fileFlowTarget;
    }

    public static function forOperator(): FileFlowTarget
    {
        $fileFlowTarget = new static();
        $fileFlowTarget->kind = static::KIND_OPERATOR;
        return $fileFlowTarget;
    }

    public function isStore(): bool
    {
        return $this->kind === static::KIND_STORE;
    }

    /** True when the folder is the given one or lies below it */
    public function isInFolder(string $folderPath): bool
    {
        $folder = trim($folderPath, '/');
        $ownFolder = trim((string)$this->folderPath, '/');
        return $folder !== '' && ($ownFolder === $folder || str_starts_with($ownFolder, $folder . '/'));
    }
}
