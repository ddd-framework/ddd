<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the console of this build says about a command a Cron may run: its description and whether an admin may
 * schedule it ({@see \DDD\Symfony\Commands\Common\Crons\CronSchedulable}).
 */
class CronConsoleCommand extends ValueObject
{
    public function __construct(
        /** @var string The console command name */
        public string $name = '',
        /** @var string The command's console description */
        public string $description = '',
        /** @var bool Whether the admin endpoints may schedule it */
        public bool $isSchedulable = false,
        /** @var bool Whether the console lists it as hidden */
        public bool $isHidden = false,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->name);
    }
}
