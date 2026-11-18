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
        getSchemaWritesDisabledMessage as public getDefaultSchemaWritesDisabledMessage;
    }

    /** @var bool|null Overrides the schema-writes switch; null reads the environment */
    public ?bool $schemaWritesDisabledOverride = null;

    /** @var string|null Overrides the message of the schema-writes refusal; null keeps the default */
    public ?string $schemaWritesDisabledMessageOverride = null;

    protected function areSchemaWritesDisabled(): bool
    {
        return $this->schemaWritesDisabledOverride ?? $this->areSchemaWritesDisabledByEnvironment();
    }

    protected function getSchemaWritesDisabledMessage(): string
    {
        return $this->schemaWritesDisabledMessageOverride ?? $this->getDefaultSchemaWritesDisabledMessage();
    }
}
