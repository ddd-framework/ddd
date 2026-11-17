<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Applies the pending diff of one table. There is deliberately no field to bypass the large-table production guard:
 * that bypass exists for console commands and message handlers only.
 */
class ApplyDiffRequestDto extends RequestDto
{
    /** @var string The `sqlTableName` of the diff to apply */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $sqlTableName;

    /** @var bool Run the statements with foreign key checks switched off (MariaDB); default true */
    #[Parameter(in: Parameter::BODY, required: false)]
    public bool $disableForeignKeyChecks = true;

    /**
     * @var string|null The `diffSignature` of the diff the operator saw. The diff is computed again inside the apply
     * lock and the apply is refused (`[DIFF_SIGNATURE_MISMATCH]`) when it changed since. A screen always sends it.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $expectedDiffSignature = null;
}
