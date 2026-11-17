<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A Cron's stored command line split as the runner splits it: the console command name (first word) and the rest.
 */
class CronCommandLine extends ValueObject
{
    public function __construct(
        /** @var string The console command name */
        public string $commandName = '',
        /** @var string|null The arguments after it; null when there are none */
        public ?string $arguments = null,
    ) {
        parent::__construct();
    }

    public static function fromCommand(string $command): static
    {
        $commandParts = explode(' ', trim($command), 2);
        $arguments = isset($commandParts[1]) ? trim($commandParts[1]) : '';
        return new static($commandParts[0], $arguments !== '' ? $arguments : null);
    }

    /** The command line as the Cron stores it */
    public function getCommand(): string
    {
        return $this->commandName . ($this->arguments !== null ? ' ' . $this->arguments : '');
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->getCommand());
    }
}
