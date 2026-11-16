<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Services;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Infrastructure\Services\Fixtures\ControllableMemoryDDDService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Above half the memory limit the caches are released on the first crossing, then only after the usage has grown by
 * the release step since the last release. A process that holds its memory for other reasons stays above the
 * threshold; releasing on every read made each read pay a full clear and left the entity registry storing nothing.
 */
class MemoryReleaseDueTest extends TestCase
{
    protected const int MEGABYTE = 1024 * 1024;

    protected ?DDDService $originalDDDServiceInstance = null;

    protected ControllableMemoryDDDService $controllableMemoryDDDService;

    protected function setUp(): void
    {
        $instanceProperty = new ReflectionProperty(DDDService::class, 'instance');
        $this->originalDDDServiceInstance = DDDService::instance();
        $this->controllableMemoryDDDService = new ControllableMemoryDDDService();
        $this->controllableMemoryDDDService->memoryLimitSetting = '1000M';
        $this->controllableMemoryDDDService->releaseStepPercentOfLimit = 10.0;
        $instanceProperty->setValue(null, $this->controllableMemoryDDDService);
        $this->controllableMemoryDDDService->resetMemoryReleaseState();
        DoctrineEntityRegistry::clear();
    }

    protected function tearDown(): void
    {
        $this->controllableMemoryDDDService->resetMemoryReleaseState();
        $instanceProperty = new ReflectionProperty(DDDService::class, 'instance');
        $instanceProperty->setValue(null, $this->originalDDDServiceInstance);
        DoctrineEntityRegistry::clear();
    }

    protected function setUsageInMegabytes(int $usageInMegabytes): void
    {
        $this->controllableMemoryDDDService->currentMemoryUsageInBytes = $usageInMegabytes * self::MEGABYTE;
    }

    public function testBelowTheThresholdAReleaseIsNeverDue(): void
    {
        $this->setUsageInMegabytes(400);
        $this->assertFalse($this->controllableMemoryDDDService->isMemoryUsageHigh());
        $this->assertFalse($this->controllableMemoryDDDService->isMemoryReleaseDue());
    }

    public function testTheFirstCrossingIsDueAndAfterTheReleaseOnlyTheStepMakesItDueAgain(): void
    {
        $dddService = $this->controllableMemoryDDDService;
        $this->setUsageInMegabytes(501);
        $this->assertTrue($dddService->isMemoryReleaseDue(), 'the first crossing releases');

        $this->setUsageInMegabytes(560);
        $dddService->noteMemoryReleased();
        $this->assertFalse($dddService->isMemoryReleaseDue(), 'right after a release nothing is due');

        $this->setUsageInMegabytes(659);
        $this->assertFalse($dddService->isMemoryReleaseDue(), 'below the step (100 MB of a 1000 MB limit)');

        $this->setUsageInMegabytes(661);
        $this->assertTrue($dddService->isMemoryReleaseDue(), 'the step reached: due again');
    }

    public function testAReleaseThatBroughtTheUsageBelowTheThresholdReleasesOnTheNextCrossing(): void
    {
        $dddService = $this->controllableMemoryDDDService;
        $this->setUsageInMegabytes(300);
        $dddService->noteMemoryReleased();
        $this->setUsageInMegabytes(501);
        $this->assertTrue($dddService->isMemoryReleaseDue());
    }

    public function testFallingBelowTheThresholdReArmsTheFirstCrossing(): void
    {
        $dddService = $this->controllableMemoryDDDService;
        $this->setUsageInMegabytes(600);
        $dddService->noteMemoryReleased();
        $this->setUsageInMegabytes(610);
        $this->assertFalse($dddService->isMemoryReleaseDue());

        $this->setUsageInMegabytes(450);
        $this->assertFalse($dddService->isMemoryReleaseDue());
        $this->setUsageInMegabytes(510);
        $this->assertTrue($dddService->isMemoryReleaseDue(), 'a new crossing after falling below the threshold');
    }

    /** A long-lived worker that keeps growing releases at every step, and the job reset between messages is a release */
    public function testAGrowingWorkerReleasesAtEveryStepAndTheJobResetCountsAsARelease(): void
    {
        $dddService = $this->controllableMemoryDDDService;
        $releaseCount = 0;
        for ($usageInMegabytes = 400; $usageInMegabytes <= 800; $usageInMegabytes += 1) {
            $this->setUsageInMegabytes($usageInMegabytes);
            if ($dddService->isMemoryReleaseDue()) {
                $releaseCount++;
                $dddService->noteMemoryReleased();
            }
        }
        // 501 (crossing), then 601, 701 (each 100 MB beyond the usage after the last release)
        $this->assertSame(3, $releaseCount);

        // the job reset releases and notes: a worker still above the threshold is not due at the next job's first read
        $this->setUsageInMegabytes(720);
        $dddService->noteMemoryReleased();
        $this->assertFalse($dddService->isMemoryReleaseDue());
    }

