<?php

namespace App\Service;

use App\Model\Measure;
use App\Model\Note;
use App\Model\Score;

/**
 * Rule-based cadence detector (skeleton).
 *
 * For each metrically salient bass arrival it builds a small feature vector and
 * scores the move that leads into it. The score gates whether a cadence is
 * reported; the bass scale-degree motion classifies its *type*. This is the
 * harmonic backbone that {@see PhraseSegmenter}/{@see PassageDetector} use to
 * cut phrases, and it is deliberately written to be extended:
 *
 *   - Leading-tone resolution (7→1) in an upper voice — needs the realised
 *     voices, so it is only stubbed here (see {@see scoreLeadingToneFigure}).
 *   - Suspension figures (4‑3, 7‑6, 9‑8) and the cadential 6/4 — partially
 *     handled from the raw figured bass already carried on each Note.
 *
 * Detection is degree-relative, so it must be given a key frame (typically a
 * rough global estimate from {@see LocalKeyEstimator}); phrase-local keys are
 * resolved afterwards once the boundaries are known.
 */
class CadenceDetector
{
    public const AUTHENTIC = 'authentic';
    public const HALF      = 'half';
    public const PLAGAL    = 'plagal';
    public const DECEPTIVE = 'deceptive';

    /** A candidate must reach this score to be reported as a cadence. */
    private const THRESHOLD = 3.0;

    public function __construct(private readonly HarmonyAnalyzer $analyzer) {}

    /**
     * Detect cadences across the score under the given key frame.
     *
     * @return list<array{measure:int, type:string, score:float, bassFrom:int, bassTo:int, strong:bool}>
     */
    public function detect(Score $score, int $keyFifths, string $keyMode): array
    {
        $events = $this->flattenBass($score);
        if (count($events) < 2) {
            return [];
        }

        $cadences = [];
        for ($i = 1; $i < count($events); $i++) {
            $prev    = $events[$i - 1];
            $arrival = $events[$i];

            $candidate = $this->evaluate($prev, $arrival, $keyFifths, $keyMode);
            if ($candidate !== null && $candidate['score'] >= self::THRESHOLD) {
                $cadences[] = $candidate;
            }
        }

        return $this->dedupePerMeasure($cadences);
    }

    /**
     * Score and classify a single penultimate → arrival bass move.
     *
     * @param array{note:Note, measure:int, offsetQn:float, downbeat:bool, durationQn:float} $prev
     * @param array{note:Note, measure:int, offsetQn:float, downbeat:bool, durationQn:float} $arrival
     *
     * @return array{measure:int, type:string, score:float, bassFrom:int, bassTo:int, strong:bool}|null
     */
    private function evaluate(array $prev, array $arrival, int $keyFifths, string $keyMode): ?array
    {
        $fromDeg = $this->analyzer->scaleDegree($prev['note'], $keyFifths, $keyMode);
        $toDeg   = $this->analyzer->scaleDegree($arrival['note'], $keyFifths, $keyMode);

        $type = $this->classify($fromDeg, $toDeg);
        if ($type === null) {
            return null;
        }

        // ── Feature scoring ─────────────────────────────────────────────
        $score = 0.0;

        // Harmonic formula carries the base weight.
        $score += match ($type) {
            self::AUTHENTIC => 2.0,
            self::HALF      => 1.5,
            self::PLAGAL    => 1.5,
            self::DECEPTIVE => 1.5,
            default         => 0.0,
        };

        // Metric placement: an arrival on a downbeat is the prototypical close.
        $strong = $arrival['downbeat'];
        if ($strong) {
            $score += 1.5;
        }

        // Agogic accent: the goal chord is held (≥ a half note, or ends a measure).
        if ($arrival['durationQn'] >= 2.0) {
            $score += 1.0;
        }

        // Bass leap of a 4th/5th into the arrival (authentic / half hallmark).
        $leap = abs($prev['note']->midiPitch() - $arrival['note']->midiPitch()) % 12;
        if (in_array($leap, [5, 7], true)) {
            $score += 1.0;
        }

        // Figured-bass hints on the penultimate (dominant 7th, cadential 6/4, 4-3).
        $score += $this->scoreFigures($prev['note'], $arrival['note']);

        return [
            'measure'  => $arrival['measure'],
            'type'     => $type,
            'score'    => round($score, 2),
            'bassFrom' => $fromDeg,
            'bassTo'   => $toDeg,
            'strong'   => $strong,
        ];
    }

