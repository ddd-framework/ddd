<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Infrastructure\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A property with a Choice accepts only its allowed values, but `eq` / `ne` against null (any spelling) is
 * IS [NOT] NULL, no value of the property: the allowed values do not apply to it.
 */
class FiltersChoiceNullComparisonTest extends TestCase
{
    protected function setUp(): void
    {
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, 'DB');
    }

    protected function validate(string $filterQuery): bool
    {
        return FiltersOptions::fromString($filterQuery)->validateAgainstDefinitions(
            FiltersDefinitions::getFiltersDefinitionsForReferenceClass(CronExecution::class)
        );
    }

    /** @return array<string, array{0: string}> */
    public static function acceptedFilters(): array
    {
        return [
            'allowed value' => ["state eq 'RUNNING'"],
            'allowed list' => ["state in ['RUNNING','ENDED']"],
            'eq null' => ['state eq null'],
            "eq 'NULL'" => ["state eq 'NULL'"],
            'ne null' => ['state ne null'],
            "ne 'null'" => ["state ne 'null'"],
        ];
    }

    /** @dataProvider acceptedFilters */
    public function testTheFilterIsAccepted(string $filterQuery): void
    {
        $this->assertTrue($this->validate($filterQuery));
    }

    public function testAValueOutsideTheChoicesIsStillRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Allowed values are');
        $this->validate("state eq 'FOO'");
    }
}
