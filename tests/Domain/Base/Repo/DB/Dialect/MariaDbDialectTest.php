<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect;
use DDD\Domain\Base\Repo\DB\Dialect\MariaDbSessionTimeouts;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** §6 step 2 "pure move": connectionParams() is the identity function, quote() matches today's raw backtick usage. */
class MariaDbDialectTest extends TestCase
{
    protected MariaDbDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new MariaDbDialect();
    }

    public function testConnectionParamsAreUnchanged(): void
    {
        $params = ['driver' => 'pdo_mysql', 'charset' => 'utf8mb4', 'server_version' => '10.6'];
        $this->assertSame($params, $this->dialect->connectionParams($params));
    }

    public function testQuoteWrapsInBackticks(): void
    {
        $this->assertSame('`CounterRows`', $this->dialect->quote('CounterRows'));
    }

    public function testNameAndCapabilities(): void
    {
        $this->assertSame('mariadb', $this->dialect->name());
        $this->assertTrue($this->dialect->capabilities()->isMariaDb());
        $this->assertFalse($this->dialect->capabilities()->isPostgres());
    }

    public function testConfigureOrmRegistersTheCustomFunctionsAndTypesWithoutError(): void
    {
        $configuration = new Configuration();
        $this->dialect->configureOrm($configuration);
        $this->assertNotNull($configuration->getCustomStringFunction('MATCH'));
        $this->assertNotNull($configuration->getCustomNumericFunction('COSINE_DISTANCE'));
    }

    public function testTransactionFragmentsRenderTheMariaDbForms(): void
    {
        $this->assertSame(
            "GROUP_CONCAT(DISTINCT `label` ORDER BY `label` SEPARATOR ', ')",
            $this->dialect->stringAggDistinct('`label`', ', ')
        );
        $this->assertSame('LOCK IN SHARE MODE', $this->dialect->forShare());
        $this->assertSame(
            'INSERT INTO `Rows` (`code`) VALUES (?) ON DUPLICATE KEY UPDATE `Rows`.`code` = `Rows`.`code`',
            $this->dialect->insertSkippingExistingRows("INSERT INTO `Rows` (`code`) VALUES (?);\n", 'Rows', 'code')
        );
    }

    public function testNumberReadersParseTheDocumentOnce(): void
    {
        $this->assertSame(
            "CAST(JSON_VALUE(`document`, '$.\"cost\"') AS DECIMAL(16, 8))",
            $this->dialect->jsonDecimal('`document`', JsonPath::fromKeys('cost'), 16, 8)
        );
        $this->assertSame("CAST(JSON_VALUE(`document`, '$.\"number\"') AS SIGNED)", $this->dialect->jsonInt('`document`', JsonPath::fromKeys('number')));
        $this->assertSame('OCTET_LENGTH(`label`)', $this->dialect->byteLength('`label`'));
    }

    public function testLocksOfOneConnectionShareOneInstance(): void
    {
        $firstConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $secondConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->assertSame($this->dialect->locks($firstConnection), $this->dialect->locks($firstConnection));
        $this->assertNotSame($this->dialect->locks($firstConnection), $this->dialect->locks($secondConnection));
    }

    public function testClearPutsBackTheWholeNumbersTheSessionHeldBeforeTheChange(): void
    {
        $connection = $this->buildSessionRecordingConnection();

        $this->dialect->setTransactionTimeouts($connection, 2, 20);
        $this->dialect->clearTransactionTimeouts($connection);

        $this->assertSame(['SELECT @@SESSION.innodb_lock_wait_timeout, @@SESSION.max_statement_time'], $connection->fetchedQueries);
        $this->assertSame(
            [
                'SET SESSION innodb_lock_wait_timeout = 2',
                'SET SESSION max_statement_time = 20',
                'SET SESSION innodb_lock_wait_timeout = 50, max_statement_time = 300',
            ],
            $connection->executedStatements
        );
    }

    /** A proxy that tracks session variables only when they are set to whole numbers (e.g. ProxySQL). */
    public function testNoTimeoutStatementCarriesDefaultAGlobalReadOrADecimalValue(): void
    {
        $connection = $this->buildSessionRecordingConnection();
        $connection->sessionValues = ['50', '0.500000'];

        $this->dialect->setTransactionTimeouts($connection, null, 20);
        $this->dialect->clearTransactionTimeouts($connection);

        foreach ($connection->executedStatements as $executedStatement) {
            $this->assertStringNotContainsStringIgnoringCase('DEFAULT', $executedStatement);
            $this->assertStringNotContainsString('.', $executedStatement);
            $this->assertStringNotContainsString('@@', $executedStatement);
        }
        $this->assertSame(
            'SET SESSION innodb_lock_wait_timeout = 50, max_statement_time = 1',
            end($connection->executedStatements),
            'a fraction of a second is rounded up, 0 would lift the limit'
        );
    }

    public function testASecondChangeBeforeTheClearKeepsTheValuesOfTheFirst(): void
    {
        $connection = $this->buildSessionRecordingConnection();

        $this->dialect->setTransactionTimeouts($connection, null, 20);
        $connection->sessionValues = ['50', '20.000000'];
        $this->dialect->setTransactionTimeouts($connection, 2, 20);
        $this->dialect->clearTransactionTimeouts($connection);

        $this->assertCount(1, $connection->fetchedQueries);
        $this->assertSame('SET SESSION innodb_lock_wait_timeout = 50, max_statement_time = 300', end($connection->executedStatements));
    }

    public function testClearWithoutAChangeIssuesNothingAndASecondClearNeither(): void
    {
        $connection = $this->buildSessionRecordingConnection();

        $this->dialect->clearTransactionTimeouts($connection);
        $this->assertSame([], $connection->executedStatements);

        $this->dialect->setTransactionTimeouts($connection, 2, null);
        $this->dialect->clearTransactionTimeouts($connection);
        $this->dialect->clearTransactionTimeouts($connection);

        $this->assertSame(
            ['SET SESSION innodb_lock_wait_timeout = 2', 'SET SESSION innodb_lock_wait_timeout = 50, max_statement_time = 300'],
            $connection->executedStatements
        );
    }

    public function testEachConnectionGetsItsOwnTimeoutsBack(): void
    {
        $firstConnection = $this->buildSessionRecordingConnection();
        $secondConnection = $this->buildSessionRecordingConnection();
        $secondConnection->sessionValues = ['7', '0.000000'];

        $this->dialect->setTransactionTimeouts($firstConnection, 2, 20);
        $this->dialect->setTransactionTimeouts($secondConnection, 2, 20);
        $this->dialect->clearTransactionTimeouts($secondConnection);
        $this->dialect->clearTransactionTimeouts($firstConnection);

        $this->assertSame('SET SESSION innodb_lock_wait_timeout = 7, max_statement_time = 0', end($secondConnection->executedStatements));
        $this->assertSame('SET SESSION innodb_lock_wait_timeout = 50, max_statement_time = 300', end($firstConnection->executedStatements));
    }

    public function testATimeoutChangeOutsideATransactionIsRefusedBeforeTheSessionIsRead(): void
    {
        $connection = $this->buildSessionRecordingConnection();
        $connection->transactionActive = false;

        try {
            $this->dialect->setTransactionTimeouts($connection, 2, 20);
            $this->fail('a change outside a transaction must throw');
        } catch (InternalErrorException) {
        }

        $this->assertSame([], $connection->fetchedQueries);
        $this->assertSame([], $connection->executedStatements);
    }

    public function testSessionTimeoutsRoundToWholeSecondsAndKeepZero(): void
    {
        $this->assertSame(
            'SET SESSION innodb_lock_wait_timeout = 50, max_statement_time = 300',
            MariaDbSessionTimeouts::fromSessionValues('50', '300.000000')->buildSetSessionSql()
        );
        $this->assertSame(
            'SET SESSION innodb_lock_wait_timeout = 0, max_statement_time = 2',
            MariaDbSessionTimeouts::fromSessionValues(0, 1.2)->buildSetSessionSql(),
            'a session that waits 0 seconds for a lock gets 0 back'
        );
        $this->assertSame(
            'SET SESSION innodb_lock_wait_timeout = 1, max_statement_time = 0',
            MariaDbSessionTimeouts::fromSessionValues('1', '0.000000')->buildSetSessionSql()
        );
    }

    protected function buildSessionRecordingConnection(): MariaDbSessionRecordingConnection
    {
        /** @var MariaDbSessionRecordingConnection $connection */
        $connection = (new ReflectionClass(MariaDbSessionRecordingConnection::class))->newInstanceWithoutConstructor();
        return $connection;
    }
}
