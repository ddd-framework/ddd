<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Presentation\Api\Admin\Common\DatabaseModels\DatabaseModelsControllerTrait;
use DDD\Presentation\Base\Controller\HttpController;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\Router\Routes\Route;

#[Route('/common/databaseModels')]
#[Tag(group: 'Common', name: 'DatabaseModels', description: 'Database schema diff and generated models')]
class FixtureDatabaseModelsController extends HttpController
{
    use DatabaseModelsControllerTrait {
        areSchemaWritesDisabled as public areSchemaWritesDisabledByEnvironment;
    }

    /** @var bool|null Overrides the schema-writes switch; null reads the environment */
    public ?bool $schemaWritesDisabledOverride = null;

    protected function areSchemaWritesDisabled(): bool
    {
        return $this->schemaWritesDisabledOverride ?? $this->areSchemaWritesDisabledByEnvironment();
    }
}
