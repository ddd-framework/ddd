<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The console commands an admin may schedule, sorted by name.
 *
 * @property CronSchedulableCommand[] $elements
 * @method CronSchedulableCommand[] getElements()
 * @method CronSchedulableCommand|null getByUniqueKey(string $uniqueKey)
 */
class CronSchedulableCommands extends ObjectSet
{
    public function getByCommandName(string $commandName): ?CronSchedulableCommand
    {
        return $this->getByUniqueKey(CronSchedulableCommand::uniqueKeyStatic($commandName));
    }
}
