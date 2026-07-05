<?php

namespace App\Service;

use App\Model\Measure;
use App\Model\Score;

/**
 * Segments a score into phrases and assigns each a local key/mode.
 *
 * Three passes:
 *   1. A rough global key over the whole texture ({@see LocalKeyEstimator}),
 *      used only as a harmonic frame for cadence detection.
 *   2. Cadences ({@see CadenceDetector}) plus any explicit MusicXML key changes
 *      give the phrase boundaries.
 *   3. Each phrase span is re-estimated independently — over ALL voices, not the
 *      bass alone — to get its key, mode and a confidence level.
 *
 * The detected keys are reported for display only; they are intentionally NOT
 * written into the output armature (see ContinuoController).
 */
class PassageDetector
{
    public function __construct(
        private readonly LocalKeyEstimator     $keyEstimator,
        private readonly CadenceDetector       $cadenceDetector,
        private readonly RomanNumeralAnalyzer  $romanAnalyzer,
        private readonly SequenceDetector      $sequenceDetector,
        private readonly SuspensionDetector    $suspensionDetector,
    ) {}

    /** Weight given to the cadential tonic on top of the raw K-S correlation. */
    private const CADENCE_BONUS = 0.12;

    /**
     * @return list<array{start_measure:int, end_measure:int, key:array{fifths:int,mode:string},
     *                    confidence:string, correlation:float, cadence:?string, boundary:string,
     *                    key_trace:array}>
     */
    public function detectPassages(Score $score): array
    {
        if (empty($score->measures)) {
            return [];
        }

        $divisions = max(1, $score->divisions);

        // Pass 1 — global frame.
        $global = $this->keyEstimator->estimateFromHistogram(
            $this->histogramForRange($score->measures, $divisions)
        );

        // Pass 2 — cadences keyed by their arrival measure.
        $cadences = [];
        foreach ($this->cadenceDetector->detect($score, $global['fifths'], $global['mode']) as $c) {
            $cadences[$c['measure']] = $c;
        }

        // Pass 3 — cut phrases and estimate each span's key.
        $passages    = [];
        $segment     = [];
        $measures    = $score->measures;
        $lastIndex   = count($measures) - 1;

        foreach ($measures as $i => $measure) {
            $segment[] = $measure;

            $cadenceHere   = $cadences[$measure->number] ?? null;
            $keyChangeNext = $i < $lastIndex && $this->isExplicitKeyChange($measures[$i + 1], $measure);

            if ($cadenceHere !== null || $keyChangeNext || $i === $lastIndex) {
                $boundary = $cadenceHere !== null ? 'cadence'
                    : ($keyChangeNext ? 'key-change' : 'end-of-piece');
                $passages[] = $this->makePassage($segment, $cadenceHere, $divisions, $boundary);
                $segment    = [];
            }
        }

        return $this->mergeShortPassages($passages);
    }

    /**
     * Second pass, run AFTER realization: re-score the cadence closing each
     * phrase now that the realized upper voices exist, confirming any raised ^7
     * → tonic resolution. Phrase boundaries are left untouched — this only
     * enriches each passage's cadence with a `leadingTone` flag and the refined
     * score, upgrading a plausible authentic cadence to a confirmed one.
     *
     * @param Score $score a realized score (measures carry realizedChords)
     */
    public function refineWithRealization(Score $score): void
    {
        if (empty($score->passages)) {
            return;
        }

        $divisions = max(1, $score->divisions);
        $global    = $this->keyEstimator->estimateFromHistogram(
            $this->histogramForRange($score->measures, $divisions)
        );

        // Re-detect with the realized voices; index by arrival measure.
        $refined = [];
        foreach ($this->cadenceDetector->detect($score, $global['fifths'], $global['mode'], true) as $c) {
            $refined[$c['measure']] = $c;
        }

        foreach ($score->passages as &$passage) {
            $c = $refined[$passage['end_measure']] ?? null;
            if ($c === null) {
                $passage['leadingTone'] = false;
                continue;
            }
            $passage['cadence']       = $c['type'];
            $passage['leadingTone']   = $c['leadingTone'];
            $passage['cadence_score'] = $c['score'];
        }
        unset($passage);
    }

