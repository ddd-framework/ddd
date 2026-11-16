<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Audit;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use Doctrine\DBAL\Configuration;

/**
 * The DBAL configuration of one entity manager scope's connection, with the database it talks to — what the statement
 * audit installs its logger on.
 */
class DatabaseScopeConfiguration extends ValueObject
{
    /** @var string The entity manager scope ({@see \DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory::SCOPE_DEFAULT} …) */
    public string $scope = '';

    /** @var Configuration The DBAL configuration of the scope's connection */
    public Configuration $configuration;

    /** @var SqlDialect The database of the scope's connection */
    public SqlDialect $sqlDialect;

    /**
     * @param string $scope
     * @param Configuration $configuration
     * @param SqlDialect $sqlDialect
     * @return static
     */
    public static function forScope(string $scope, Configuration $configuration, SqlDialect $sqlDialect): static
    {
        $databaseScopeConfiguration = new static();
        $databaseScopeConfiguration->scope = $scope;
        $databaseScopeConfiguration->configuration = $configuration;
        $databaseScopeConfiguration->sqlDialect = $sqlDialect;
        return $databaseScopeConfiguration;
    }

    /**
     * One element per scope, also for a subclass ({@see buildUniqueKeyOfScope()}).
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        return static::buildUniqueKeyOfScope($this->scope);
    }

    /**
     * The element key of a scope, built from this declaring class so that the lookup
     * ({@see DatabaseScopeConfigurations::findByScope()}) finds the element of a subclass too.
     *
     * @param string $scope
     * @return string
     */
    public static function buildUniqueKeyOfScope(string $scope): string
    {
        return self::class . '_' . $scope;
    }
}
