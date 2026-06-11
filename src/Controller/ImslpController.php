<?php

namespace App\Controller;

use App\Entity\ImslpWork;
use App\Repository\ImslpWorkRepository;
use App\Repository\WorkFilters;
use App\Service\ImslpAiSearchService;
use App\Service\ImslpSearchService;
use App\Service\ImslpService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/imslp')]
class ImslpController extends AbstractController
{
    private const STYLES = ['Ancient', 'Baroque', 'Classical', 'Medieval', 'Modern', 'Renaissance', 'Romantic', 'Traditional'];
    private const RISM_SEARCH_ROWS = 20;  // RISM Online API pagination limit

    public function __construct(
        private readonly ImslpWorkRepository    $workRepo,
        private readonly ImslpService           $imslp,
        private readonly ImslpAiSearchService   $aiSearch,
        private readonly ImslpSearchService     $search,
        private readonly EntityManagerInterface $em,
        private readonly Connection             $db,
        private readonly CacheInterface         $cache,
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
            language:        trim($request->query->getString('language')),
            includeManuscripts: $request->query->getString('include_manuscripts', '1') === '1',
            yearFrom:        ($v = trim($request->query->getString('year_from'))) !== '' ? (int) $v : null,
            yearTo:          ($v = trim($request->query->getString('year_to')))   !== '' ? (int) $v : null,
        );

        // Use ImslpSearchService to delegate search/filter logic and caching
        $result = match (true) {
            $composer !== ''      => $this->search->searchByComposer($composer, $filters, $page, $perPage),
            $q !== ''             => $this->search->searchByQuery($q, $filters, $page, $perPage),
            !$filters->isEmpty()  => $this->search->searchByFilters($filters, $page, $perPage),
            default               => ['works' => [], 'total' => 0, 'pages' => 0, 'mode' => 'empty'],
        };

        $works           = $result['works'];
        $composerMatches = $result['composerMatches'] ?? [];
        $total           = $result['total'];
        $pages           = $result['pages'];
        $mode            = $result['mode'];
        $composerStyle   = ($mode === 'composer') ? $this->cachedComposerStyle($composer) : '';

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
            'styles'    => self::STYLES,
            'genres'    => $this->cachedDistinctGenres(),
            'languages' => $this->cachedDistinctLanguages(),
            'mode'      => $mode,
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
        // Cache the full work page response (10m TTL) to avoid repeated fetch-details calls
        $workPageCacheKey = 'imslp.work_page.' . $pageId;
        $cachedResponse = $this->cache->get($workPageCacheKey, function (ItemInterface $item) use ($pageId): ?Response {
            $item->expiresAfter(600); // 10 minutes

            /** @var ImslpWork|null $work */
            $work = $this->workRepo->findOneBy(['pageId' => $pageId]);

            if (!$work) {
                return null; // Signals not found
            }

            if (!$work->hasDetail()) {
                try {
                    $this->imslp->fetchWorkDetail($work);
                } catch (\Throwable) {
                    // Non-fatal — show page with partial data
                }
            }

            // Back-fill rismSourceId for editions already in the DB (parsed before this field existed).
            // The RISM URL is preserved in miscNotes because stripWikiMarkup only strips [[...]] not [...].
            $filesJson = $work->getFilesJson();
            if ($filesJson) {
                $enriched = false;
                foreach ($filesJson as &$ed) {
                    if (!array_key_exists('rismSourceId', $ed) && !empty($ed['miscNotes'])) {
                        $ed['rismSourceId'] = $this->imslp->extractRismId($ed['miscNotes']);
                        $enriched = true;
                    }
                }
                unset($ed);
                if ($enriched) $work->setFilesJson($filesJson);
            }

            // Extract publication year per edition from the publisher string (e.g. "Breitkopf, 1884" → 1884).
            $editionYears = [];
            foreach ($work->getFilesJson() ?? [] as $i => $ed) {
                $pub = trim($ed['publisher'] ?? '');
                if ($pub !== '' && preg_match('/\b(1[4-9]\d{2}|20[0-2]\d)\b/', $pub, $m)) {
                    $editionYears[$i] = $m[1];
                }
            }

            return new Response(
                $this->renderView('imslp/work.html.twig', [
                    'work'             => $work,
                    'editionYears'     => $editionYears,
                    'imslpCredentials' => ($_ENV['IMSLP_USER'] ?? '') !== '' && ($_ENV['IMSLP_PASS'] ?? '') !== '',
                ])
            );
        });

