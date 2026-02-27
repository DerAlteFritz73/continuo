<?php

namespace App\Service;

use App\Model\Note;

/**
 * Interprets figured bass notation and computes the complete set of intervals
 * to realize above a given bass note.
 *
 * Rules implemented from:
 *   - Gasparini, Francesco (1708). L'Armonico Pratico al Cimbalo.
 *   - Delair, Denis (1724). Traité d'Accompagnement.
 *   - Wead & Knopke, ICMC 2007 decision tree system.
 *
 * Figured bass notation (MusicXML-style figure numbers):
 *   <nothing>  → 5 3   (root position triad)
 *   6          → 6 3   (first inversion triad)
 *   6 4        → 6 4   (second inversion triad — cadential 6/4)
 *   7          → 7 5 3 (root position seventh chord)
 *   6 5        → 6 5 3 (first inversion seventh chord)
 *   4 3        → 4 3 (+ 6 implied) (second inversion seventh chord — 6/4/3)
 *   4 2        → 4 2   (third inversion seventh chord — bass is 7th of chord)
 *   9 (or 2)   → 9 5 3 or 9 7 5 — suspension
 *   2          → 4 2   if no 4 present else 9
 */
class FiguredBassInterpreter
{
    /**
     * Given raw figures (array of ['number'=>int,'alter'=>int]),
     * return the expanded, ordered list of generic intervals to place above bass.
     * Each entry: ['interval'=>int, 'alter'=>int]
     *
     * @param array $rawFigures  e.g. [['number'=>6,'alter'=>0],['number'=>5,'alter'=>1]]
     * @param Note  $bass        The bass note (for context)
     * @param int   $keyFifths
     * @param string $keyMode
     * @return array  e.g. [['interval'=>3,'alter'=>0],['interval'=>5,'alter'=>0],['interval'=>6,'alter'=>0]]
     */
    public function expand(array $rawFigures, Note $bass, int $keyFifths, string $keyMode): array
    {
        // Sort figures descending (highest interval first)
        usort($rawFigures, fn($a, $b) => $b['number'] <=> $a['number']);

        $nums = array_column($rawFigures, 'number');

        // ---- Identify chord type from figures and fill in defaults ----

        // No figures → root-position triad
        if (empty($nums)) {
            return $this->withAlters([3, 5], [], $keyFifths, $keyMode, $bass);
        }

        // Figure "5 #" alone (rare: augmented fifth) — treat as altered 5 3
        // Figure "6" alone → first inversion: 3, 6
        if ($nums === [6]) {
            return $this->withAlters([3, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "6 4" → second inversion (cadential or passing 6/4): 4, 6
        if ($nums === [6, 4]) {
            return $this->withAlters([4, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "7" alone → root-position 7th chord: 3, 5, 7
        if ($nums === [7]) {
            return $this->withAlters([3, 5, 7], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "7 5" or "7 5 3" → root-position 7th (explicit): 3, 5, 7
        if (in_array(7, $nums) && in_array(5, $nums)) {
            return $this->withAlters([3, 5, 7], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "6 5" → first inversion 7th chord: 3, 5, 6  (= 6/5/3)
        if ($nums === [6, 5]) {
            return $this->withAlters([3, 5, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "6 5 3" → first inversion 7th (explicit): 3, 5, 6
        if (in_array(6, $nums) && in_array(5, $nums)) {
            return $this->withAlters([3, 5, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "4 3" → second inversion 7th chord: 3, 4, 6  (= 6/4/3)
        if ($nums === [4, 3]) {
            return $this->withAlters([3, 4, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "4 2" or "2" → third inversion 7th chord: 2, 4, 6  (= 6/4/2)
        if ($nums === [4, 2] || $nums === [2]) {
            return $this->withAlters([2, 4, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Suspensions: 9 – 8 → place 5, 3, 9 (then resolve 9→8)
        if (in_array(9, $nums)) {
            return $this->withAlters([3, 5, 9], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Suspension 7 – 6 (bass stays, 7 resolves to 6)
        if ($nums === [7, 6]) {
            // Start on 7/3, resolve handled by voice leading engine
            return $this->withAlters([3, 7], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Suspension 4 – 3 (4th resolves to 3rd)
        if ($nums === [4] || ($nums === [4, 3] && count($nums) === 2)) {
            return $this->withAlters([4, 5], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "5#" (augmented fifth): 3, #5
        if ($nums === [5] && !empty($rawFigures)) {
            return $this->withAlters([3, 5], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Diminished 7th: "°7" encoded as 7 with b-alter on 3 and 5
        if (in_array(7, $nums) && in_array(3, $nums)) {
            return $this->withAlters([3, 5, 7], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Fallback: use the numbers as given + fill in 3 if not present
        $intervals = $nums;
        if (!in_array(3, $intervals)) {
            $intervals[] = 3;
        }
        sort($intervals);
        return $this->withAlters($intervals, $rawFigures, $keyFifths, $keyMode, $bass);
    }

    /**
     * Take a list of generic intervals and annotate each with the correct alteration
     * from the raw figures (override over key-signature default).
     *
     * @return array  [['interval'=>int, 'alter'=>int], ...]
     */
    private function withAlters(array $intervals, array $rawFigures, int $keyFifths, string $keyMode, Note $bass): array
    {
        // Build a lookup from figure number → alter
        $alterMap = [];
        foreach ($rawFigures as $f) {
            $alterMap[$f['number']] = $f['alter'];
        }

        $result = [];
        foreach ($intervals as $interval) {
            // Check if the figure explicitly specifies an alteration
            $alter = $alterMap[$interval] ?? null;

            // Default alter comes from the key (we use 0 here; PitchHelper handles key accidentals)
            $result[] = [
                'interval' => $interval,
                'alter'    => $alter ?? 0,
                'explicit' => ($alter !== null),
            ];
        }
        return $result;
    }

    /**
     * Unfigured bass decision tree (Gasparini / Delair rules).
     *
     * Determines the most likely figured bass for an unfigured bass note
     * based on:
     *  - Scale degree of the bass note
     *  - Melodic motion (step up/down, leap up/down)
     *  - Mode (major/minor)
     *
     * Decision tree (simplified from Wead & Knopke 2007):
     *
     *  Bass step is:
     *   1 (tonic)       → 5 3       (I)
     *   2 (supertonic)  → 6         (vii°6 in major / ii°6) or 6 5 if descending
     *   3 (mediant)     → 6         (I6 or vi6)
     *   4 (subdominant) → 5 3       (IV) or 6 4 if passing
     *   5 (dominant)    → 5 3       (V) or 7 if leading to tonic
     *   6 (submediant)  → 6         (IV6) or 5 3 (vi)
     *   7 (leading tone) → 6        (V6) or 6 5
     *
     * Motion modifiers (Delair 1724):
     *  - Ascending step from 5 → prefer 6 on next note
     *  - Descending step to 1 → V7 on prev note
     *  - Ascending leap of 4th up → add 6 on destination
     *  - Bass moves by 4th/5th → typically 5 3
     *
     * @param int    $scaleDegree   1..7
     * @param string $motion        'step-up','step-down','leap-up','leap-down','same','start'
     * @param string $nextMotion    Motion to the NEXT note
     * @param string $mode          'major'|'minor'
     * @return array  Raw figures [['number'=>int,'alter'=>int]]
     */
    public function unfiguredDecision(
        int    $scaleDegree,
        string $motion,
        string $nextMotion,
        string $mode,
        int    $leapSize = 0
    ): array {
        $isMajor = strtolower($mode) === 'major';

        // --- Gasparini Rule Set (primary) ---

        // Scale degree 7 (leading tone) → first inversion dominant (V6)
        if ($scaleDegree === 7) {
            // Leading tone always takes 6 (V6)
            return $this->makeFig([6]);
        }

        // Scale degree 4 ascending step → 6 (passing) per Delair
        if ($scaleDegree === 4 && $motion === 'step-up') {
            return $this->makeFig([6]);
        }

        // Scale degree 2 → generally 6 (first inversion), except when harmonized as II
        if ($scaleDegree === 2) {
            // Descending: Delair says use 6/5 on descending 2
            if ($motion === 'step-down') {
                return $this->makeFig([6, 5]);
            }
            return $this->makeFig([6]);
        }

        // Scale degree 3 → first inversion I (or VI6)
        if ($scaleDegree === 3) {
            return $this->makeFig([6]);
        }

        // Scale degree 6 → depends on context
        if ($scaleDegree === 6) {
            // Submediant ascending step → 5 3 (vi)
            if ($motion === 'step-up') {
                return $this->makeFig([5, 3]);
            }
            // Submediant descending → 6 (IV6)
            if ($motion === 'step-down') {
                return $this->makeFig([6]);
            }
            return $this->makeFig($isMajor ? [5, 3] : [6]);
        }

        // Scale degree 5 (dominant)
        if ($scaleDegree === 5) {
            // If next motion descends by step to 1 → use V7
            if ($nextMotion === 'step-down') {
                return $this->makeFig([7]);
            }
            return $this->makeFig([5, 3]);
        }

        // Scale degree 1 (tonic) → I (5/3) always
        if ($scaleDegree === 1) {
            return $this->makeFig([5, 3]);
        }

        // Scale degree 4 (subdominant)
        if ($scaleDegree === 4) {
            // Leap of 4th up or 5th down → cadential 6/4
            if ($leapSize === 4 && ($motion === 'leap-up' || $motion === 'leap-down')) {
                return $this->makeFig([6, 4]);
            }
            return $this->makeFig([5, 3]);
        }

        // Default → root position triad
        return $this->makeFig([5, 3]);
    }

    private function makeFig(array $numbers): array
    {
        return array_map(fn($n) => ['number' => $n, 'alter' => 0], $numbers);
    }
}
