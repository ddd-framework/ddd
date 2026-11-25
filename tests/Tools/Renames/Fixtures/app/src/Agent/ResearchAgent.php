<?php

declare(strict_types=1);

namespace App\Agent;

use Acme\Agents\AgentSkills;
use Acme\Agents\Legacy\Memory;
use Acme\Agents\Tools\WebTool;
use DDD\Infrastructure\Libs\Config;

class ResearchAgent extends WebTool
{
    public const array SKILLS = ['Agent.Web', 'Agent.Web.Search', AgentSkills::WEB, AgentSkills::WEB_PAGES];

    public function createTool(): WebTool
    {
        $webTool = new WebTool(Memory::class, Config::get('Settings.webSearch.enabled'));
        $webTool->WebTool = self::WEB;
        return $webTool;
    }
}
