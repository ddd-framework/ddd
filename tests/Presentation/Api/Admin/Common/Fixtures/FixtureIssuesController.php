<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Presentation\Api\Admin\Common\Issues\IssuesControllerTrait;
use DDD\Presentation\Base\Controller\HttpController;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\Router\Routes\Route;

#[Route('/common/issues')]
#[Tag(group: 'Common', name: 'Issues', description: 'Error groups of the issues registry')]
class FixtureIssuesController extends HttpController
{
    use IssuesControllerTrait;
}
