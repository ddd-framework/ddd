<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * Where a failed worker message was dispatched from (`messenger.origin.*`, stamped at dispatch). Messages dispatched
 * before the stamp existed carry no origin.
 */
class IssueEventMessageOrigin extends ValueObject
{
    /** @var string|null The route that dispatched the message, for a dispatch from a request */
    #[Required]
    public ?string $route = null;

    /** @var string|null The console command that dispatched the message, for a dispatch from the CLI */
    #[Required]
    public ?string $command = null;

    /** @var int|null The account the message was dispatched for */
    #[Required]
    public ?int $accountId = null;

    /** @var string|null The workspace or environment that dispatched it */
    #[Required]
    public ?string $workspace = null;

    /** @var DateTime|null When it was dispatched */
    #[Required]
    public ?DateTime $dispatchedAt = null;

    /** Whether the line carried any origin field */
    public function hasAnyOriginField(): bool
    {
        return $this->route !== null || $this->command !== null || $this->accountId !== null
            || $this->workspace !== null || $this->dispatchedAt !== null;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
