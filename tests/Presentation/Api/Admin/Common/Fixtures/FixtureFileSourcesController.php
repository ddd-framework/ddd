<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Presentation\Api\Admin\Common\Files\FileSourcesControllerTrait;
use DDD\Presentation\Base\Controller\HttpController;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\Router\Routes\Route;

#[Route('/common/files')]
#[Tag(group: 'Common', name: 'Files', description: 'Files of every file source by reference')]
class FixtureFileSourcesController extends HttpController
{
    use FileSourcesControllerTrait;
}
