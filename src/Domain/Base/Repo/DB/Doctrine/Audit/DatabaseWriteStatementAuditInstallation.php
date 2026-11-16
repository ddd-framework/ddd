<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;
use Doctrine\DBAL\Logging\SQLLogger;

/**
 * The statement audit installed on one scope's connection: the configuration it changed, the SQL logger that was
 * there before (put back on stop) and the logger it installed.
 */
class DatabaseWriteStatementAuditInstallation extends ValueObject
{
    /** @var DatabaseScopeConfiguration The scope, its configuration and its database */
    public DatabaseScopeConfiguration $scopeConfiguration;

    /** @var SQLLogger|null The SQL logger of the configuration before the audit started */
    public ?SQLLogger $previousLogger = null;

    /** @var SQLLogger The logger the audit installed (its own, or a chain after the previous one) */
    public SQLLogger $installedLogger;

    /**
     * @param DatabaseScopeConfiguration $scopeConfiguration
     * @param SQLLogger|null $previousLogger
     * @param SQLLogger $installedLogger
     * @return static
     */
    public static function onScope(DatabaseScopeConfiguration $scopeConfiguration, ?SQLLogger $previousLogger, SQLLogger $installedLogger): static
    {
        $databaseWriteStatementAuditInstallation = new static();
        $databaseWriteStatementAuditInstallation->scopeConfiguration = $scopeConfiguration;
        $databaseWriteStatementAuditInstallation->previousLogger = $previousLogger;
        $databaseWriteStatementAuditInstallation->installedLogger = $installedLogger;
        return $databaseWriteStatementAuditInstallation;
    }
}
