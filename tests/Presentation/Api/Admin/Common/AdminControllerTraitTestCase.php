<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Presentation\Base\OpenApi\Document;
use DDD\Symfony\Loaders\CustomAnnotationClassLoader;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\Routing\RouteCollection;

/**
 * Mounts an admin controller trait on a fixture controller the way an application does (a class-level `#[Route]`
 * prefix and `use …ControllerTrait;`), loads its routes with the framework's route loader and builds the OpenAPI
 * document over them.
 */
abstract class AdminControllerTraitTestCase extends TestCase
{
    /** @return string The fixture controller class that uses the trait */
    abstract protected function getFixtureControllerClass(): string;

    protected function loadRouteCollection(): RouteCollection
    {
        $customAnnotationClassLoader = new CustomAnnotationClassLoader();
        return $customAnnotationClassLoader->load($this->getFixtureControllerClass());
    }

    /**
     * @return string[] `METHOD path` of every route, sorted
     */
    protected function getMethodAndPathOfEveryRoute(): array
    {
        $methodAndPaths = [];
        foreach ($this->loadRouteCollection() as $route) {
            foreach ($route->getMethods() as $httpMethod) {
                $methodAndPaths[] = $httpMethod . ' ' . $route->getPath();
            }
        }
        sort($methodAndPaths);
        return $methodAndPaths;
    }

    protected function buildOpenApiDocument(): Document
    {
        // the QueryOptions documentation reads the default repository type an application configures in its .env
        $defaultRepoTypeProperty = new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType');
        if (!$defaultRepoTypeProperty->isInitialized()) {
            $defaultRepoTypeProperty->setValue(null, LazyLoadRepo::DB);
        }
        $routeCollection = $this->loadRouteCollection();
        $document = new Document($routeCollection);
        $document->buildDocumentation();
        return $document;
    }

    /**
     * @return string[] the `components.schemas` keys of the built document
     */
    protected function getDocumentedSchemaNames(Document $document): array
    {
        return array_keys($document->components->schemas ?? []);
    }
    /** @return string The fixture controller class whose access hook throws {@see ForbiddenException} */
    abstract protected function getForbiddingFixtureControllerClass(): string;

    /** @return string The name of the trait's access hook */
    abstract protected function getAccessHookMethodName(): string;

    public function testEveryRouteCallsTheAccessHookFirstAndAnswers403WhenItThrows(): void
    {
        $forbiddingControllerClass = $this->getForbiddingFixtureControllerClass();
        $actionMethodNames = $this->getActionMethodNamesOfEveryRoute();
        $this->assertNotEmpty($actionMethodNames);
        foreach ($actionMethodNames as $actionMethodName) {
            // no constructor, no container: an action that touched its services or DTOs before the hook would fail
            // with an error other than the 403 of the hook
            $forbiddingController = (new ReflectionClass($forbiddingControllerClass))->newInstanceWithoutConstructor();
            $actionArguments = $this->buildUninitialisedArgumentsForAction($forbiddingControllerClass, $actionMethodName);
            try {
                $forbiddingController->$actionMethodName(...$actionArguments);
                $this->fail($actionMethodName . ' did not call the access hook');
            } catch (ForbiddenException $forbiddenException) {
                $this->assertSame(403, $forbiddenException->getCode(), $actionMethodName);
                $this->assertSame(1, $forbiddingController->accessHookCallCount, $actionMethodName);
            }
        }
    }

    public function testTheDefaultAccessHookLetsEveryRouteThrough(): void
    {
        $fixtureControllerClass = $this->getFixtureControllerClass();
        $fixtureController = (new ReflectionClass($fixtureControllerClass))->newInstanceWithoutConstructor();
        $accessHookMethod = new ReflectionMethod($fixtureControllerClass, $this->getAccessHookMethodName());
        $this->assertTrue($accessHookMethod->isProtected());
        $this->assertSame('void', (string)$accessHookMethod->getReturnType());
        $accessHookMethod->invoke($fixtureController);
        $this->addToAssertionCount(1);
    }

    /**
     * @return string[] the controller method of every route
     */
    protected function getActionMethodNamesOfEveryRoute(): array
    {
        $actionMethodNames = [];
        foreach ($this->loadRouteCollection() as $route) {
            $controllerReference = (string)$route->getDefault('_controller');
            $actionMethodNames[] = substr($controllerReference, strrpos($controllerReference, '::') + 2);
        }
        return array_values(array_unique($actionMethodNames));
    }

    /**
     * One argument per parameter of the action: an object of the parameter's class built without its constructor.
     *
     * @return object[]
     */
    protected function buildUninitialisedArgumentsForAction(string $controllerClass, string $actionMethodName): array
    {
        $actionArguments = [];
        foreach ((new ReflectionMethod($controllerClass, $actionMethodName))->getParameters() as $parameter) {
            $parameterType = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $parameterType, $actionMethodName);
            $actionArguments[] = (new ReflectionClass($parameterType->getName()))->newInstanceWithoutConstructor();
        }
        return $actionArguments;
    }
}
