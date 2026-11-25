<?php

declare (strict_types=1);

namespace DDD\Symfony\Commands\Common\Crons;

use DDD\Domain\Common\Entities\Crons\CronCommandLine;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Lists every Cron with its state. A Cron whose command this console does not register (a release removed it) is
 * marked in the column `Command registered` and named in a warning below the table: the tick skips it, and it can be
 * switched off with `app:crons:deactivate` (or deleted) — switching it off never checks the command.
 */
#[AsCommand(
    name: 'app:crons:list',
    description: 'Lists all Cron Jobs',
    hidden: false
)]
class CronsList extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setDefaultAdminAccountAsAuthContext();
        putenv('SYMFONY_DEPRECATIONS_HELPER=disabled');
        $output->writeln([
            'Crons available:',
            '============',
        ]);
        $crons = Crons::getService()->list();


        $table = new Table($output);
        $table->setHeaders(
            [
                'Name',
                'Description',
                'Schedule',
                'Command',
                'Active',
                'Command registered',
                'Last Execution started at',
                'Next Execution scheduled at',
                'Created',
                'Updated'
            ]
        );

        $cronNamesWithUnregisteredCommand = [];
        foreach ($crons->getElements() as $cron) {
            $commandRegistered = $this->isCommandRegistered(CronCommandLine::fromCommand((string)($cron->command ?? ''))->commandName);
            if (!$commandRegistered) {
                $cronNamesWithUnregisteredCommand[] = $cron->name . ' (' . ($cron->isActive ? 'active' : 'inactive') . ', `' . $cron->command . '`)';
            }
            $table->addRow([
                $cron->name,
                $cron->description,
                $cron->schedule,
                $cron->command,
                $cron->isActive ? 'Yes' : 'No',
                $commandRegistered ? 'Yes' : 'NO',
                isset($cron->lastExecutionStartedAt) ? $cron->lastExecutionStartedAt->format('Y-m-d H:i:s') : '',
                isset($cron->nextExecutionScheduledAt) ? $cron->nextExecutionScheduledAt->format('Y-m-d H:i:s') : '',
                $cron->getCreatedTime()?->format('Y-m-d H:i:s'),
                $cron->getModifiedTime()?->format('Y-m-d H:i:s'),
            ]);
        }
        $table->render();
        if ($cronNamesWithUnregisteredCommand !== []) {
            (new SymfonyStyle($input, $output))->warning(
                count($cronNamesWithUnregisteredCommand) . ' Cron(s) with a command this console does not register: '
                . implode(', ', $cronNamesWithUnregisteredCommand)
                . '. The tick skips them; switch them off with app:crons:deactivate "<name>" or delete them.'
            );
        }
        return Command::SUCCESS;
    }

    /** The CLI admin may read every Cron; the seam a test replaces (the admin lives in the database). */
    protected function setDefaultAdminAccountAsAuthContext(): void
    {
        $defaultAdminAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        AuthService::instance()->setAccount($defaultAdminAccount);
    }

    /** Whether the console registers this command name: the application running this command, else the kernel's. */
    protected function isCommandRegistered(string $commandName): bool
    {
        if ($commandName === '') {
            return false;
        }
        try {
            $consoleApplication = $this->getApplication() ?? DDDService::instance()->createConsoleApplicationForCurrentKernel();
            return $consoleApplication->has($commandName);
        } catch (Throwable) {
            // the console cannot be built here: do not claim the command is missing
            return true;
        }
    }
}