    /**
     * Classify by bass scale-degree motion. Returns null when the move is not a
     * cadential formula.
     */
    private function classify(int $fromDeg, int $toDeg): ?string
    {
        // Arrival on the dominant → half cadence (antecedent close).
        if ($toDeg === 5 && in_array($fromDeg, [1, 2, 4, 6, 7], true)) {
            return self::HALF;
        }

        if ($toDeg === 1) {
            // V→I or vii°6→I both land on the tonic from the dominant function.
            if ($fromDeg === 5 || $fromDeg === 7) {
                return self::AUTHENTIC;
            }
            // IV→I plagal.
            if ($fromDeg === 4) {
                return self::PLAGAL;
            }
        }

        // V→vi deceptive resolution.
        if ($fromDeg === 5 && $toDeg === 6) {
            return self::DECEPTIVE;
        }

        return null;
    }

    /**
     * Weight from the penultimate chord's figured bass.
     */
    private function scoreFigures(Note $penult, Note $arrival): float
    {
        $figs = array_map('intval', $penult->figuredBass);
        if ($figs === []) {
            return 0.0;
        }

        $score = 0.0;

        // Dominant seventh under the penultimate.
        if (in_array(7, $figs, true)) {
            $score += 0.75;
        }

        // Cadential 6/4 (figures 6 and 4 together) resolving onto the arrival.
        if (in_array(6, $figs, true) && in_array(4, $figs, true)) {
            $score += 0.75;
        }

        // 4‑3 suspension into the arrival.
        if (in_array(4, $figs, true) && in_array(3, array_map('intval', $arrival->figuredBass), true)) {
            $score += 0.5;
        }

        return $score;
    }

    /**
     * Placeholder for the strongest authentic-cadence signal: a leading tone
     * (raised ^7) resolving up to the tonic in an upper voice. That requires the
     * realised voices, which are not available at detection time — wire this up
     * once cadence detection can run against a realised Score.
     */
    private function scoreLeadingToneFigure(Note $penult, Note $arrival): float
    {
        return 0.0;
    }

    /**
     * Flatten every measure's bass line into a linear stream annotated with
     * metric position (offset in quarter notes, whether it is the downbeat) and
     * sounding length in quarter notes.
     *
     * @return list<array{note:Note, measure:int, offsetQn:float, downbeat:bool, durationQn:float}>
     */
    private function flattenBass(Score $score): array
    {
        $divisions = max(1, $score->divisions);
        $events    = [];

        foreach ($score->measures as $measure) {
            $offsetQn = 0.0;
            foreach ($measure->bassNotes as $note) {
                $durationQn = $note->duration / $divisions;
                if (!$note->isRest()) {
                    $events[] = [
                        'note'       => $note,
                        'measure'    => $measure->number,
                        'offsetQn'   => $offsetQn,
                        'downbeat'   => abs($offsetQn) < 1e-6,
                        'durationQn' => $durationQn,
                    ];
                }
                $offsetQn += $durationQn;
            }
        }

        return $events;
    }

    /**
     * Keep only the strongest cadence per measure (cadences cluster around a
     * single arrival point).
     *
     * @param list<array{measure:int, type:string, score:float, bassFrom:int, bassTo:int, strong:bool}> $cadences
     *
     * @return list<array{measure:int, type:string, score:float, bassFrom:int, bassTo:int, strong:bool}>
     */
    private function dedupePerMeasure(array $cadences): array
    {
        $best = [];
        foreach ($cadences as $c) {
            $m = $c['measure'];
            if (!isset($best[$m]) || $c['score'] > $best[$m]['score']) {
                $best[$m] = $c;
            }
        }

        $result = array_values($best);
        usort($result, static fn(array $a, array $b): int => $a['measure'] <=> $b['measure']);

        return $result;
    }
}
