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
        private readonly LocalKeyEstimator $keyEstimator,
        private readonly CadenceDetector   $cadenceDetector,
    ) {}

    /**
     * @return list<array{start_measure:int, end_measure:int, key:array{fifths:int,mode:string},
     *                    confidence:string, correlation:float, cadence:?string}>
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
                $passages[] = $this->makePassage($segment, $cadenceHere, $divisions);
                $segment    = [];
            }
        }

        return $this->mergeShortPassages($passages);
    }

    /**
     * @param Measure[] $segment
     * @param array{type:string}|null $cadence
     */
    private function makePassage(array $segment, ?array $cadence, int $divisions): array
    {
        $estimate = $this->keyEstimator->estimateFromHistogram(
            $this->histogramForRange($segment, $divisions)
        );

        $first = $segment[0];
        $last  = end($segment);

        return [
            'start_measure' => $first->number,
            'end_measure'   => $last->number,
            'key'           => ['fifths' => $estimate['fifths'], 'mode' => $estimate['mode']],
            'confidence'    => $estimate['confidence'],
            'correlation'   => round($estimate['correlation'], 3),
            'cadence'       => $cadence['type'] ?? null,
        ];
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
                $prev                  = array_pop($merged);
                $prev['end_measure']   = $passage['end_measure'];
                $prev['key']           = $passage['key'];
                $prev['confidence']    = $passage['confidence'];
                $prev['correlation']   = $passage['correlation'];
                $prev['cadence']       = $passage['cadence'];
                $merged[]              = $prev;
            } else {
                $merged[] = $passage;
            }
        }

        return $merged;
    }
}
