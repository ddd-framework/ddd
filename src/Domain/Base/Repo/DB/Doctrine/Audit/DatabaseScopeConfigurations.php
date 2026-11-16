<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The connection configurations of several entity manager scopes, one per scope.
 *
 * @property DatabaseScopeConfiguration[] $elements
 * @method DatabaseScopeConfiguration first()
 * @method DatabaseScopeConfiguration[] getElements()
 */
class DatabaseScopeConfigurations extends ObjectSet
{
    /**
     * @param string $scope
     * @return DatabaseScopeConfiguration|null The configuration of the scope, null when the set has none
     */
    public function findByScope(string $scope): ?DatabaseScopeConfiguration
    {
        /** @var DatabaseScopeConfiguration|null $databaseScopeConfiguration */
        $databaseScopeConfiguration = $this->getByUniqueKey(DatabaseScopeConfiguration::buildUniqueKeyOfScope($scope));
        return $databaseScopeConfiguration;
    }
}