    public function testThousandReadsAtConstantHighUsageReleaseOnceAndTheRegistryKeepsStoring(): void
    {
        $dddService = $this->controllableMemoryDDDService;
        $entityRegistry = DoctrineEntityRegistry::getInstance();
        $this->setUsageInMegabytes(600);

        $releaseCount = 0;
        for ($readIndex = 1; $readIndex <= 1000; $readIndex++) {
            $releaseWasDue = $dddService->isMemoryReleaseDue();
            $storedValueObject = new ValueObject();
            $entityRegistry->add($storedValueObject, DBEntity::class, $readIndex);
            if ($releaseWasDue) {
                $releaseCount++;
            }
        }
        $this->assertSame(1, $releaseCount);
        $this->assertInstanceOf(ValueObject::class, $entityRegistry->get(DBEntity::class, 1));
        $this->assertInstanceOf(ValueObject::class, $entityRegistry->get(DBEntity::class, 1000));

        // growth by the step releases once more: the registry is emptied, the entity of that add is stored
        $this->setUsageInMegabytes(700);
        $storedValueObject = new ValueObject();
        $entityRegistry->add($storedValueObject, DBEntity::class, 1001);
        $this->assertFalse($entityRegistry->get(DBEntity::class, 1), 'emptied by the release');
        $this->assertInstanceOf(ValueObject::class, $entityRegistry->get(DBEntity::class, 1001));
        $this->assertFalse($dddService->isMemoryReleaseDue());
    }

    public function testTheReleaseOfTheEntityManagersNotesTheRelease(): void
    {
        $dddService = $this->controllableMemoryDDDService;
        $this->setUsageInMegabytes(600);
        $this->assertTrue($dddService->isMemoryReleaseDue());
        EntityManagerFactory::clearAllInstanceCaches();
        $this->assertFalse($dddService->isMemoryReleaseDue());
    }

    public function testWithoutAMemoryLimitUsageIsNeverHighAndNoReleaseIsDue(): void
    {
        $dddService = $this->controllableMemoryDDDService;
        $dddService->memoryLimitSetting = '-1';
        $this->controllableMemoryDDDService->currentMemoryUsageInBytes = PHP_INT_MAX - 1;
        $this->assertFalse($dddService->isMemoryUsageHigh());
        $this->assertFalse($dddService->isMemoryReleaseDue());
    }

    public function testTheReleaseStepIsTheConfiguredShareOfTheLimit(): void
    {
        $dddService = $this->controllableMemoryDDDService;
        $this->assertSame(100 * self::MEGABYTE, $dddService->getMemoryReleaseStepInBytes());
        $dddService->releaseStepPercentOfLimit = 25.0;
        $this->assertSame(250 * self::MEGABYTE, $dddService->getMemoryReleaseStepInBytes());
    }

    /** A limit changed later in the process (`ini_set` in a command) is honoured: the limit is not cached for good */
    public function testAChangedMemoryLimitSettingIsHonoured(): void
    {
        $dddService = $this->controllableMemoryDDDService;
        $this->setUsageInMegabytes(600);
        $this->assertTrue($dddService->isMemoryUsageHigh());
        $dddService->memoryLimitSetting = '2G';
        $this->assertSame(2 * 1024 * self::MEGABYTE, $dddService->getMemoryLimitInBytes());
        $this->assertFalse($dddService->isMemoryUsageHigh());
    }

    /** The real `memory_limit` of the process, changed with ini_set, is read again */
    public function testARealIniSetOfTheMemoryLimitIsHonoured(): void
    {
        $originalMemoryLimit = (string)ini_get('memory_limit');
        $this->controllableMemoryDDDService->memoryLimitSetting = null;
        try {
            ini_set('memory_limit', '3G');
            $this->assertSame(3 * 1024 * self::MEGABYTE, $this->controllableMemoryDDDService->getMemoryLimitInBytes());
            ini_set('memory_limit', '4G');
            $this->assertSame(4 * 1024 * self::MEGABYTE, $this->controllableMemoryDDDService->getMemoryLimitInBytes());
        } finally {
            ini_set('memory_limit', $originalMemoryLimit);
        }
        $this->assertSame(
            DDDService::convertMemoryLimitToBytes($originalMemoryLimit),
            $this->controllableMemoryDDDService->getMemoryLimitInBytes()
        );
    }
}
