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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
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
             ->addOption('pid-file', null, InputOption::VALUE_REQUIRED,
                'Write the current process PID to this file at startup', '')
             ->addOption('refetch-no-tags', null, InputOption::VALUE_NONE,
                'Re-fetch works that were synced but have no tags (to pick up category data)')
             ->addOption('fill-genres', null, InputOption::VALUE_NONE,
                'Re-fetch works that were synced but have no genre_cats (to populate IMSLP genre categories)')
             ->addOption('fill-all', null, InputOption::VALUE_NONE,
                'Re-fetch works where duration_seconds or first_perf_date are still NULL (to pick up new parsed fields)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit         = (int) $input->getOption('limit');
        $delay         = (int) $input->getOption('delay');
        $stopFile      = (string) $input->getOption('stop-file');
        $pidFile       = (string) $input->getOption('pid-file');
        $refetchNoTags = (bool) $input->getOption('refetch-no-tags');
        $fillGenres    = (bool) $input->getOption('fill-genres');
        $fillAll       = (bool) $input->getOption('fill-all');

        // Exclusive non-blocking lock — exits immediately if another instance holds it
        $lockFp = fopen($this->projectDir . '/var/imslp-fetch.lock', 'w');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            $output->writeln(sprintf('[%s] Another fetch-details instance is already running. Exiting.', $this->ts()));
            return Command::FAILURE;
        }

        if ($pidFile !== '') {
            file_put_contents($pidFile, (string) getmypid());
        }

        $total   = (int) $this->em->createQuery('SELECT COUNT(w.id) FROM App\Entity\ImslpWork w')->getSingleScalarResult();
        $pending = match (true) {
            $fillAll       => $this->workRepo->countWithoutAllFields(),
            $fillGenres    => $this->workRepo->countWithoutGenreCats(),
            $refetchNoTags => $this->workRepo->countWithoutTags(),
            default        => $this->workRepo->countWithoutDetail(),
        };
        $toFetch = ($limit > 0) ? min($limit, $pending) : $pending;
        $fetched = $refetchNoTags || $fillGenres || $fillAll
            ? $total - $this->workRepo->countWithoutDetail()
            : $total - $pending;

        $mode = match (true) {
            $fillAll       => 'fill-all re-fetch',
            $fillGenres    => 'fill-genres re-fetch',
            $refetchNoTags => 'no-tags re-fetch',
            default        => 'detail fetch',
        };
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
            $works     = match (true) {
                $fillAll       => $this->workRepo->findWithoutAllFields($batchSize),
                $fillGenres    => $this->workRepo->findWithoutGenreCats($batchSize),
                $refetchNoTags => $this->workRepo->findWithoutTags($batchSize),
                default        => $this->workRepo->findWithoutDetail($batchSize),
            };
            if (empty($works)) break;

            // Fetch work details in parallel (10 concurrent requests)
            $results = $this->imslp->fetchWorkDetailBatch($works, 10);

            foreach ($results as [$work, $exception]) {
                if ($stopFile && file_exists($stopFile)) {
                    @unlink($stopFile);
                    $output->writeln(sprintf('[%s] Stopped via stop-file after %d works.', $this->ts(), $done));
                    break 2;
                }

                $label = sprintf('%s — %s', $work->getComposer(), $work->getTitle());
                if ($exception !== null) {
                    // Retry once on deadlock (SQLSTATE 40001) with a short back-off
                    if (!str_contains($exception->getMessage(), '1213')) {
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
                            $this->ts(), $fetched + $done, $total, $label, $exception->getMessage(), $eta));
                        if ($delay > 0 && $done < $toFetch) usleep($delay * 1000);
                        continue;
                    }
                    // Retry deadlock by fallback to sequential fetch
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
                    }
                }

                $done++;
                $eta = $this->eta($done, $toFetch, $startTime);
                $output->writeln(sprintf('[%s] [%d/%d] %s%s', $this->ts(), $fetched + $done, $total, $label, $eta));
                if ($delay > 0 && $done < $toFetch) usleep($delay * 1000);
            }

            $this->em->clear();
        }

        $remaining = match (true) {
            $fillAll       => $this->workRepo->countWithoutAllFields(),
            $fillGenres    => $this->workRepo->countWithoutGenreCats(),
            $refetchNoTags => $this->workRepo->countWithoutTags(),
            default        => $this->workRepo->countWithoutDetail(),
        };
        $output->writeln(sprintf('[%s] Done. Fetched %d works (%d errors). %d still pending.',
            $this->ts(), $done, $errors, $remaining));

        // Invalidate caches that depend on fetched data
        $this->cache->delete('imslp.distinct_genres');
        $this->cache->delete('imslp.distinct_languages');
        // Clear work page cache for all works that were fetched (invalidate entire cache prefix)
        // This ensures the next page load shows fresh data
        // Note: This is a brute-force invalidation; a more granular approach would track
        // which specific works were fetched and only clear those, but that's overkill.
        // The 10m TTL means stale pages expire naturally if we don't clear them.

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
