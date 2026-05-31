<?php

namespace App\Command;

use App\Service\ImslpService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsCommand(name: 'app:imslp:sync', description: 'Sync IMSLP composers and/or works into the local database')]
class ImslpSyncCommand extends Command
{
    public function __construct(
        private readonly ImslpService  $imslp,
        private readonly CacheInterface $cache,
    ) {
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
                'Resume works sync from the last known offset (rounds down to nearest 1000)')
            ->addOption('stop-file', null, InputOption::VALUE_REQUIRED,
                'Path to a stop-file; process exits gracefully when the file appears', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type     = $input->getOption('type');
        $stopFile = (string) $input->getOption('stop-file');

        if (!in_array($type, ['composers', 'works', 'all'])) {
            $output->writeln(sprintf('[%s] ERROR: --type must be composers, works, or all', $this->ts()));
            return Command::FAILURE;
        }

        if ($type === 'composers' || $type === 'all') {
            $output->writeln(sprintf('[%s] Starting composer sync…', $this->ts()));

            $count = $this->imslp->syncComposers(
                function (int $total, string $lastName) use ($output, $stopFile) {
                    $output->writeln(sprintf('[%s] Fetched %d composers — last: %s', $this->ts(), $total, $lastName));

                    if ($stopFile && file_exists($stopFile)) {
                        @unlink($stopFile);
                        $output->writeln(sprintf('[%s] Stopped via stop-file.', $this->ts()));
                        return false;
                    }

                    return true;
                }
            );

            $output->writeln(sprintf('[%s] Done. Synced %d composers.', $this->ts(), $count));
        }

        if ($type === 'works' || $type === 'all') {
            $start = (int) $input->getOption('start');
            if ($input->getOption('resume')) {
                $start = $this->imslp->worksResumeOffset();
                $output->writeln(sprintf('[%s] Resuming from offset %d', $this->ts(), $start));
            }

            $output->writeln(sprintf('[%s] Starting works sync from offset %d…', $this->ts(), $start));

            $count = $this->imslp->syncWorks(
                function (int $total, string $lastItem) use ($output, $stopFile) {
                    $output->writeln(sprintf('[%s] Fetched %d works — last: %s', $this->ts(), $total, $lastItem));

                    if ($stopFile && file_exists($stopFile)) {
                        @unlink($stopFile);
                        $output->writeln(sprintf('[%s] Stopped via stop-file.', $this->ts()));
                        return false;
                    }

                    return true;
                },
                $start
            );

            $output->writeln(sprintf('[%s] Done. Synced %d works.', $this->ts(), $count));
        }

        $this->cache->delete('imslp.distinct_genres');

        return Command::SUCCESS;
    }

    private function ts(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
    }
}
