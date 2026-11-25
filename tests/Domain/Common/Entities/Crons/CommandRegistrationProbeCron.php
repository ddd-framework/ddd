<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Crons;

use DDD\Domain\Common\Entities\Crons\Cron;

/** A Cron whose console and stored row are given by the test: no kernel, no database */
class CommandRegistrationProbeCron extends Cron
{
    /** @var bool Whether the console registers the command */
    public bool $commandRegisteredInConsole = true;

    /** @var string|null The command stored in the row; null = no row */
    public ?string $storedCommandInDatabase = null;

    protected function isCommandRegistered(): bool
    {
        return $this->commandRegisteredInConsole;
    }

    protected function findStoredCommand(): ?string
    {
        return $this->storedCommandInDatabase;
    }

    /** An existing row: id, the stored command, and the object as loaded from it */
    public static function createStored(string $command, bool $isActive, bool $commandRegisteredInConsole): static
    {
        $cron = new static();
        $cron->id = 42;
        $cron->name = 'removed-command-cron';
        $cron->description = 'Runs a command that a release removed';
        $cron->schedule = '0 3 * * *';
        $cron->command = $command;
        $cron->isActive = $isActive;
        $cron->storedCommandInDatabase = $command;
        $cron->commandRegisteredInConsole = $commandRegisteredInConsole;
        return $cron;
    }
}
