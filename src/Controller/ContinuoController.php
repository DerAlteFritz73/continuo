<?php

namespace App\Controller;

use App\Service\ContinuoRealizer;
use App\Service\MusicXmlParser;
use App\Service\MusicXmlSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class ContinuoController extends AbstractController
{
    public function __construct(
        private readonly MusicXmlParser    $parser,
        private readonly ContinuoRealizer  $realizer,
        private readonly MusicXmlSerializer $serializer,
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('continuo/index.html.twig');
    }

    #[Route('/realize', name: 'app_realize', methods: ['POST'])]
    public function realize(Request $request): Response
    {
        $file = $request->files->get('musicxml');

        if (!$file) {
            return $this->render('continuo/index.html.twig', [
                'error' => 'Please upload a MusicXML file.',
            ]);
        }

        // Validate MIME / extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xml', 'musicxml', 'mxl'])) {
            return $this->render('continuo/index.html.twig', [
                'error' => 'Invalid file type. Please upload a .xml or .musicxml file.',
            ]);
        }

        $maxSize = 5 * 1024 * 1024; // 5 MB
        if ($file->getSize() > $maxSize) {
            return $this->render('continuo/index.html.twig', [
                'error' => 'File too large (max 5 MB).',
            ]);
        }

        try {
            $xmlContent = file_get_contents($file->getPathname());

            // Parse
            $score = $this->parser->parse($xmlContent);

            // Realize
            $score = $this->realizer->realize($score);

            // Serialize
            $output = $this->serializer->serialize($score);

            // Build summary for display
            $summary = $this->buildSummary($score);

            // Return as downloadable file
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $outputName   = $originalName . '_continuo_realization.xml';

            $response = new Response($output);
            $response->headers->set('Content-Type', 'application/vnd.recordare.musicxml+xml');
            $response->headers->set(
                'Content-Disposition',
                $response->headers->makeDisposition(
                    ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    $outputName
                )
            );

            // If AJAX or preview requested, return JSON with XML embedded
            if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
                return $this->json([
                    'success'  => true,
                    'xml'      => $output,
                    'filename' => $outputName,
                    'summary'  => $summary,
                ]);
            }

            return $response;

        } catch (\Throwable $e) {
            return $this->render('continuo/index.html.twig', [
                'error' => 'Error processing file: ' . $e->getMessage(),
            ]);
        }
    }

    #[Route('/realize/preview', name: 'app_realize_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $file = $request->files->get('musicxml');

        if (!$file) {
            return $this->json(['error' => 'No file uploaded'], 400);
        }

        try {
            $xmlContent = file_get_contents($file->getPathname());
            $score      = $this->parser->parse($xmlContent);
            $score      = $this->realizer->realize($score);
            $output     = $this->serializer->serialize($score);
            $summary    = $this->buildSummary($score);

            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

            return $this->json([
                'success'  => true,
                'xml'      => $output,
                'filename' => $originalName . '_continuo_realization.xml',
                'summary'  => $summary,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function buildSummary(\App\Model\Score $score): array
    {
        $totalNotes  = 0;
        $chordCounts = [];
        $parallelViolations = 0;

        $serializer = $this->serializer;

        foreach ($score->measures as $measure) {
            foreach ($measure->realizedChords as $chord) {
                $totalNotes++;
                $sym = $chord->chordSymbol ?: '?';
                $chordCounts[$sym] = ($chordCounts[$sym] ?? 0) + 1;
            }
        }

        arsort($chordCounts);

        return [
            'title'       => $score->title    ?? 'Unknown',
            'composer'    => $score->composer  ?? 'Unknown',
            'key'         => \App\Service\PitchHelper::tonicFromFifths($score->keyFifths, $score->keyMode)
                             . ' ' . $score->keyMode,
            'measures'    => count($score->measures),
            'totalNotes'  => $totalNotes,
            'topChords'   => array_slice($chordCounts, 0, 10, true),
        ];
    }
}
