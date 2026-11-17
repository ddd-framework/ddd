<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Presentation\Api\Admin\Common\Workers\WorkersControllerTrait;
use DDD\Presentation\Base\Controller\HttpController;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\Router\Routes\Route;

#[Route('/maintenance/workers')]
#[Tag(group: 'Maintenance', name: 'Workers', description: 'Messenger workers')]
class FixtureWorkersController extends HttpController
{
    use WorkersControllerTrait;
}
