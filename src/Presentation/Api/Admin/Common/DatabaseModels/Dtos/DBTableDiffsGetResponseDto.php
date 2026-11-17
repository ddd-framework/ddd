<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos;

use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiffs;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class DBTableDiffsGetResponseDto extends RestResponseDto
{
    /** @var DBTableDiffs The pending differences between the entity-derived schema and the live database, one per table */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DBTableDiffs $diffs;
}
