<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\UntrustedInput;

use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\UntrustedInputRuntimeCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:untrusted-input:check-runtime',
    description: 'Checks this runtime against the untrusted-input rule: ImageMagick refuses PS/EPS/PDF/SVG/MVG/MSL/TEXT, no Ghostscript on the PATH, PNG/JPEG work. Exits 1 when anything is unsafe or broken — for the image build, deploys and CI'
)]
class UntrustedInputCheckRuntime extends Command
{
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var UntrustedInputRuntimeCheckService $untrustedInputRuntimeCheckService */
        $untrustedInputRuntimeCheckService = DDDService::instance()->getService(UntrustedInputRuntimeCheckService::class);
        $untrustedInputRuntimeChecks = $untrustedInputRuntimeCheckService->checkRuntime();
        if ($input->getOption('format') === 'json') {
            $output->writeln((string)json_encode($untrustedInputRuntimeChecks->toObject(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $untrustedInputRuntimeChecks->isSafe() ? Command::SUCCESS : Command::FAILURE;
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        $tableRows = [];
        foreach ($untrustedInputRuntimeChecks->getElements() as $untrustedInputRuntimeCheck) {
            $tableRows[] = [$untrustedInputRuntimeCheck->status, $untrustedInputRuntimeCheck->name, $untrustedInputRuntimeCheck->detail];
        }
        $symfonyStyle->table(['Status', 'Check', 'Detail'], $tableRows);
        if (!$untrustedInputRuntimeChecks->isSafe()) {
            $symfonyStyle->error('This runtime interprets foreign bytes or lacks a raster coder (skill ddd-untrusted-input-specialist).');
            return Command::FAILURE;
        }
        $symfonyStyle->success('ImageMagick refuses every interpreter format, no Ghostscript on the PATH, raster coders work.');
        return Command::SUCCESS;
    }
}
