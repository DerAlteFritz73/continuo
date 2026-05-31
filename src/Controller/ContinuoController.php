<?php

namespace App\Controller;

use App\Repository\VoiceLeadingRuleRepository;
use App\Service\ContinuoRealizer;
use App\Service\MusicXmlParser;
use App\Service\MusicXmlSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ContinuoController extends AbstractController
{
    public function __construct(
        private readonly MusicXmlParser             $parser,
        private readonly ContinuoRealizer           $realizer,
        private readonly MusicXmlSerializer         $serializer,
        private readonly TranslatorInterface        $translator,
        private readonly VoiceLeadingRuleRepository $ruleRepository,
    ) {}

    #[Route('/language/{locale}', name: 'app_language', requirements: ['locale' => 'en|fr|de|cs'])]
    public function language(string $locale, Request $request): Response
    {
        $request->getSession()->set('_locale', $locale);
        $referer = $request->headers->get('referer', $this->generateUrl('app_home'));
        return $this->redirect($referer);
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        try {
            $rules = $this->ruleRepository->findBy([], ['priority' => 'ASC']);
        } catch (\Throwable) {
            $rules = [];
        }
        return $this->render('continuo/index.html.twig', ['rules' => $rules]);
    }

    #[Route('/realize', name: 'app_realize', methods: ['POST'])]
    public function realize(Request $request): Response
    {
        $file = $request->files->get('musicxml');

        if (!$file) {
            return $this->render('continuo/index.html.twig', [
                'error' => $this->translator->trans('error.no_file'),
            ]);
        }

        // Validate MIME / extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xml', 'musicxml', 'mxl'])) {
            return $this->render('continuo/index.html.twig', [
                'error' => $this->translator->trans('error.invalid_type'),
            ]);
        }

        $maxSize = 10 * 1024 * 1024; // 10 MB
        if ($file->getSize() > $maxSize) {
            return $this->render('continuo/index.html.twig', [
                'error' => $this->translator->trans('error.too_large'),
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
                    'success'    => true,
                    'xml'        => $output,
                    'inputXml'   => $this->serializer->serializeBassLine($score),
                    'filename'   => $outputName,
                    'summary'    => $summary,
                    'chordData'  => $this->buildChordDataArray($score),
                ]);
            }

            return $response;

        } catch (\Throwable $e) {
            return $this->render('continuo/index.html.twig', [
                'error' => $this->translator->trans('error.processing', ['%message%' => $e->getMessage()]),
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
            $numVoices  = (int) $request->request->get('voices', 4);
            $numVoices  = in_array($numVoices, [3, 4], true) ? $numVoices : 4;
            $score      = $this->parser->parse($xmlContent);
            $score      = $this->realizer->realize($score, $numVoices);
            $output     = $this->serializer->serialize($score);
            $summary    = $this->buildSummary($score);

            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

            return $this->json([
                'success'   => true,
                'xml'       => $output,
                'inputXml'  => $this->serializer->serializeBassLine($score),
                'filename'  => $originalName . '_continuo_realization.xml',
                'summary'   => $summary,
                'chordData' => $this->buildChordDataArray($score),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function buildChordDataArray(\App\Model\Score $score): array
    {
        $data      = [];
        $cumQN     = 0.0;
        $divisions = max(1, $score->divisions);

        foreach ($score->measures as $measure) {
            foreach ($measure->bassNotes as $i => $bassNote) {
                $noteQN = $bassNote->duration / $divisions;

                if (!$bassNote->isRest() && isset($measure->realizedChords[$i])) {
                    $chord = $measure->realizedChords[$i];
                    $dt    = $chord->decisionTrace;

                    $numUpper   = count($chord->upperVoices);
                    $voiceNames = $numUpper === 2
                        ? ['alto', 'soprano']
                        : ['tenor', 'alto', 'soprano'];
                    $notes = ['bass' => $this->noteInfo($chord->bass)];
                    foreach ($chord->upperVoices as $vi => $note) {
                        $notes[$voiceNames[$vi] ?? 'soprano'] = $this->noteInfo($note);
                    }

                    $data[] = [
                        'scorePosition'    => $cumQN,
                        'measureNum'       => $measure->number,
                        'noteIndex'        => $i,
                        'chordSymbol'      => $chord->chordSymbol,
                        'figures'          => $this->figureString($chord->figures),
                        'notes'            => $notes,
                        'scaleDegree'      => $dt['scaleDegree'] ?? null,
                        'scaleDegName'     => $this->scaleDegName($dt['scaleDegree'] ?? 0),
                        'motionIn'         => $dt['motionIn'] ?? null,
                        'motionInDisplay'  => $this->motionDisplay($dt['motionIn'] ?? ''),
                        'motionOut'        => $dt['motionOut'] ?? null,
                        'motionOutDisplay' => $this->motionDisplay($dt['motionOut'] ?? ''),
                        'figuresSource'    => $dt['figuresSource'] ?? 'unknown',
                        'keyFifths'        => $dt['keyFifths'] ?? 0,
                        'keyDisplay'       => \App\Service\PitchHelper::tonicFromFifths(
                                                 $dt['keyFifths'] ?? 0, $dt['keyMode'] ?? 'major'
                                             ) . ' ' . ($dt['keyMode'] ?? 'major'),
                        'decisionSteps'    => $dt['steps'] ?? [],
                    ];
                }

                $cumQN += $noteQN;
            }
        }
        return $data;
    }

    private function noteInfo(\App\Model\Note $note): array
    {
        return [
            'pitch'    => (string) $note,
            'step'     => $note->step,
            'octave'   => $note->octave,
            'alter'    => $note->alter,
            'type'     => $note->type,
            'duration' => $note->duration,
            'isRest'   => $note->isRest(),
            'midi'     => $note->midiPitch(),
        ];
    }

    private function figureString(array $figures): string
    {
        if (empty($figures)) {
            return '(none)';
        }
        $alterMap = [-2 => 'bb', -1 => 'b', 0 => '', 1 => '#', 2 => 'x'];
        $parts = [];
        foreach ($figures as $f) {
            $alter  = (int) ($f['alter'] ?? 0);
            $prefix = $alterMap[$alter] ?? '';
            $parts[] = $prefix . $f['number'];
        }
        // Conventional abbreviation: "5 3" → "5"
        if ($parts === ['5', '3']) {
            return '5';
        }
        return implode(' ', $parts);
    }

    private function scaleDegName(int $deg): string
    {
        if ($deg < 1 || $deg > 7) return '?';
        return $this->translator->trans('scale_degree.' . $deg);
    }

    private function motionDisplay(string $motion): string
    {
        $key = match($motion) {
            'step-up'   => 'motion.step_up',
            'step-down' => 'motion.step_down',
            'leap-up'   => 'motion.leap_up',
            'leap-down' => 'motion.leap_down',
            'same'      => 'motion.same',
            'start'     => 'motion.start',
            default     => null,
        };
        return $key ? $this->translator->trans($key) : $motion;
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
