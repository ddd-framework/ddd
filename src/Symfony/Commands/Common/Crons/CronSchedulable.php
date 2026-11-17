<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Crons;

use Attribute;

/**
 * Marks a console command as one an admin may schedule as a Cron through the admin endpoints
 * ({@see \DDD\Presentation\Api\Admin\Common\Crons\CronsControllerTrait}). A command without it can still be scheduled
 * from the console (`app:crons:upsert`); the admin HTTP API offers and accepts only marked commands, because a Cron
 * runs its command with the full rights of the console. Inherited by subclasses of the command.
 *
 *     #[AsCommand(name: 'app:orders:close-stale', description: 'Closes orders left open for a day')]
 *     #[CronSchedulable]
 *     class CloseStaleOrders extends Command
 */
#[Attribute(Attribute::TARGET_CLASS)]
class CronSchedulable
{
}
