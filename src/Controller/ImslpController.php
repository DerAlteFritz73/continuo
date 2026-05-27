<?php

namespace App\Controller;

use App\Entity\ImslpWork;
use App\Repository\ImslpWorkRepository;
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
    public function __construct(
        private readonly ImslpWorkRepository  $workRepo,
        private readonly ImslpService         $imslp,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_imslp', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q               = trim($request->query->getString('q'));
        $composer        = trim($request->query->getString('composer'));
        $instrumentation = trim($request->query->getString('instrumentation'));
        $style           = trim($request->query->getString('style'));
        $page            = max(1, $request->query->getInt('page', 1));
        $perPage         = 30;

        $works           = [];
        $composerMatches = [];
        $total           = 0;
        $pages           = 0;
        $mode            = 'empty'; // empty | search | composer | instr

        if ($composer !== '') {
            // ── Composer browse ──────────────────────────────────────────────
            $mode  = 'composer';
            $total = $this->workRepo->countByComposer($composer, $instrumentation, $style);
            $pages = (int) ceil($total / $perPage);
            $works = $this->workRepo->findByComposer($composer, $instrumentation, $style, $page, $perPage);

        } elseif ($q !== '') {
            // ── Text search: composer cards + title matches ───────────────────
            $mode            = 'search';
            $composerMatches = $this->workRepo->findComposersLike($q);
            $total           = $this->workRepo->countByTitleSearch($q, $instrumentation, $style);
            $pages           = (int) ceil($total / $perPage);
            $works           = $this->workRepo->findByTitleSearch($q, $instrumentation, $style, $page, $perPage);

        } elseif ($instrumentation !== '' || $style !== '') {
            // ── Instrumentation / style only ──────────────────────────────────
            $mode  = 'instr';
            $total = $this->workRepo->countByInstrStyle($instrumentation, $style);
            $pages = (int) ceil($total / $perPage);
            $works = $this->workRepo->findByInstrStyle($instrumentation, $style, $page, $perPage);
        }

        $styles = $this->workRepo->findDistinctStyles();

        return $this->render('imslp/index.html.twig', [
            'q'               => $q,
            'composer'        => $composer,
            'instrumentation' => $instrumentation,
            'style'           => $style,
            'page'            => $page,
            'perPage'         => $perPage,
            'total'           => $total,
            'pages'           => $pages,
            'works'           => $works,
            'composerMatches' => $composerMatches,
            'styles'          => $styles,
            'mode'            => $mode,
        ]);
    }

    #[Route('/work/{pageId}', name: 'app_imslp_work', methods: ['GET'], requirements: ['pageId' => '\d+'])]
    public function work(int $pageId): Response
    {
        /** @var ImslpWork|null $work */
        $work = $this->workRepo->findOneBy(['pageId' => $pageId]);

        if (!$work) {
            throw $this->createNotFoundException('Work not found in local database. Run app:imslp:sync first.');
        }

        // Fetch detail on demand if not yet cached
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    #[Route('/fetch-log', name: 'app_imslp_fetch_log', methods: ['GET'])]
    public function fetchLog(): Response
    {
        $logFile = $this->getParameter('kernel.project_dir') . '/var/log/imslp-fetch.log';

        if (!file_exists($logFile)) {
            return new Response('(no log file yet)', 200, ['Content-Type' => 'text/plain']);
        }

        // Return last 50 lines
        $lines  = file($logFile, FILE_IGNORE_NEW_LINES);
        $tail   = array_slice($lines, -50);

        return new Response(implode("\n", $tail), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function buildStatusData(): array
    {
        $total          = (int) $this->em->createQuery('SELECT COUNT(w.id) FROM App\Entity\ImslpWork w')->getSingleScalarResult();
        $withDetail     = (int) $this->em->createQuery('SELECT COUNT(w.id) FROM App\Entity\ImslpWork w WHERE w.detailSyncedAt IS NOT NULL')->getSingleScalarResult();
        $totalComposers = (int) $this->em->createQuery('SELECT COUNT(c.id) FROM App\Entity\ImslpComposer c')->getSingleScalarResult();

        $pct = $total > 0 ? round($withDetail / $total * 100, 1) : 0;

        return [
            'works'          => $total,
            'worksWithDetail' => $withDetail,
            'worksWithoutDetail' => $total - $withDetail,
            'composers'      => $totalComposers,
            'detailPercent'  => $pct,
            'running'        => $this->isFetchRunning(),
        ];
    }

    private function isFetchRunning(): bool
    {
        $pidFile = $this->getParameter('kernel.project_dir') . '/var/imslp-fetch.pid';
        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = (int) file_get_contents($pidFile);
        if ($pid <= 0) {
            return false;
        }

        // /proc/{pid} exists only while the process is alive (Linux)
        return is_dir('/proc/' . $pid);
    }
}
