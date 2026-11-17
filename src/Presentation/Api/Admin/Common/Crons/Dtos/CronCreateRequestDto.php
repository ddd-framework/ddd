<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * A new Cron. The command line is the command name plus the optional arguments.
 */
class CronCreateRequestDto extends RequestDto
{
    /** @var string Unique name: 3 to 80 lowercase letters, digits and single hyphens */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[Length(max: 80)]
    public string $name;

    /** @var string What the Cron is for, 16 to 255 characters */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[Length(max: 255)]
    public string $description;

    /** @var string Cron expression, five fields, evaluated in the server's time zone */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[Length(max: 100)]
    public string $schedule;

    /** @var string A schedulable console command name, from the commands list */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[Length(max: 120)]
    public string $commandName;

    /** @var string|null Arguments: one line, at most 300 characters, no quote characters */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 300)]
    public ?string $arguments = null;

    /** @var bool Whether the runner takes it; on by default */
    #[Parameter(in: Parameter::BODY, required: false)]
    public bool $isActive = true;
}
