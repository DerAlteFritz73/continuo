<?php

namespace App\Command;

use App\Repository\ImslpWorkRepository;
use App\Repository\WorkFilters;
use App\Service\ImslpService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:imslp:prefetch-composers',
    description: 'Prefetch details for popular composers to warm cache',
)]
class ImslpPrefetchComposersCommand extends Command
{
    public function __construct(
        private readonly ImslpService $imslp,
        private readonly ImslpWorkRepository $workRepo,
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED,
            'Number of top composers to prefetch (0 = top 50)', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        if ($limit === 0) $limit = 50;

        $output->writeln(sprintf('[%s] Prefetching details for top %d composers...', $this->ts(), $limit));

        // Find top composers by work count
        $composers = $this->db->fetchAllAssociative(
            'SELECT w.composer, COUNT(w.id) AS cnt
             FROM imslp_work w
             WHERE w.composer != \'\'
             GROUP BY w.composer
             ORDER BY cnt DESC
             LIMIT ?',
            [$limit],
            ['integer']
        );

        if (empty($composers)) {
            $output->writeln(sprintf('[%s] No composers found', $this->ts()));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('[%s] Found %d composers, prefetching...', $this->ts(), count($composers)));

        $done = 0;
        foreach ($composers as $row) {
            $composer = $row['composer'];
            $count = (int) $row['cnt'];

            // Prefetch works for this composer (all pages)
            $pages = (int) ceil($count / 30);
            for ($page = 1; $page <= $pages; $page++) {
                $this->workRepo->findByComposer($composer, new WorkFilters(), $page, 30);
            }

            // Prefetch composer dates
            $this->imslp->fetchComposerDates($composer);

            $done++;
            if ($done % 10 === 0) {
                $output->writeln(sprintf('[%s]   %d/%d composers...', $this->ts(), $done, count($composers)));
            }
        }

        $this->em->clear();

        $output->writeln(sprintf('[%s] ✓ Prefetch complete (%d composers, %d total pages cached)',
            $this->ts(),
            count($composers),
            array_reduce($composers, fn($carry, $row) => $carry + (int) ceil((int) $row['cnt'] / 30), 0)
        ));
        $output->writeln('  Cache is now warm for instant loads of popular composers');

        return Command::SUCCESS;
    }

    private function ts(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
    }
}
