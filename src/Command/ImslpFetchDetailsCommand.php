<?php

namespace App\Command;

use App\Repository\ImslpWorkRepository;
use App\Service\ImslpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Cache\CacheInterface;

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
        private readonly CacheInterface         $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED,
                'Maximum number of works to fetch in this run (0 = all)', 0)
             ->addOption('delay', null, InputOption::VALUE_REQUIRED,
                'Milliseconds to sleep between requests', 300)
             ->addOption('stop-file', null, InputOption::VALUE_REQUIRED,
                'Path to a stop-file; process exits gracefully when the file appears', '')
             ->addOption('refetch-no-tags', null, InputOption::VALUE_NONE,
                'Re-fetch works that were synced but have no tags (to pick up category data)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit        = (int) $input->getOption('limit');
        $delay        = (int) $input->getOption('delay');
        $stopFile     = (string) $input->getOption('stop-file');
        $refetchNoTags = (bool) $input->getOption('refetch-no-tags');

        $total   = (int) $this->em->createQuery('SELECT COUNT(w.id) FROM App\Entity\ImslpWork w')->getSingleScalarResult();
        $pending = $refetchNoTags
            ? $this->workRepo->countWithoutTags()
            : $this->workRepo->countWithoutDetail();
        $toFetch = ($limit > 0) ? min($limit, $pending) : $pending;
        $fetched = $refetchNoTags
            ? $total - $this->workRepo->countWithoutDetail()
            : $total - $pending;

        $mode = $refetchNoTags ? 'no-tags re-fetch' : 'detail fetch';
        $output->writeln(sprintf('[%s] %s mode: %d/%d works have detail data; %d pending. Fetching %s now (batch=%d, delay=%dms).',
            $this->ts(),
            $mode,
            $fetched,
            $total,
            $pending,
            $toFetch === $pending ? 'all remaining' : $toFetch,
            self::BATCH,
            $delay
        ));

        if ($toFetch === 0) {
            $output->writeln(sprintf('[%s] Nothing to fetch.', $this->ts()));
            return Command::SUCCESS;
        }

        $done      = 0;
        $errors    = 0;
        $startTime = microtime(true);

        while ($done < $toFetch) {
            $batchSize = min(self::BATCH, $toFetch - $done);
            $works     = $refetchNoTags
                ? $this->workRepo->findWithoutTags($batchSize)
                : $this->workRepo->findWithoutDetail($batchSize);
            if (empty($works)) break;

            foreach ($works as $work) {
                if ($stopFile && file_exists($stopFile)) {
                    @unlink($stopFile);
                    $output->writeln(sprintf('[%s] Stopped via stop-file after %d works.', $this->ts(), $done));
                    break 2;
                }

                $label = sprintf('%s — %s', $work->getComposer(), $work->getTitle());
                try {
                    $this->imslp->fetchWorkDetail($work);
                } catch (\Throwable $e) {
                    try {
                        $this->em->getConnection()->executeStatement(
                            'UPDATE imslp_work SET detail_synced_at = ? WHERE page_id = ?',
                            [date('Y-m-d H:i:s'), $work->getPageId()]
                        );
                    } catch (\Throwable) {}
                    $errors++;
                    $done++;
                    $eta = $this->eta($done, $toFetch, $startTime);
                    $output->writeln(sprintf('[%s] [%d/%d] SKIP %s (%s)%s',
                        $this->ts(), $fetched + $done, $total, $label, $e->getMessage(), $eta));
                    if ($delay > 0 && $done < $toFetch) usleep($delay * 1000);
                    continue;
                }
                $done++;
                $eta = $this->eta($done, $toFetch, $startTime);
                $output->writeln(sprintf('[%s] [%d/%d] %s%s', $this->ts(), $fetched + $done, $total, $label, $eta));
                if ($delay > 0 && $done < $toFetch) usleep($delay * 1000);
            }

            $this->em->clear();
        }

        $remaining = $refetchNoTags
            ? $this->workRepo->countWithoutTags()
            : $this->workRepo->countWithoutDetail();
        $output->writeln(sprintf('[%s] Done. Fetched %d works (%d errors). %d still pending.',
            $this->ts(), $done, $errors, $remaining));

        $this->cache->delete('imslp.distinct_genres');

        return Command::SUCCESS;
    }

    private function eta(int $done, int $total, float $startTime): string
    {
        $elapsed = microtime(true) - $startTime;
        if ($elapsed < 0.5 || $done === 0) return '';
        $remaining = ($total - $done) * $elapsed / $done;
        return '  ETA ' . $this->formatDuration($remaining);
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) return '< 1 min';
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) return sprintf('~%d min', $minutes);
        $hours = (int) ($minutes / 60);
        $mins  = $minutes % 60;
        if ($hours < 24) return $mins > 0 ? sprintf('~%dh%02dm', $hours, $mins) : sprintf('~%dh', $hours);
        $days = (int) ($hours / 24);
        $hrs  = $hours % 24;
        return $hrs > 0 ? sprintf('~%dd%dh', $days, $hrs) : sprintf('~%dd', $days);
    }

    private function ts(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i:s');
    }
}
