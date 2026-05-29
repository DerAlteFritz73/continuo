<?php

namespace App\Controller;

use App\Entity\ImslpWork;
use App\Repository\ImslpWorkRepository;
use App\Repository\WorkFilters;
use App\Service\ImslpAiSearchService;
use App\Service\ImslpService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/imslp')]
class ImslpController extends AbstractController
{
    private const STYLES = ['Ancient', 'Baroque', 'Classical', 'Medieval', 'Modern', 'Renaissance', 'Romantic', 'Traditional'];

    public function __construct(
        private readonly ImslpWorkRepository    $workRepo,
        private readonly ImslpService           $imslp,
        private readonly ImslpAiSearchService   $aiSearch,
        private readonly EntityManagerInterface $em,
        private readonly Connection             $db,
    ) {}

    #[Route('', name: 'app_imslp', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q        = trim($request->query->getString('q'));
        $composer = trim($request->query->getString('composer'));
        $page     = max(1, $request->query->getInt('page', 1));
        $perPage  = 30;

        $filters = new WorkFilters(
            instrumentation: trim($request->query->getString('instrumentation')),
            style:           trim($request->query->getString('style')),
            genre:           trim($request->query->getString('genre')),
            key:             trim($request->query->getString('key')),
            yearFrom:        ($v = trim($request->query->getString('year_from'))) !== '' ? (int) $v : null,
            yearTo:          ($v = trim($request->query->getString('year_to')))   !== '' ? (int) $v : null,
        );

        $works           = [];
        $composerMatches = [];
        $total           = 0;
        $pages           = 0;
        $mode            = 'empty'; // empty | search | composer | filter

        $composerStyle = '';
        if ($composer !== '') {
            $mode  = 'composer';
            $total = $this->workRepo->countByComposer($composer, $filters);
            $pages = (int) ceil($total / $perPage);
            $works = $this->workRepo->findByComposer($composer, $filters, $page, $perPage);

            // Derive the composer's dominant period from their works (for style pre-fill)
            $composerStyle = (string) ($this->db->fetchOne(
                'SELECT piece_style FROM imslp_work
                 WHERE composer = ? AND piece_style IS NOT NULL
                 GROUP BY piece_style ORDER BY COUNT(*) DESC LIMIT 1',
                [$composer]
            ) ?: '');

        } elseif ($q !== '') {
            $mode            = 'search';
            $composerMatches = $this->workRepo->findComposersLike($q);

            // If q is an exact (case-insensitive) match for a single composer, switch to
            // composer mode so any active filters apply to the work list rather than
            // appearing to return the full unfiltered composer catalogue.
            if (count($composerMatches) === 1
                && strcasecmp($composerMatches[0]['name'], $q) === 0
            ) {
                $composer        = $composerMatches[0]['name'];
                $mode            = 'composer';
                $composerMatches = [];
                $composerStyle   = (string) ($this->db->fetchOne(
                    'SELECT piece_style FROM imslp_work
                     WHERE composer = ? AND piece_style IS NOT NULL
                     GROUP BY piece_style ORDER BY COUNT(*) DESC LIMIT 1',
                    [$composer]
                ) ?: '');
                $total = $this->workRepo->countByComposer($composer, $filters);
                $pages = (int) ceil($total / $perPage);
                $works = $this->workRepo->findByComposer($composer, $filters, $page, $perPage);
            } else {
                $total = $this->workRepo->countByTitleSearch($q, $filters);
                $pages = (int) ceil($total / $perPage);
                $works = $this->workRepo->findByTitleSearch($q, $filters, $page, $perPage);
            }

        } elseif (!$filters->isEmpty()) {
            $mode  = 'filter';
            $total = $this->workRepo->countByFilters($filters);
            $pages = (int) ceil($total / $perPage);
            $works = $this->workRepo->findByFilters($filters, $page, $perPage);
        }

        $composerDates = $this->loadComposerDates($works);