    /**
     * @param Measure[] $segment
     * @param array{type:string}|null $cadence
     * @param string $boundary what closed the phrase: cadence|key-change|end-of-piece
     */
    private function makePassage(array $segment, ?array $cadence, int $divisions, string $boundary): array
    {
        // The closing cadence, when present, biases the key toward the tonic it
        // implies — the strongest single piece of baroque key evidence.
        $prior    = $this->cadencePrior($cadence);
        $estimate = $this->keyEstimator->estimateFromHistogram(
            $this->histogramForRange($segment, $divisions),
            $prior
        );

        $first = $segment[0];
        $last  = end($segment);

        $progression = $this->romanAnalyzer->analyze($segment, $estimate['fifths'], $estimate['mode'], $divisions);
        $patterns    = $this->sequenceDetector->detect($segment);
        $suspensions = $this->suspensionDetector->detect($segment, $divisions);

        return [
            'start_measure' => $first->number,
            'end_measure'   => $last->number,
            'key'           => ['fifths' => $estimate['fifths'], 'mode' => $estimate['mode']],
            'confidence'    => $estimate['confidence'],
            'correlation'   => round($estimate['correlation'], 3),
            'cadence'       => $cadence['type'] ?? null,
            'boundary'      => $boundary,
            'key_trace'     => $estimate['trace'] ?? [],
            'progression'   => $progression,
            'patterns'      => $patterns,
            'suspensions'   => $suspensions,
        ];
    }

    /**
     * Build the cadential key prior: the tonic implied by the phrase's closing
     * cadence, resolved from the arrival bass pitch class and the cadence type.
     *
     * @param array{type:string, arrivalPc:int}|null $cadence
     *
     * @return array{tonicPc:int, bonus:float, reason:string}|null
     */
    private function cadencePrior(?array $cadence): ?array
    {
        if ($cadence === null || !isset($cadence['arrivalPc'])) {
            return null;
        }

        $arrival = $cadence['arrivalPc'];
        $tonicPc = match ($cadence['type']) {
            CadenceDetector::HALF      => ($arrival + 5) % 12,   // arrival = dominant → tonic a 5th below
            CadenceDetector::DECEPTIVE => ($arrival + 3) % 12,   // arrival = submediant → tonic
            default                    => $arrival,              // authentic / plagal → arrival is the tonic
        };

        return ['tonicPc' => $tonicPc, 'bonus' => self::CADENCE_BONUS, 'reason' => $cadence['type']];
    }

    /**
     * Duration-weighted pitch-class histogram over every voice in the range
     * (bass + any melody notes), normalised to quarter-note units so the two
     * sources contribute on the same scale.
     *
     * @param Measure[] $measures
     *
     * @return float[]
     */
    private function histogramForRange(array $measures, int $divisions): array
    {
        $hist = array_fill(0, 12, 0.0);

        foreach ($measures as $measure) {
            foreach ($measure->bassNotes as $note) {
                if ($note->isRest()) {
                    continue;
                }
                $weight = ($note->duration > 0 ? $note->duration : 1.0) / $divisions;
                $hist[$note->pitchClass()] += $weight;
            }

            // melodyNotes durations are already in quarter-note units.
            foreach ($measure->melodyNotes as $mn) {
                $weight = ($mn['duration'] ?? 0) > 0 ? $mn['duration'] : 1.0;
                $hist[$mn['pc'] % 12] += $weight;
            }
        }

        return $hist;
    }

    private function isExplicitKeyChange(Measure $measure, Measure $previous): bool
    {
        return $measure->keySignature !== null
            && $measure->keySignature !== $previous->keySignature;
    }

    /**
     * Fold phrases shorter than two measures into their neighbour so the display
     * is not cluttered by single-measure fragments.
     */
    private function mergeShortPassages(array $passages): array
    {
        $merged = [];
        foreach ($passages as $passage) {
            $length = $passage['end_measure'] - $passage['start_measure'] + 1;

            if ($length < 2 && !empty($merged)) {
                // Fold the fragment into its neighbour, adopting the fragment's
                // (later) key reading but keeping the neighbour's start.
                $passage['start_measure'] = $merged[count($merged) - 1]['start_measure'];
                array_pop($merged);
                $merged[] = $passage;
            } else {
                $merged[] = $passage;
            }
        }

        return $merged;
    }
}
