<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * One breadcrumb of a client event: what happened in the client shortly before the error (navigation, a failed
 * fetch, a console error, a click). URLs and messages are redacted.
 */
class IssueEventBreadcrumb extends ValueObject
{
    /** @var string|null When it happened (ISO 8601, client clock) */
    #[Required]
    public ?string $at = null;

    /** @var string|null navigation, fetch, console, ui or custom */
    #[Required]
    public ?string $category = null;

    /** @var string|null What happened */
    #[Required]
    public ?string $message = null;

    /** @var string|null Request URL, for fetch breadcrumbs */
    #[Required]
    public ?string $url = null;

    /** @var string|null Previous route, for navigation breadcrumbs */
    #[Required]
    public ?string $from = null;

    /** @var string|null Next route, for navigation breadcrumbs */
    #[Required]
    public ?string $to = null;

    /** @var int|null HTTP status, for fetch breadcrumbs */
    #[Required]
    public ?int $status = null;

    public function uniqueKey(): string
    {
        // the same breadcrumb may repeat (a retried request): order matters, duplicates stay
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
