<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Reflection;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Infrastructure\Reflection\ReflectionClass;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionProperty;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * The attribute-instance cache of a property keys on the lookup flags: an exact-name read and an IS_INSTANCEOF read
 * of the same attribute name are different lookups, so the first one no longer decides the answer of the other.
 * Entities carry the framework's Choice, a subclass of Symfony's: the filter definitions read it with IS_INSTANCEOF
 * and know the allowed values in every process, whatever ran before.
 */
class AttributeInstanceCacheFlagsTest extends TestCase
{
    protected function setUp(): void
    {
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, 'DB');
        ReflectionClass::$attributeInstanceCache = [];
    }

    public function testAnExactNameReadDoesNotHideTheInstanceOfRead(): void
    {
        $reflectionClass = ReflectionClass::instance(CronExecution::class);
        $this->assertNull($reflectionClass->getAttributeInstanceForProperty('state', Choice::class));
        $this->assertInstanceOf(
            Choice::class,
            $reflectionClass->getAttributeInstanceForProperty('state', Choice::class, ReflectionAttribute::IS_INSTANCEOF)
        );
    }

    public function testAnInstanceOfReadDoesNotLeakIntoTheExactNameRead(): void
    {
        $reflectionClass = ReflectionClass::instance(CronExecution::class);
        $this->assertNotNull(
            $reflectionClass->getAttributeInstanceForProperty('state', Choice::class, ReflectionAttribute::IS_INSTANCEOF)
        );
        $this->assertNull($reflectionClass->getAttributeInstanceForProperty('state', Choice::class));
    }

    public function testTheFilterDefinitionsKnowTheChoicesOfTheFrameworkChoice(): void
    {
        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(CronExecution::class);
        $stateDefinition = $filtersDefinitions->getFilterDefinitionForPropertyName('state');
        $this->assertNotNull($stateDefinition);
        $this->assertEqualsCanonicalizing(
            [CronExecution::STATE_RUNNING, CronExecution::STATE_ENDED],
            $stateDefinition->options
        );
    }
}
