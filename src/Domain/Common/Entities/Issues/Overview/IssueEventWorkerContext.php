<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Overview;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\OpenApi\Attributes\Required;

/**
 * The failed worker message of a MESSENGER event: which message, which handler, what it was about, which attempt, and
 * where it came from. Fields an older event does not carry are null (or empty).
 */
class IssueEventWorkerContext extends ValueObject
{
    /** @var string|null The message class (fully qualified) */
    #[Required]
    public ?string $messageClass = null;

    /** @var string|null The failing handler, Class::__invoke */
    #[Required]
    public ?string $handler = null;

    /** @var IssueEventKeyValues The message's scalar public properties (ids, enums, short strings), redacted */
    #[Required]
    public IssueEventKeyValues $messageKeys;

    /** @var int|null The delivery that failed: 1 = the first */
    #[Required]
    public ?int $attempt = null;

    /** @var bool|null True when no retry follows and the message went to the failure transport (lost until re-dispatched) */
    #[Required]
    public ?bool $final = null;

    /** @var int|null Retries before this delivery: 0 = the first delivery */
    #[Required]
    public ?int $retryCount = null;

    /** @var string[] The transports the message was received from */
    #[Required]
    public array $transports = [];

    // deliberately not #[Required]: a generated client drops the nullability of a required class reference
    /** @var IssueEventMessageOrigin|null Where the message was dispatched from; absent for messages without an origin stamp */
    public ?IssueEventMessageOrigin $origin = null;

    /** @var int|null Seconds between dispatch and the failed handling (queue delay) */
    #[Required]
    public ?int $ageSeconds = null;

    public function __construct()
    {
        $this->messageKeys = new IssueEventKeyValues();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