        if ($cachedResponse === null) {
            throw $this->createNotFoundException('Work not found in local database. Run app:imslp:sync first.');
        }

        return $cachedResponse;
    }

    #[Route('/download-zip', name: 'app_imslp_download_zip', methods: ['POST'])]
    public function downloadZip(Request $request): Response
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        // Release the session lock (if a session is active) so the progress-poll
        // endpoint can respond concurrently without being blocked.
        $session = $request->getSession();
        if ($session->isStarted()) {
            $session->save();
        }

        try {
            $filenames = $request->request->all('files');
            $zipName   = trim($request->request->getString('zipName', 'imslp-download'));
            $folder    = mb_substr(trim(preg_replace('/[\/\\\:*?"<>|]+/', '-', $zipName), '. '), 0, 80) ?: 'imslp-download';
            $jobId     = preg_replace('/[^a-z0-9]/', '', strtolower($request->request->getString('jobId')));

            $progressFile = $jobId !== '' ? sys_get_temp_dir() . '/imslp_dl_' . $jobId . '.json' : null;

            // Collect valid filenames up front so we know the total for progress
            $valid = array_values(array_filter(
                array_map(fn($f) => basename((string) $f), $filenames),
                fn($f) => (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-\.]+\.(pdf|jpg|png|midi?|xml|mxl|mp3|ogg|flac)$/i', $f)
            ));
            $total = count($valid);

            if ($total === 0) {
                return $this->json(['error' => 'No valid files selected. Check filename format.'], 400);
            }

            $writeProgress = function (int $done, string $current = '') use ($progressFile, $total): void {
                if ($progressFile === null) return;
                @file_put_contents($progressFile, json_encode([
                    'done' => $done, 'total' => $total, 'current' => $current,
                ]));
            };

            $cookieJar = $this->imslpLogin();
            if ($cookieJar === null) {
                if ($progressFile) @unlink($progressFile);
                return $this->json(['error' => 'IMSLP login failed. Check IMSLP_USER / IMSLP_PASS in .env.local.'], 502);
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'imslp_zip_');
            if ($tmpFile === false) {
                return $this->json(['error' => 'Failed to create temporary file.'], 500);
            }

            $zip = new \ZipArchive();
            $openResult = $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($openResult !== true) {
                @unlink($tmpFile);
                return $this->json(['error' => 'Failed to create zip archive: ' . $openResult], 500);
            }

            $added = 0;
            foreach ($valid as $i => $filename) {
                $writeProgress($i, $filename);
                $content = $this->fetchImslpFile($filename, $cookieJar);
                if ($content === null) continue;

                $zip->addFromString($folder . '/' . $filename, $content);
                $added++;
            }

            $writeProgress($total);
            if ($progressFile) @unlink($progressFile);

            @unlink($cookieJar);
            $zip->close();

            if ($added === 0) {
                @unlink($tmpFile);
                return $this->json(['error' => 'No files could be downloaded. They may require a paid IMSLP membership.'], 502);
            }

            $response = new BinaryFileResponse($tmpFile);
            $response->headers->set('Content-Type', 'application/zip');
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $folder . '.zip');
            $response->deleteFileAfterSend(true);

            return $response;
        } catch (\Throwable $e) {
            if ($progressFile ?? null) @unlink($progressFile);
            return $this->json(['error' => 'Download failed: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/download-progress/{jobId}', name: 'app_imslp_download_progress', methods: ['GET'],
            requirements: ['jobId' => '[a-z0-9]{8,32}'])]
    public function downloadProgress(string $jobId): JsonResponse
    {
        $progressFile = sys_get_temp_dir() . '/imslp_dl_' . $jobId . '.json';
        if (!file_exists($progressFile)) {
            return $this->json(['done' => 0, 'total' => 0, 'current' => '']);
        }
        return $this->json(json_decode(file_get_contents($progressFile), true) ?: []);
    }

    #[Route('/download-progress-stream/{jobId}', name: 'app_imslp_download_progress_stream', methods: ['GET'],
            requirements: ['jobId' => '[a-z0-9]{8,32}'])]
    public function downloadProgressStream(string $jobId): Response
    {
        // Server-Sent Events endpoint for real-time download progress
        // Replaces polling; client uses: new EventSource('/imslp/download-progress-stream/{jobId}')
        $response = new Response();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // nginx: don't buffer
        $response->setCallback(function () use ($jobId): void {
            $progressFile = sys_get_temp_dir() . '/imslp_dl_' . $jobId . '.json';
            $lastData = null;

            // Stream for up to 10 minutes (600 seconds)
            $deadline = time() + 600;
            while (time() < $deadline) {
                if (file_exists($progressFile)) {
                    $current = json_decode(file_get_contents($progressFile), true) ?: [];
                    if ($current !== $lastData) {
                        echo 'data: ' . json_encode($current) . "\n\n";
                        flush();
                        $lastData = $current;

                        // Check if download complete
                        if (($current['done'] ?? 0) >= ($current['total'] ?? 0) && ($current['total'] ?? 0) > 0) {
                            echo ": complete\n\n";
                            flush();
                            break;
                        }
                    }
                } else {
                    // File doesn't exist yet; wait and retry
                    echo ": waiting\n\n";
                    flush();
                }

                usleep(200_000); // 200ms polling (vs 500ms before)
            }
        });

        return $response;
    }

    /**
     * Login to IMSLP via the web form (not the MediaWiki API) and inject the
     * redirectPassed cookie so subsequent requests skip the JS redirect interstitial.
     * Returns a path to the cookie jar file, or null on failure.
     */
    private function imslpLogin(): ?string
    {
        $username = $_ENV['IMSLP_USER'] ?? '';
        $password = $_ENV['IMSLP_PASS'] ?? '';
        if ($username === '' || $password === '') return null;

        $jar = tempnam(sys_get_temp_dir(), 'imslp_ck_');

        // Step 1: GET login page to obtain the CSRF token
        $ch = curl_init('https://imslp.org/wiki/Special:UserLogin');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if (!preg_match('/name="wpLoginToken"\s+value="([^"]+)"/', (string) $body, $m)) {
            @unlink($jar);
            return null;
        }

        // Step 2: POST the login form to the actual submit action
        $ch = curl_init('https://imslp.org/index.php?title=Special%3AUserLogin&action=submitlogin&type=login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'wpName'         => $username,
                'wpPassword'     => $password,
                'wpLoginToken'   => $m[1],
                'wpRemember'     => '1',
                'wpLoginAttempt' => '1',
            ]),
            CURLOPT_ENCODING       => '',
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
        ]);
        curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if (str_contains((string) $finalUrl, 'UserLogin')) {
            @unlink($jar);
            return null;
        }

        return $jar;
    }

    /**
     * Fetch one IMSLP file via the IMSLPImageHandler page.
     * That page embeds the CDN URL in a data-id attribute; the CDN itself is public.
     */
    private function fetchImslpFile(string $filename, string $cookieJar): ?string
    {
        // Use DisclaimerAccept which redirects to IMSLPImageHandler after acceptance.
        // Inject redirectPassed=1 in-memory via CURLOPT_COOKIELIST — this bypasses
        // the friendlyredirect.html JS interstitial that curl cannot follow.
        $ch = curl_init('https://imslp.org/wiki/Special:IMSLPDisclaimerAccept/' . rawurlencode($filename));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_COOKIEFILE     => $cookieJar,
            CURLOPT_COOKIEJAR      => $cookieJar,
        ]);
        curl_setopt($ch, CURLOPT_COOKIELIST, 'Set-Cookie: redirectPassed=1; path=/; Domain=.imslp.org');
        $html     = curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($html === null || $html === false) return null;
        $html = (string) $html;

        // Path A: landed on IMSLPImageHandler — extract the CDN URL from data-id
        if (preg_match('/data-id="([^"]+)"/', $html, $m)) {
            $cdnUrl = html_entity_decode($m[1]);
            if (str_starts_with($cdnUrl, '//')) $cdnUrl = 'https:' . $cdnUrl;
            if (!str_starts_with($cdnUrl, 'http')) return null;

            $content = $this->curlGet($cdnUrl, null);
            if ($content === null || str_starts_with(ltrim($content), '<!')) return null;
            return $content;
        }

        // Path B: landed on petruccilibrary.us — parse the relative file href
        if (str_contains($finalUrl, 'petruccilibrary')) {
            $base = preg_replace('/\?.*$/', '', $finalUrl); // strip query string
            $base = preg_replace('#[^/]+$#', '', $base);   // strip last path segment
            if (preg_match('#href="(files/imglnks/[^"]+\.(?:pdf|midi?|xml|mxl))"#i', $html, $hm)) {
                $fileUrl = 'https://www.petruccilibrary.us/' . $hm[1];
                $content = $this->curlGet($fileUrl, null);
                if ($content === null || str_starts_with(ltrim($content), '<!')) return null;
                return $content;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // RISM incipit proxy
    // -------------------------------------------------------------------------

    /**
     * Search RISM for sources of an IMSLP work and return their incipits.
     * Extracts the standard catalogue number (BWV, QV, HWV, TWV, K, RV …)
     * from the work title, searches RISM, and collects SVG incipits from the
     * first few matching sources that have them.
     */
    #[Route('/rism-work-incipits/{pageId}', name: 'app_imslp_rism_work_incipits', methods: ['GET'],
            requirements: ['pageId' => '\d+'])]
    public function rismWorkIncipits(int $pageId): JsonResponse
    {
        $cacheKey = 'imslp.rism_work.' . $pageId;
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($pageId): array {
            $item->expiresAfter(86400);

            $work = $this->workRepo->findOneBy(['pageId' => $pageId]);
            if (!$work) return [];

            $catNum = $this->extractCatalogueNumber($work->getTitle());
            if ($catNum === null) return [];

            // Search RISM — normalize separators: "QV 2:Anh.28" → "QV 2 Anh 28"
            $q = trim(preg_replace('/[:.\/\s]+/', ' ', $catNum));
            $rismHeaders = ['Accept: application/json'];
            $body = $this->curlGet('https://rism.online/search?'
                . http_build_query(['q' => $q, 'mode' => 'sources', 'rows' => self::RISM_SEARCH_ROWS]),
                null, $rismHeaders);
            if ($body === null) return [];

            $search  = json_decode($body, true);
            $sources = $search['items'] ?? [];

            // Build a loose pattern from the catalogue number to filter out
            // unrelated search hits (e.g. "Anh.14" when we wanted "Anh.28").
            // Build a pattern that ignores separator differences (space, colon, dot, dash)
            // so "QV 2: Anh.28" and "QV 2.Anh.28" both match catalogue number "QV 2:Anh.28"
            $catPattern = preg_replace('/[^A-Za-z0-9]+/', '[^A-Za-z0-9]*', preg_quote($catNum, '/'));

            $collected = [];
            foreach ($sources as $src) {
                if (count($collected) >= 1) break; // use only the first matching source
                $srcId = basename($src['id'] ?? '');
                if (!preg_match('/^\d{6,12}$/', $srcId)) continue;

                // Skip sources whose label doesn't look like our work
                $srcLabel = implode(' ', array_merge(...array_values(
                    array_map(fn($v) => (array)$v, $src['label'] ?? [])
                )));
                if (!preg_match('/' . $catPattern . '/i', $srcLabel)) continue;

                $incBody = $this->curlGet("https://rism.online/sources/{$srcId}/incipits", null, $rismHeaders);
                if ($incBody === null) continue;

                $incData = json_decode($incBody, true);
                $items   = $incData['items'] ?? [];
                if (empty($items)) continue;

                $incipits = [];
                foreach ($items as $inc) {
                    $svg = null;
                    foreach ($inc['rendered'] ?? [] as $r) {
                        if (($r['format'] ?? '') === 'image/svg+xml') { $svg = $r['data']; break; }
                    }
                    if (!$svg) continue;
                    $labelArr = $inc['label'] ?? [];
                    $lv = !empty($labelArr) ? reset($labelArr) : null;
                    $incipits[] = ['svg' => $this->sanitizeSvg($svg), 'label' => is_array($lv) ? (reset($lv) ?: null) : ($lv ?: null)];
                }
                if (empty($incipits)) continue;

                $srcLabel = $src['label']['en'][0] ?? $src['label']['none'][0] ?? $srcId;
                $collected[] = ['source' => $srcLabel, 'incipits' => $incipits];
            }
            return ['catalogueNumber' => $catNum, 'sources' => $collected];
        });

        return $this->json($result);
    }

    /** Extract a standard musicological catalogue number from an IMSLP work title. */
    private function extractCatalogueNumber(string $title): ?string
    {
        // Matches common musicological prefixes (BWV, QV, HWV, RV, K., etc.) followed by
        // digits and optional qualifiers (colons, dots, dashes, "Anh" for Anhang/appendix).
        // Examples: BWV 1087, QV 2:Anh.28, HWV 6, K. 331, Hob. XVI:52, Op. 5 No. 2
        if (preg_match(
            '/\b(BWV|QV|HWV|TWV|RV|WoO|Hob\.?|K\.?|D\.?|Op\.?|L\.?|Z\.?|H\.?|CT|TH|P\.?|Wq)\s*[\d][\d:.\-\/Anh ]{0,20}/i',
            $title,
            $m
        )) {
            return rtrim(trim($m[0]), ' .-');
        }
        return null;
    }

    #[Route('/rism-incipits/{rismId}', name: 'app_imslp_rism_incipits', methods: ['GET'],
            requirements: ['rismId' => '\d{7,12}'])]
    public function rismIncipits(string $rismId): JsonResponse
    {
        $cacheKey = 'imslp.rism.' . $rismId;
        $data = $this->cache->get($cacheKey, function (ItemInterface $item) use ($rismId): array {
            $item->expiresAfter(86400); // cache for 24 hours — RISM data changes rarely

            $body = $this->curlGet("https://rism.online/sources/{$rismId}/incipits", null,
                ['Accept: application/json']);
            if ($body === null) return [];

            $raw = json_decode($body, true);
            $incipits = [];
            foreach ($raw['items'] ?? [] as $item2) {
                $svg = null;
                foreach ($item2['rendered'] ?? [] as $r) {
                    if (($r['format'] ?? '') === 'image/svg+xml') { $svg = $r['data']; break; }
                }
                // Plaine & Easie code
                $pae = null;
                foreach ($item2['encodings'] ?? [] as $enc) {
                    $label = $enc['label'] ?? [];
                    $langs = array_merge(...array_values(array_map(fn($v) => (array)$v, $label)));
                    if (array_filter($langs, fn($l) => str_contains(strtolower($l), 'plain'))) {
                        $pae = $enc['value'] ?? null;
                        break;
                    }
                }
                // Voice / section label (e.g. "1.1.1")
                $labelArr = $item2['label'] ?? [];
                $label    = !empty($labelArr) ? reset($labelArr) : null;
                if (is_array($label)) $label = reset($label) ?: null;
                if ($svg || $pae) {
                    $incipits[] = ['label' => $label, 'svg' => $svg ? $this->sanitizeSvg($svg) : null, 'pae' => $pae];
                }
            }
            return $incipits;
        });

        return $this->json($data);
    }

    // -------------------------------------------------------------------------
    // Sync status page + JSON API
    // -------------------------------------------------------------------------

    #[Route('/sync-status', name: 'app_imslp_sync_status', methods: ['GET'])]
    public function syncStatus(Request $request): Response
    {
        // Both HTML and JSON share the same short-lived cache entry so that
        // concurrent poll requests don't each trigger expensive COUNT queries
        // while a fetch process is actively writing to the same tables.
        $data = $this->cache->get('imslp.sync_status', function (ItemInterface $item): array {
            $data = $this->buildStatusData();
            $isRunning = $data['running'] || $data['syncRunning'] || $data['datesRunning'] || $data['composersRunning'];
            $item->expiresAfter($isRunning ? 10 : 60);
            return $data;
        });

        if ($request->query->has('json')) {
            return $this->json($data);
        }

        return $this->render('imslp/sync_status.html.twig', $data);
    }

    #[Route('/fetch-details/start', name: 'app_imslp_fetch_start', methods: ['POST'])]
    public function startFetch(Request $request): JsonResponse
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

        $mode     = $request->request->get('mode', '');
        $modeFlag = match ($mode) {
            'fill-all'    => ' --fill-all',
            'fill-genres' => ' --fill-genres',
            default       => '',
        };

        $cmd = sprintf(
            'nohup env APP_DEBUG=0 php %s app:imslp:fetch-details --delay=500%s --stop-file=%s --pid-file=%s >> %s 2>&1 &',
            escapeshellarg($projectDir . '/bin/console'),
            $modeFlag,
            escapeshellarg($stopFile),
            escapeshellarg($pidFile),
            escapeshellarg($logFile)
        );

        shell_exec($cmd);

        // Poll for up to 5 s for the command to write its own PID (Symfony boot ~2 s)
        $pid = 0;
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            usleep(300_000);
            if (file_exists($pidFile) && ($pid = (int) file_get_contents($pidFile)) > 0) {
                break;
            }
        }

        if ($pid > 0) {
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
            'nohup env APP_DEBUG=0 php %s app:imslp:sync --type=composers --stop-file=%s >> %s 2>&1 & echo $!',
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
        return $this->tailLog($this->getParameter('kernel.project_dir') . '/var/log/imslp-composers.log');
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
            'nohup env APP_DEBUG=0 php %s app:imslp:sync --type=works --resume --stop-file=%s >> %s 2>&1 & echo $!',
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
        return $this->tailLog($this->getParameter('kernel.project_dir') . '/var/log/imslp-sync.log');
    }

    #[Route('/fetch-log', name: 'app_imslp_fetch_log', methods: ['GET'])]
    public function fetchLog(): Response
    {
        return $this->tailLog($this->getParameter('kernel.project_dir') . '/var/log/imslp-fetch.log');
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
            'nohup env APP_DEBUG=0 php %s app:imslp:sync-composer-dates --delay=300 --stop-file=%s >> %s 2>&1 & echo $!',
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
        return $this->tailLog($this->getParameter('kernel.project_dir') . '/var/log/imslp-dates.log');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function cachedDistinctGenres(): array
    {
        return $this->cache->get('imslp.distinct_genres', function (ItemInterface $item): array {
            $item->expiresAfter(86400);
            return $this->workRepo->findDistinctGenres();
        });
    }

    private function cachedDistinctLanguages(): array
    {
        return $this->cache->get('imslp.distinct_languages', function (ItemInterface $item): array {
            $item->expiresAfter(86400);
            return $this->workRepo->findDistinctLanguages();
        });
    }

    private function cachedComposerStyle(string $composer): string
    {
        $key = 'imslp.composer_style.' . md5($composer);
        return (string) $this->cache->get($key, function (ItemInterface $item) use ($composer): string {
            $item->expiresAfter(604800); // 7 days — composer styles rarely change
            return (string) ($this->db->fetchOne(
                'SELECT piece_style FROM imslp_work
                 WHERE composer = ? AND piece_style IS NOT NULL
                 GROUP BY piece_style ORDER BY COUNT(*) DESC LIMIT 1',
                [$composer]
            ) ?: '');
        });
    }

    private function tailLog(string $logFile, int $lines = 200): Response
    {
        if (!file_exists($logFile)) {
            return new Response('(no log file yet)', 200, ['Content-Type' => 'text/plain']);
        }

        $output = shell_exec('tail -' . (int) $lines . ' ' . escapeshellarg($logFile));

        return new Response($output ?? '', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

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
        return $this->isPidAlive($this->getParameter('kernel.project_dir') . '/var/imslp-fetch.pid')
            || $this->isCommandRunning('fetch-details');
    }

    private function isSyncRunning(): bool
    {
        return $this->isPidAlive($this->getParameter('kernel.project_dir') . '/var/imslp-sync.pid')
            || $this->isCommandRunning('imslp:sync');
    }

    private function isDatesRunning(): bool
    {
        return $this->isPidAlive($this->getParameter('kernel.project_dir') . '/var/imslp-dates.pid')
            || $this->isCommandRunning('sync-composer-dates');
    }

    private function isComposersRunning(): bool
    {
        return $this->isPidAlive($this->getParameter('kernel.project_dir') . '/var/imslp-composers.pid')
            || $this->isCommandRunning('imslp:sync.*composers');
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

    private function curlGet(string $url, ?string $cookieJar = null, array $headers = []): ?string
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ContinuoRealizer/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
        ];
        if ($cookieJar) {
            $opts[CURLOPT_COOKIEFILE] = $cookieJar;
            $opts[CURLOPT_COOKIEJAR]  = $cookieJar;
        }
        if ($headers) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : null;
    }

    private function isPidAlive(string $pidFile): bool
    {
        if (!file_exists($pidFile)) {
            return false;
        }
        $pid = (int) file_get_contents($pidFile);
        return $pid > 0 && is_dir('/proc/' . $pid);
    }

    /** Returns true if any process matching $pattern is running in /proc. */
    private function isCommandRunning(string $pattern): bool
    {
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) {
            $cmd = @file_get_contents($f);
            if ($cmd !== false && preg_match('/' . preg_quote($pattern, '/') . '/', $cmd)) {
                return true;
            }
        }
        return false;
    }

    /** Sanitize SVG by removing script/event handlers. RISM is trusted but defense-in-depth. */
    private function sanitizeSvg(string $svg): string
    {
        // Remove <script> tags and on* event handlers
        $svg = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svg);
        $svg = preg_replace('/\s+on\w+\s*=\s*["\']?[^"\'\s>]+["\']?/i', '', $svg);
        return $svg;
    }

    /** Cache a count query result. Used for pagination counts. */
    private function cachedCount(string $key, callable $query, int $ttl): int
    {
        return (int) $this->cache->get($key, function (ItemInterface $item) use ($query, $ttl): int {
            $item->expiresAfter($ttl);
            return $query();
        });
    }

    /** Cache a search/filter query result. Used for work lists. */
    private function cachedSearch(string $key, callable $query, int $ttl): array
    {
        return $this->cache->get($key, function (ItemInterface $item) use ($query, $ttl): array {
            $item->expiresAfter($ttl);
            return $query();
        });
    }

}
