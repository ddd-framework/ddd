<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Crons\Dtos;

use DDD\Domain\Common\Entities\Crons\CronSchedulableCommands;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The console commands the create and edit form offers.
 */
class CronSchedulableCommandsGetResponseDto extends RestResponseDto
{
    /** @var CronSchedulableCommands The schedulable commands, sorted by name */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public CronSchedulableCommands $commands;
}
