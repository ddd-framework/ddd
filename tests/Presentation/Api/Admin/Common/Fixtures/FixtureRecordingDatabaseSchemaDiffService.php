<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Domain\Base\Repo\DB\Database\Diff\DBExpectedDiffSignatures;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiffs;
use DDD\Domain\Common\Services\DatabaseSchemaDiffService;

/**
 * A schema diff service without a database: it answers a fixed diff set and records what the controller applies.
 */
class FixtureRecordingDatabaseSchemaDiffService extends DatabaseSchemaDiffService
{
    public DBTableDiffs $pendingDiffs;

    public ?DBTableDiffs $appliedDiffs = null;

    public ?bool $appliedWithProductionGuardBypass = null;

    public ?DBExpectedDiffSignatures $appliedExpectedDiffSignatures = null;

    public ?string $appliedExpectedDiffSignature = null;

    public function __construct()
    {
        $this->pendingDiffs = new DBTableDiffs();
    }

    public function addPendingDiff(string $sqlTableName, string $changeType = DBTableDiff::CHANGE_TYPE_ALTER_TABLE): void
    {
        $tableDiff = new DBTableDiff();
        $tableDiff->sqlTableName = $sqlTableName;
        $tableDiff->changeType = $changeType;
        $tableDiff->diffSignature = 'signature-' . $sqlTableName;
        $this->pendingDiffs->add($tableDiff);
    }

    public function computeDiffs(?array $entityClasses = null): DBTableDiffs
    {
        return $this->pendingDiffs;
    }

    public function applyDiffs(
        DBTableDiffs $diffs,
        bool $disableForeignKeyChecks = true,
        bool $bypassProductionGuard = false,
        ?DBExpectedDiffSignatures $expectedDiffSignatures = null
    ): DBTableDiffs {
        $this->appliedDiffs = $diffs;
        $this->appliedWithProductionGuardBypass = $bypassProductionGuard;
        $this->appliedExpectedDiffSignatures = $expectedDiffSignatures;
        return new DBTableDiffs();
    }

    public function applyDiff(
        DBTableDiff $diff,
        bool $disableForeignKeyChecks = true,
        bool $bypassProductionGuard = false,
        ?string $expectedDiffSignature = null
    ): DBTableDiffs {
        $this->appliedDiffs = new DBTableDiffs();
        $this->appliedDiffs->add($diff);
        $this->appliedWithProductionGuardBypass = $bypassProductionGuard;
        $this->appliedExpectedDiffSignature = $expectedDiffSignature;
        return new DBTableDiffs();
    }
}
