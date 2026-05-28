<?php

namespace App\Command;

use App\Service\ImslpService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:imslp:sync', description: 'Sync IMSLP composers and/or works into the local database')]
class ImslpSyncCommand extends Command
{
    public function __construct(private readonly ImslpService $imslp)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED,
                'What to sync: composers, works, or all', 'all')
            ->addOption('start', null, InputOption::VALUE_REQUIRED,
                'Start offset for works sync (multiple of 1000)', 0)
            ->addOption('resume', null, InputOption::VALUE_NONE,
                'Resume works sync from the last known offset (rounds down to nearest 1000)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $type = $input->getOption('type');

        if (!in_array($type, ['composers', 'works', 'all'])) {
            $io->error('--type must be composers, works, or all');
            return Command::FAILURE;
        }

        if ($type === 'composers' || $type === 'all') {
            $io->section('Syncing composers…');
            $bar = new ProgressBar($output);
            $bar->start();
            $count = $this->imslp->syncComposers(function (int $n) use ($bar) { $bar->setProgress($n); });
            $bar->finish();
            $io->newLine();
            $io->success(sprintf('Synced %d composers.', $count));
        }

        if ($type === 'works' || $type === 'all') {
            $start = (int) $input->getOption('start');
            if ($input->getOption('resume')) {
                $start = $this->imslp->worksResumeOffset();
                $io->note(sprintf('Resuming from offset %d', $start));
            }

            $io->section('Syncing works…');
            $bar = new ProgressBar($output);
            $bar->start($start);
            $count = $this->imslp->syncWorks(function (int $n) use ($bar) { $bar->setProgress($n); }, $start);
            $bar->finish();
            $io->newLine();
            $io->success(sprintf('Synced %d works.', $count));
        }

        return Command::SUCCESS;
    }
}
