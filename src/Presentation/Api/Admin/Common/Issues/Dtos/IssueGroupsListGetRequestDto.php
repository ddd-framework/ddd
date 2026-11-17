<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Issues\Dtos;

use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Filters of the issues list. Typed parameters rather than QueryOptions: the status tabs and the environment switch
 * (with "all") are not plain row filters.
 */
class IssueGroupsListGetRequestDto extends RequestDto
{
    public const string STATUS_OPEN = 'OPEN';
    public const string STATUS_RESOLVED = 'RESOLVED';
    public const string STATUS_IGNORED = 'IGNORED';
    /** Every status: no status filter */
    public const string STATUS_ALL = 'ALL';
    public const array STATUSES = [self::STATUS_OPEN, self::STATUS_RESOLVED, self::STATUS_IGNORED, self::STATUS_ALL];

    public const int DEFAULT_LIMIT = 50;

    /**
     * @var string|null The registry environment (a configured one or one that holds groups), or "all" for every known
     * environment (each group carries its own). Omitted or empty resolves to the first known environment; an
     * explicitly sent value the registry does not know answers 400.
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 64)]
    public ?string $environment = null;

    /** @var string OPEN (default), RESOLVED, IGNORED or ALL */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Choice(choices: self::STATUSES)]
    public string $status = self::STATUS_OPEN;

    /** @var string|null Search over message, exception type and origin */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 200)]
    public ?string $search = null;

    /** @var string|null HTTP, CLI or MESSENGER (backend); WEB, WEB_SERVER or MOBILE (client errors) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 16)]
    public ?string $section = null;

    /** @var string|null First or last seen in this release */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 128)]
    public ?string $release = null;

    /** @var int Page size, 50 by default; clamped to 1 … 200 */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public int $limit = self::DEFAULT_LIMIT;

    /** @var int Groups to skip (paging); a negative value reads as 0 */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public int $offset = 0;

    /** The status filter of the registry query: null for ALL */
    public function getStatusFilter(): ?string
    {
        return $this->status === self::STATUS_ALL ? null : $this->status;
    }
}
