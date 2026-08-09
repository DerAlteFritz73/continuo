<?php

namespace App\Controller;

use App\Service\StaffRelineService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Re-ruling of facsimile scans, so that a French baroque clef sits where a
 * modern reader expects it.  See {@see StaffRelineService} for what the
 * conversion actually does to the page.
 */
#[Route('/reline')]
class RelineController extends AbstractController
{
    private const MAX_UPLOAD = 30 * 1024 * 1024;
    /** Bounds the work one request can ask for; a full treatise is hundreds of pages. */
    private const MAX_PAGES = 24;
    private const EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff'];
    /** Runs older than this are swept away on the next request. */
    private const KEEP_SECONDS = 86400;

    public function __construct(
        private readonly StaffRelineService $reliner,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('', name: 'app_reline', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('reline/index.html.twig', [
            'clefs'     => array_keys(StaffRelineService::CLEFS),
            'maxPages'  => self::MAX_PAGES,
        ]);
    }

    #[Route('/run', name: 'app_reline_run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        $file = $request->files->get('facsimile');
        if (!$file) {
            return $this->json(['error' => $this->translator->trans('reline.error.no_file')], 400);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::EXTENSIONS, true)) {
            return $this->json(['error' => $this->translator->trans('reline.error.invalid_type')], 400);
        }
        if ($file->getSize() > self::MAX_UPLOAD) {
            return $this->json(['error' => $this->translator->trans('reline.error.too_large')], 400);
        }

        $shift = $this->resolveShift($request);
        if ($shift === null) {
            return $this->json(['error' => $this->translator->trans('reline.error.no_shift')], 400);
        }

        // A first/last page range, clamped so one request never renders more than
        // MAX_PAGES at a time (a full treatise is hundreds of pages).
        $first = max(1, $request->request->getInt('firstPage', 1));
        $last  = max($first, $request->request->getInt('lastPage', $first));
        $count = min(self::MAX_PAGES, $last - $first + 1);
        $pages = sprintf('%d-%d', $first, $first + $count - 1);

        $dpi = $request->request->getInt('dpi', 300);
        if (!in_array($dpi, [150, 200, 300, 400, 500, 600], true)) {
            $dpi = 300;
        }

        $this->sweepOldRuns();

        $token = bin2hex(random_bytes(8));
        $dir   = $this->runDir($token);
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->json(['error' => $this->translator->trans('reline.error.storage')], 500);
        }

        $source = $dir . '/source.' . $extension;
        $file->move($dir, 'source.' . $extension);

        try {
            $report = $this->reliner->reline(
                path: $source,
                outDir: $dir,
                shift: $shift,
                dpi: $dpi,
                pages: $pages,
                ledgers: $request->request->getBoolean('ledgers'),
                pdf: true,
            );
        } catch (\Throwable $e) {
            return $this->json([
                'error'  => $this->translator->trans('reline.error.failed'),
                'detail' => $e->getMessage(),
            ], 500);
        }

        return $this->json([
            'success' => true,
            'token'   => $token,
            'shift'   => $shift,
            'pages'   => array_values(array_map(
                fn (array $page): array => $this->describePage($token, $page),
                $report['pages'],
            )),
            'pdf' => is_file($dir . '/relined.pdf')
                ? $this->generateUrl('app_reline_asset', ['token' => $token, 'name' => 'relined.pdf'])
                : null,
        ]);
    }

    /**
     * Page count for the uploaded facsimile, so the form can pre-fill how many
     * pages to process the moment a file is chosen. A single image is always one
     * page and answered without touching the sidecar; a PDF is counted by it.
     */
    #[Route('/pages', name: 'app_reline_pages', methods: ['POST'])]
    public function pages(Request $request): JsonResponse
    {
        $file = $request->files->get('facsimile');
        if (!$file) {
            return $this->json(['error' => $this->translator->trans('reline.error.no_file')], 400);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::EXTENSIONS, true)) {
            return $this->json(['error' => $this->translator->trans('reline.error.invalid_type')], 400);
        }
        if ($file->getSize() > self::MAX_UPLOAD) {
            return $this->json(['error' => $this->translator->trans('reline.error.too_large')], 400);
        }

        if ($extension !== 'pdf') {
            return $this->json(['pages' => 1]);
        }

        $tmp = $file->move(sys_get_temp_dir(), 'reline-count-' . bin2hex(random_bytes(6)) . '.pdf');
        try {
            $count = $this->reliner->countPages($tmp->getPathname());
        } catch (\Throwable $e) {
            return $this->json([
                'error'  => $this->translator->trans('reline.error.failed'),
                'detail' => $e->getMessage(),
            ], 500);
        } finally {
            @unlink($tmp->getPathname());
        }

        return $this->json(['pages' => $count]);
    }

    /**
     * Serve one generated page or the assembled PDF.
     *
     * The run directory lives under var/, outside the document root, so every
     * read goes through here; the route requirements keep the name to the
     * shapes the sidecar produces rather than anything a caller supplies.
     */
    #[Route('/asset/{token}/{name}', name: 'app_reline_asset', methods: ['GET'], requirements: [
        'token' => '[0-9a-f]{16}',
        'name'  => 'page-\d{3}(-relined)?\.png|relined\.pdf',
    ])]
    public function asset(string $token, string $name): Response
    {
        $path = $this->runDir($token) . '/' . $name;
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setPrivate();
        $response->headers->set('Content-Type', str_ends_with($name, '.pdf') ? 'application/pdf' : 'image/png');
        return $response;
    }

    /**
     * The shift can come as a named source clef or, for anything the presets do
     * not cover, as a raw number of line positions.
     */
    private function resolveShift(Request $request): ?int
    {
        $clef = $request->request->getString('clef');
        if ($clef !== '' && $clef !== 'custom') {
            return $this->reliner->shiftForClef($clef);
        }

        $shift = $request->request->getInt('shift');
        return ($shift >= -4 && $shift <= 4 && $shift !== 0) ? $shift : null;
    }

    /**
     * @param array<string,mixed> $page
     *
     * @return array<string,mixed>
     */
    private function describePage(string $token, array $page): array
    {
        $url = function (?string $path) use ($token): ?string {
            if ($path === null) {
                return null;
            }
            return $this->generateUrl('app_reline_asset', [
                'token' => $token,
                'name'  => basename($path),
            ]);
        };

        return [
            'index'    => $page['index'],
            'width'    => $page['width'],
            'height'   => $page['height'],
            'skew'     => $page['skew_deg'],
            'staves'   => is_array($page['staves'] ?? null) ? count($page['staves']) : 0,
            'ledgers'  => $page['ledgers_added'] ?? 0,
            'barlines' => $page['barlines_adjusted'] ?? 0,
            'before'   => $url($page['image'] ?? null),
            'after'    => $url($page['output'] ?? null),
        ];
    }

    private function runDir(string $token): string
    {
        return $this->projectDir . '/var/reline/' . $token;
    }

    /** Drop yesterday's runs; nothing here is worth keeping once the tab is closed. */
    private function sweepOldRuns(): void
    {
        $root = $this->projectDir . '/var/reline';
        if (!is_dir($root)) {
            return;
        }
        $cutoff = time() - self::KEEP_SECONDS;
        foreach (glob($root . '/*') ?: [] as $dir) {
            if (!is_dir($dir) || filemtime($dir) > $cutoff) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($dir);
        }
    }
}
