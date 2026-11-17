<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * A partial Cron update: a field that is not sent stays as it is. Clear the arguments by sending "".
 */
class CronUpdateRequestDto extends RequestDto
{
    /** @var int The id of the Cron */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $cronId;

    /** @var string|null New unique name: 3 to 80 lowercase letters, digits and single hyphens */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 80)]
    public ?string $name = null;

    /** @var string|null New description, 16 to 255 characters */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 255)]
    public ?string $description = null;

    /** @var string|null New cron expression, five fields */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 100)]
    public ?string $schedule = null;

    /** @var string|null New schedulable console command name */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 120)]
    public ?string $commandName = null;

    /** @var string|null New arguments; "" clears them */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 300)]
    public ?string $arguments = null;

    /** @var bool|null Switch the Cron on or off */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $isActive = null;
}
