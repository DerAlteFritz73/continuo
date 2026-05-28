<?php

namespace App\Controller;

use App\Entity\ImslpWork;
use App\Repository\ImslpWorkRepository;
use App\Repository\WorkFilters;
use App\Service\ImslpAiSearchService;
use App\Service\ImslpService;
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
            yearFrom:        $request->query->getInt('year_from') ?: null,
            yearTo:          $request->query->getInt('year_to')   ?: null,
        );

        $works           = [];
        $composerMatches = [];
        $total           = 0;
        $pages           = 0;
        $mode            = 'empty'; // empty | search | composer | filter

        if ($composer !== '') {
            $mode  = 'composer';
            $total = $this->workRepo->countByComposer($composer, $filters);
            $pages = (int) ceil($total / $perPage);
            $works = $this->workRepo->findByComposer($composer, $filters, $page, $perPage);

        } elseif ($q !== '') {
            $mode            = 'search';
            $composerMatches = $this->workRepo->findComposersLike($q);
            $total           = $this->workRepo->countByTitleSearch($q, $filters);
            $pages           = (int) ceil($total / $perPage);
            $works           = $this->workRepo->findByTitleSearch($q, $filters, $page, $perPage);

        } elseif (!$filters->isEmpty()) {
            $mode  = 'filter';
            $total = $this->workRepo->countByFilters($filters);
            $pages = (int) ceil($total / $perPage);
            $works = $this->workRepo->findByFilters($filters, $page, $perPage);
        }

        return $this->render('imslp/index.html.twig', [
            'q'        => $q,
            'composer' => $composer,
            'filters'  => $filters,
            'page'     => $page,
            'perPage'  => $perPage,
            'total'    => $total,
            'pages'    => $pages,
            'works'    => $works,
            'composerMatches' => $composerMatches,
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
        $logFile    = $projectDir . '/var/log/imslp-fetch.log';

        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0777, true);
        }

        $cmd = sprintf(
            'nohup php %s app:imslp:fetch-details --delay=500 >> %s 2>&1 & echo $!',
            escapeshellarg($projectDir . '/bin/console'),
            escapeshellarg($logFile)
        );

        $pid = (int) shell_exec($cmd);

        if ($pid > 0) {
            file_put_contents($pidFile, $pid);
            return $this->json(['started' => true, 'pid' => $pid]);
        }

        return $this->json(['started' => false, 'message' => 'Failed to start process'], 500);
    }

    #[Route('/sync-works/start', name: 'app_imslp_sync_works_start', methods: ['POST'])]
    public function startSyncWorks(): JsonResponse
    {
        if ($this->isSyncRunning()) {
            return $this->json(['started' => false, 'message' => 'Already running']);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $pidFile    = $projectDir . '/var/imslp-sync.pid';
        $logFile    = $projectDir . '/var/log/imslp-sync.log';

        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0777, true);
        }

        $cmd = sprintf(
            'nohup php %s app:imslp:sync --type=works --resume >> %s 2>&1 & echo $!',
            escapeshellarg($projectDir . '/bin/console'),
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
        $tail  = array_slice($lines, -50);

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
        $tail  = array_slice($lines, -50);

        return new Response(implode("\n", $tail), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildStatusData(): array
    {
        $total          = (int) $this->em->createQuery('SELECT COUNT(w.id) FROM App\Entity\ImslpWork w')->getSingleScalarResult();
        $withDetail     = (int) $this->em->createQuery('SELECT COUNT(w.id) FROM App\Entity\ImslpWork w WHERE w.detailSyncedAt IS NOT NULL')->getSingleScalarResult();
        $totalComposers = (int) $this->em->createQuery('SELECT COUNT(c.id) FROM App\Entity\ImslpComposer c')->getSingleScalarResult();

        $pct = $total > 0 ? round($withDetail / $total * 100, 1) : 0;

        return [
            'works'              => $total,
            'worksWithDetail'    => $withDetail,
            'worksWithoutDetail' => $total - $withDetail,
            'composers'          => $totalComposers,
            'detailPercent'      => $pct,
            'running'            => $this->isFetchRunning(),
            'syncRunning'        => $this->isSyncRunning(),
        ];
    }

    private function isFetchRunning(): bool
    {
        return $this->isPidAlive($this->getParameter('kernel.project_dir') . '/var/imslp-fetch.pid');
    }

    private function isSyncRunning(): bool
    {
        return $this->isPidAlive($this->getParameter('kernel.project_dir') . '/var/imslp-sync.pid');
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
