<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * One cause of a backend error (the exception it wraps, `error.causes`), outermost cause first.
 */
class IssueEventCause extends ValueObject
{
    /** @var string|null The cause's class (fully qualified) */
    #[Required]
    public ?string $type = null;

    /** @var string|null The cause's message, sanitised */
    #[Required]
    public ?string $message = null;

    /** @var string|null Where the cause was thrown, relative to the project root: src/X.php:12 */
    #[Required]
    public ?string $file = null;

    /** @var IssueEventFrames The cause's first stack frames (at most 3), innermost first */
    #[Required]
    public IssueEventFrames $frames;

    public function __construct()
    {
        $this->frames = new IssueEventFrames();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
