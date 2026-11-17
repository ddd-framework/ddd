<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;

/**
 * The schema diff takes no parameters: it always compares every entity-derived table with the live database.
 */
class DBTableDiffsGetRequestDto extends RequestDto
{
}
