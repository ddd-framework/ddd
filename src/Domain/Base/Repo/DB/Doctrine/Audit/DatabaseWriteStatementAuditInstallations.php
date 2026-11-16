<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Where a running statement audit is installed, one element per scope.
 *
 * @property DatabaseWriteStatementAuditInstallation[] $elements
 * @method DatabaseWriteStatementAuditInstallation first()
 * @method DatabaseWriteStatementAuditInstallation[] getElements()
 */
class DatabaseWriteStatementAuditInstallations extends ObjectSet
{
}
