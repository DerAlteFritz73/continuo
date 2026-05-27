<?php

namespace App\Command;

use App\Repository\ImslpWorkRepository;
use App\Service\ImslpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:imslp:fetch-details',
    description: 'Fetch instrumentation and file details for IMSLP works lacking detail data',
)]
class ImslpFetchDetailsCommand extends Command
{
    private const BATCH = 50;

    public function __construct(
        private readonly ImslpService           $imslp,
        private readonly ImslpWorkRepository    $workRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED,
                'Maximum number of works to fetch in this run (0 = all)', 0)
             ->addOption('delay', null, InputOption::VALUE_REQUIRED,
                'Milliseconds to sleep between requests', 300);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $delay = (int) $input->getOption('delay');

        $pending = $this->workRepo->countWithoutDetail();
        $toFetch = ($limit > 0) ? min($limit, $pending) : $pending;

        $io->info(sprintf('%d works need detail data. Fetching %s now (batch=%d, delay=%dms).',
            $pending,
            $toFetch === $pending ? 'all' : $toFetch,
            self::BATCH,
            $delay
        ));

        if ($toFetch === 0) {
            $io->success('Nothing to fetch — all works already have detail data.');
            return Command::SUCCESS;
        }

        $bar    = new ProgressBar($output, $toFetch);
        $bar->start();
        $done   = 0;
        $errors = 0;

        while ($done < $toFetch) {
            $batchSize = min(self::BATCH, $toFetch - $done);
            $works     = $this->workRepo->findWithoutDetail($batchSize);
            if (empty($works)) break;

            foreach ($works as $work) {
                try {
                    $this->imslp->fetchWorkDetail($work);
                } catch (\Throwable) {
                    // Mark as attempted via DBAL so we don't retry broken pages endlessly
                    try {
                        $this->em->getConnection()->executeStatement(
                            'UPDATE imslp_work SET detail_synced_at = ? WHERE page_id = ?',
                            [date('Y-m-d H:i:s'), $work->getPageId()]
                        );
                    } catch (\Throwable) {}
                    $errors++;
                }
                $bar->advance();
                $done++;
                if ($delay > 0 && $done < $toFetch) usleep($delay * 1000);
            }

            // Release entities from memory between batches
            $this->em->clear();
        }

        $bar->finish();
        $io->newLine();

        $remaining = $this->workRepo->countWithoutDetail();
        if ($errors > 0) {
            $io->warning(sprintf('Fetched %d works with %d errors (network/parse failures marked as done).', $done, $errors));
        } else {
            $io->success(sprintf('Fetched details for %d works.', $done));
        }
        if ($remaining > 0) {
            $io->note(sprintf('%d works still pending.', $remaining));
        }

        return Command::SUCCESS;
    }
}
