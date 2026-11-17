<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Api\Admin\Common\Fixtures;

use DDD\Presentation\Api\Admin\Common\Crons\CronsControllerTrait;
use DDD\Presentation\Base\Controller\HttpController;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\Router\Routes\Route;

#[Route('/common/crons')]
#[Tag(group: 'Common', name: 'Crons', description: 'Scheduled console commands, their health and run history')]
class FixtureCronsController extends HttpController
{
    use CronsControllerTrait;
}
