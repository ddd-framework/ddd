<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\DatabaseModels\Dtos;

use DDD\Domain\Base\Repo\DB\Database\Diff\DBExpectedDiffSignatures;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Applies the pending diffs of every table, or of the listed tables. There is deliberately no field to bypass the
 * large-table production guard: that bypass exists for console commands and message handlers only.
 */
class ApplyDiffsRequestDto extends RequestDto
{
    /**
     * @var string[]|null The `sqlTableName`s to apply; null applies every pending diff. An empty list is refused, and so
     * is a table without a pending diff (`[DIFF_SIGNATURE_MISMATCH]`: applied meanwhile, refresh).
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $sqlTableNames = null;

    /** @var bool Run the statements with foreign key checks switched off (MariaDB); default true */
    #[Parameter(in: Parameter::BODY, required: false)]
    public bool $disableForeignKeyChecks = true;

    /**
     * @var DBExpectedDiffSignatures|null The `diffSignature` of every diff the operator saw, per table. The diffs are
     * computed again inside the apply lock and the apply is refused (`[DIFF_SIGNATURE_MISMATCH]`) when one changed
     * since. Narrowed to `sqlTableNames` when those are given. A screen always sends it.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?DBExpectedDiffSignatures $expectedDiffSignatures = null;
}
