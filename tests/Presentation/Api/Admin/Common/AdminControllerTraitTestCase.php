<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Presentation\Base\OpenApi\Document;
use DDD\Symfony\Loaders\CustomAnnotationClassLoader;
use PHPUnit\Framework\TestCase;
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
}
