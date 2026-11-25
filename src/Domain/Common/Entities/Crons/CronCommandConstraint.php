<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use Symfony\Component\Validator\Constraint;

/**
 * Cron command validation.
 */
#[Attribute]
class CronCommandConstraint extends Constraint
{
    use BaseAttributeTrait;

    public $message = 'The command "{{ value }}" is not registered in this console. A new or active Cron and a changed command need a registered command; a Cron that keeps its command can still be switched off.';

    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}