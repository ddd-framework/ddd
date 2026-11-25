<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinition;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Converting MOMENT literals from the caller's zone leaves `eq` / `ne` against 'null' (any case) alone: the grammar
 * reads it as IS [NOT] NULL, so it is no date-time to convert. Before, `oneTimeAt eq 'NULL'` was refused as
 * "not a date-time".
 */
class FiltersMomentNullLiteralTest extends TestCase
{
    protected function createMomentFiltersDefinitions(): FiltersDefinitions
    {
        return new FiltersDefinitions(
            ['propertyName' => 'oneTimeAt', 'temporalKind' => FiltersDefinition::TEMPORAL_KIND_MOMENT],
            'status',
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function nullComparisonsOnAMoment(): array
    {
        return [
            "eq 'NULL'" => ["oneTimeAt eq 'NULL'", 'NULL'],
            "ne 'null'" => ["oneTimeAt ne 'null'", 'null'],
            "eq 'Null' in a group" => ["status eq 'ACTIVE' and oneTimeAt eq 'Null'", 'Null'],
        ];
    }

    /** @dataProvider nullComparisonsOnAMoment */
    public function testANullLiteralOnAMomentIsLeftAsItIs(string $filterQuery, string $expectedLiteral): void
    {
        $filtersOptions = FiltersOptions::fromString($filterQuery);
        $filtersOptions->normalizeMomentLiterals($this->createMomentFiltersDefinitions(), new DateTimeZone('Europe/Berlin'));

        $this->assertStringContainsString("oneTimeAt", $filtersOptions->toCanonicalExpression());
        $this->assertStringContainsString("'" . $expectedLiteral . "'", $filtersOptions->toCanonicalExpression());
    }

    public function testADateTimeLiteralOnAMomentIsStillConverted(): void
    {
        $filtersOptions = FiltersOptions::fromString("oneTimeAt ge '2026-03-01 09:00:00'");
        $filtersOptions->normalizeMomentLiterals($this->createMomentFiltersDefinitions(), new DateTimeZone('Europe/Berlin'));

        $this->assertStringNotContainsString("'2026-03-01 09:00:00'", $filtersOptions->toCanonicalExpression());
    }

    public function testANullLiteralUnderAnotherOperatorIsStillRefused(): void
    {
        $this->expectException(BadRequestException::class);
        FiltersOptions::fromString("oneTimeAt gt 'NULL'")
            ->normalizeMomentLiterals($this->createMomentFiltersDefinitions(), new DateTimeZone('Europe/Berlin'));
    }
}