        return $this->render('imslp/index.html.twig', [
            'q'        => $q,
            'composer' => $composer,
            'filters'  => $filters,
            'page'     => $page,
            'perPage'  => $perPage,
            'total'    => $total,
            'pages'    => $pages,
            'works'    => $works,
            'composerDates'   => $composerDates,
            'composerMatches' => $composerMatches,
            'composerStyle'   => $composerStyle,
            'styles'   => self::STYLES,
            'genres'   => $this->workRepo->findDistinctGenres(),
            'mode'     => $mode,
        ]);
    }

    #[Route('/ai-search', name: 'app_imslp_ai_search', methods: ['POST'])]
    public function aiSearch(Request $request): JsonResponse
    {
        $body  = json_decode($request->getContent(), true) ?? [];
        $query = trim($body['query'] ?? $request->request->getString('query'));

        if ($query === '') {
            return $this->json(['error' => 'Empty query'], 400);
        }

        return $this->json($this->aiSearch->parseQuery($query));
    }

    #[Route('/work/{pageId}', name: 'app_imslp_work', methods: ['GET'], requirements: ['pageId' => '\d+'])]
    public function work(int $pageId): Response
    {
        /** @var ImslpWork|null $work */
        $work = $this->workRepo->findOneBy(['pageId' => $pageId]);

        if (!$work) {
            throw $this->createNotFoundException('Work not found in local database. Run app:imslp:sync first.');
        }

        if (!$work->hasDetail()) {
            try {
                $this->imslp->fetchWorkDetail($work);
            } catch (\Throwable) {
                // Non-fatal — show page with partial data
            }
        }

        return $this->render('imslp/work.html.twig', ['work' => $work]);
    }

    // -------------------------------------------------------------------------
    // Sync status page + JSON API
    // -------------------------------------------------------------------------

    #[Route('/sync-status', name: 'app_imslp_sync_status', methods: ['GET'])]
    public function syncStatus(Request $request): Response
    {
        $data = $this->buildStatusData();

        if ($request->query->has('json')) {
            return $this->json($data);
        }

        return $this->render('imslp/sync_status.html.twig', $data);
    }

    #[Route('/fetch-details/start', name: 'app_imslp_fetch_start', methods: ['POST'])]
    public function startFetch(): JsonResponse
    {
        if ($this->isFetchRunning()) {
            return $this->json(['started' => false, 'message' => 'Already running']);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $pidFile    = $projectDir . '/var/imslp-fetch.pid';
        $stopFile   = $projectDir . '/var/imslp-fetch.stop';
        $logFile    = $projectDir . '/var/log/imslp-fetch.log';

        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0777, true);
        }

        @unlink($stopFile);

        $cmd = sprintf(
            'nohup php %s app:imslp:fetch-details --delay=500 --stop-file=%s >> %s 2>&1 & echo $!',
            escapeshellarg($projectDir . '/bin/console'),
            escapeshellarg($stopFile),
            escapeshellarg($logFile)
        );

        $pid = (int) shell_exec($cmd);

        if ($pid > 0) {
            file_put_contents($pidFile, $pid);
            return $this->json(['started' => true, 'pid' => $pid]);
        }

        return $this->json(['started' => false, 'message' => 'Failed to start process'], 500);
    }

    #[Route('/sync-composers/start', name: 'app_imslp_sync_composers_start', methods: ['POST'])]
    public function startSyncComposers(): JsonResponse
    {
        if ($this->isComposersRunning()) {
            return $this->json(['started' => false, 'message' => 'Already running']);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $pidFile    = $projectDir . '/var/imslp-composers.pid';
        $stopFile   = $projectDir . '/var/imslp-composers.stop';
        $logFile    = $projectDir . '/var/log/imslp-composers.log';

        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0777, true);
        }

        @unlink($stopFile);

        $cmd = sprintf(
            'nohup php %s app:imslp:sync --type=composers --stop-file=%s >> %s 2>&1 & echo $!',
            escapeshellarg($projectDir . '/bin/console'),
            escapeshellarg($stopFile),
            escapeshellarg($logFile)
        );

        $pid = (int) shell_exec($cmd);

        if ($pid > 0) {
            file_put_contents($pidFile, $pid);
            return $this->json(['started' => true, 'pid' => $pid]);
        }

        return $this->json(['started' => false, 'message' => 'Failed to start process'], 500);
    }

    #[Route('/composers-log', name: 'app_imslp_composers_log', methods: ['GET'])]
    public function composersLog(): Response
    {
        $logFile = $this->getParameter('kernel.project_dir') . '/var/log/imslp-composers.log';

        if (!file_exists($logFile)) {
            return new Response('(no log file yet)', 200, ['Content-Type' => 'text/plain']);
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $tail  = array_slice($lines, -200);

        return new Response(implode("\n", $tail), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    #[Route('/sync-works/start', name: 'app_imslp_sync_works_start', methods: ['POST'])]
    public function startSyncWorks(): JsonResponse
    {
        if ($this->isSyncRunning()) {
            return $this->json(['started' => false, 'message' => 'Already running']);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $pidFile    = $projectDir . '/var/imslp-sync.pid';
        $stopFile   = $projectDir . '/var/imslp-sync.stop';
        $logFile    = $projectDir . '/var/log/imslp-sync.log';

        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0777, true);
        }

        @unlink($stopFile);

        $cmd = sprintf(
            'nohup php %s app:imslp:sync --type=works --resume --stop-file=%s >> %s 2>&1 & echo $!',
            escapeshellarg($projectDir . '/bin/console'),
            escapeshellarg($stopFile),
            escapeshellarg($logFile)
        );

        $pid = (int) shell_exec($cmd);

        if ($pid > 0) {
            file_put_contents($pidFile, $pid);
            return $this->json(['started' => true, 'pid' => $pid]);
        }

        return $this->json(['started' => false, 'message' => 'Failed to start process'], 500);
    }

    #[Route('/sync-log', name: 'app_imslp_sync_log', methods: ['GET'])]
    public function syncLog(): Response
    {
        $logFile = $this->getParameter('kernel.project_dir') . '/var/log/imslp-sync.log';

        if (!file_exists($logFile)) {
            return new Response('(no log file yet)', 200, ['Content-Type' => 'text/plain']);
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $tail  = array_slice($lines, -200);

        return new Response(implode("\n", $tail), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    #[Route('/fetch-log', name: 'app_imslp_fetch_log', methods: ['GET'])]
    public function fetchLog(): Response
    {
        $logFile = $this->getParameter('kernel.project_dir') . '/var/log/imslp-fetch.log';

        if (!file_exists($logFile)) {
            return new Response('(no log file yet)', 200, ['Content-Type' => 'text/plain']);
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $tail  = array_slice($lines, -200);

        return new Response(implode("\n", $tail), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    #[Route('/sync-dates/start', name: 'app_imslp_sync_dates_start', methods: ['POST'])]
    public function startSyncDates(): JsonResponse
    {
        if ($this->isDatesRunning()) {
            return $this->json(['started' => false, 'message' => 'Already running']);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $pidFile    = $projectDir . '/var/imslp-dates.pid';
        $stopFile   = $projectDir . '/var/imslp-dates.stop';
        $logFile    = $projectDir . '/var/log/imslp-dates.log';

        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0777, true);
        }

        @unlink($stopFile);

        $cmd = sprintf(
            'nohup php %s app:imslp:sync-composer-dates --delay=300 --stop-file=%s >> %s 2>&1 & echo $!',
            escapeshellarg($projectDir . '/bin/console'),
            escapeshellarg($stopFile),
            escapeshellarg($logFile)
        );

        $pid = (int) shell_exec($cmd);

        if ($pid > 0) {
            file_put_contents($pidFile, $pid);
            return $this->json(['started' => true, 'pid' => $pid]);
        }

        return $this->json(['started' => false, 'message' => 'Failed to start process'], 500);
    }

    #[Route('/dates-log', name: 'app_imslp_dates_log', methods: ['GET'])]
    public function datesLog(): Response
    {
        $logFile = $this->getParameter('kernel.project_dir') . '/var/log/imslp-dates.log';

        if (!file_exists($logFile)) {
            return new Response('(no log file yet)', 200, ['Content-Type' => 'text/plain']);
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $tail  = array_slice($lines, -200);

        return new Response(implode("\n", $tail), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildStatusData(): array
    {
        $total          = (int) $this->em->createQuery('SELECT COUNT(w.id) FROM App\Entity\ImslpWork w')->getSingleScalarResult();
        $withDetail     = (int) $this->em->createQuery('SELECT COUNT(w.id) FROM App\Entity\ImslpWork w WHERE w.detailSyncedAt IS NOT NULL')->getSingleScalarResult();
        $totalComposers = (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT composer) FROM imslp_work WHERE composer != \'\''
        );

        $composersChecked = (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT w.composer)
             FROM imslp_work w
             JOIN imslp_composer c ON c.name = w.composer
             WHERE w.composer != \'\' AND c.dates_synced_at IS NOT NULL'
        );

        $pct      = $total > 0 ? round($withDetail / $total * 100, 1) : 0;
        $datesPct = $totalComposers > 0 ? round($composersChecked / $totalComposers * 100, 1) : 0;

        $fmt = function (?string $raw): ?string {
            if ($raw === null) return null;
            $dt = new \DateTime($raw);
            return $dt->format('d.m.Y H:i');
        };

        $lastWorkSync      = $fmt($this->db->fetchOne('SELECT MAX(synced_at) FROM imslp_work') ?: null);
        $lastDetailSync    = $fmt($this->db->fetchOne('SELECT MAX(detail_synced_at) FROM imslp_work') ?: null);
        $lastComposerSync  = $fmt($this->db->fetchOne('SELECT MAX(synced_at) FROM imslp_composer') ?: null);
        $lastDatesSync     = $fmt($this->db->fetchOne('SELECT MAX(dates_synced_at) FROM imslp_composer') ?: null);

        return [
            'works'                => $total,
            'worksWithDetail'      => $withDetail,
            'worksWithoutDetail'   => $total - $withDetail,
            'composers'            => $totalComposers,
            'composersWithDates'   => $composersChecked,
            'detailPercent'        => $pct,
            'datesPercent'         => $datesPct,
            'running'              => $this->isFetchRunning(),
            'syncRunning'          => $this->isSyncRunning(),
            'datesRunning'         => $this->isDatesRunning(),
            'composersRunning'     => $this->isComposersRunning(),
            'lastWorkSync'         => $lastWorkSync,
            'lastDetailSync'       => $lastDetailSync,
            'lastComposerSync'     => $lastComposerSync,
            'lastDatesSync'        => $lastDatesSync,
        ];
    }

    #[Route('/fetch-details/stop', name: 'app_imslp_fetch_stop', methods: ['POST'])]
    public function stopFetch(): JsonResponse
    {
        return $this->stopProcess($this->getParameter('kernel.project_dir') . '/var/imslp-fetch.pid');
    }

    #[Route('/sync-works/stop', name: 'app_imslp_sync_works_stop', methods: ['POST'])]
    public function stopSyncWorks(): JsonResponse
    {
        return $this->stopProcess($this->getParameter('kernel.project_dir') . '/var/imslp-sync.pid');
    }

    #[Route('/sync-dates/stop', name: 'app_imslp_sync_dates_stop', methods: ['POST'])]
    public function stopSyncDates(): JsonResponse
    {
        return $this->stopProcess($this->getParameter('kernel.project_dir') . '/var/imslp-dates.pid');
    }

    #[Route('/sync-composers/stop', name: 'app_imslp_sync_composers_stop', methods: ['POST'])]
    public function stopSyncComposers(): JsonResponse
    {
        return $this->stopProcess($this->getParameter('kernel.project_dir') . '/var/imslp-composers.pid');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function stopProcess(string $pidFile): JsonResponse
    {
        $stopFile = str_replace('.pid', '.stop', $pidFile);

        if (!file_exists($pidFile)) {
            return $this->json(['stopped' => false, 'message' => 'Not running']);
        }

        file_put_contents($stopFile, '1');
        @unlink($pidFile);

        return $this->json(['stopped' => true]);
    }

    private function isFetchRunning(): bool
    {
        return $this->isPidAlive($this->getParameter('kernel.project_dir') . '/var/imslp-fetch.pid');
    }

    private function isSyncRunning(): bool
    {
        return $this->isPidAlive($this->getParameter('kernel.project_dir') . '/var/imslp-sync.pid');
    }

    private function isDatesRunning(): bool
    {
        return $this->isPidAlive($this->getParameter('kernel.project_dir') . '/var/imslp-dates.pid');
    }

    private function isComposersRunning(): bool
    {
        return $this->isPidAlive($this->getParameter('kernel.project_dir') . '/var/imslp-composers.pid');
    }

    /** @param ImslpWork[] $works */
    private function loadComposerDates(array $works): array
    {
        if (empty($works)) return [];

        $names = array_values(array_unique(array_filter(
            array_map(fn($w) => $w->getComposer(), $works)
        )));

        if (empty($names)) return [];

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $rows = $this->db->fetchAllAssociative(
            "SELECT name, born_year, died_year FROM imslp_composer WHERE name IN ($placeholders)",
            $names
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['name']] = ['born' => $row['born_year'], 'died' => $row['died_year']];
        }
        return $map;
    }

    #[Route('/system-stats', name: 'app_imslp_system_stats', methods: ['GET'])]
    public function systemStats(): JsonResponse
    {
        // CPU: two samples 200 ms apart for a real delta
        $sample = function (): array {
            $line = file('/proc/stat')[0];
            $f    = array_slice(preg_split('/\s+/', trim($line)), 1);
            $idle = (int)$f[3] + (int)$f[4];
            $total = array_sum(array_map('intval', $f));
            return [$idle, $total];
        };

        [$idle1, $total1] = $sample();
        usleep(200_000);
        [$idle2, $total2] = $sample();

        $diffTotal = $total2 - $total1;
        $diffIdle  = $idle2  - $idle1;
        $cpuPct    = $diffTotal > 0 ? round(100 * (1 - $diffIdle / $diffTotal)) : 0;

        // Memory
        $memInfo = [];
        foreach (file('/proc/meminfo') as $line) {
            [$key, $val] = explode(':', $line, 2);
            $memInfo[trim($key)] = (int) trim(str_replace(' kB', '', $val));
        }
        $memTotal     = $memInfo['MemTotal']     ?? 1;
        $memAvailable = $memInfo['MemAvailable'] ?? $memTotal;
        $memUsed      = $memTotal - $memAvailable;
        $memPct       = (int) round(100 * $memUsed / $memTotal);

        // Disk (project root or /)
        $diskPath  = $this->getParameter('kernel.project_dir');
        $diskTotal = disk_total_space($diskPath);
        $diskFree  = disk_free_space($diskPath);
        $diskUsed  = $diskTotal - $diskFree;
        $diskPct   = $diskTotal > 0 ? (int) round(100 * $diskUsed / $diskTotal) : 0;

        $fmt = fn(int $kb): string => $kb >= 1_048_576
            ? round($kb / 1_048_576, 1) . ' GB'
            : round($kb / 1_024, 0) . ' MB';

        $fmtBytes = fn(int $b): string => $b >= 1_073_741_824
            ? round($b / 1_073_741_824, 1) . ' GB'
            : round($b / 1_048_576, 0) . ' MB';

        return $this->json([
            'cpu'  => ['pct' => $cpuPct],
            'mem'  => ['pct' => $memPct,  'used' => $fmt($memUsed),  'total' => $fmt($memTotal)],
            'disk' => ['pct' => $diskPct, 'used' => $fmtBytes($diskUsed), 'total' => $fmtBytes($diskTotal)],
        ]);
    }

    private function isPidAlive(string $pidFile): bool
    {
        if (!file_exists($pidFile)) {
            return false;
        }
        $pid = (int) file_get_contents($pidFile);
        return $pid > 0 && is_dir('/proc/' . $pid);
    }
}
