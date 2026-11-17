<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A console command an admin may schedule as a Cron, with the Crons that already run it.
 */
class CronSchedulableCommand extends ValueObject
{
    /** @var string Console command name, for example app:orders:close-stale */
    public string $name = '';

    /** @var string What the command does, from its console description */
    public string $description = '';

    /** @var string[] Names of the Crons that already run this command */
    public array $usedByCronNames = [];

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->name);
    }
}
