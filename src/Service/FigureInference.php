<?php

namespace App\Service;

use App\Model\Note;

/**
 * Infer figured bass figures from melody notes.
 *
 * Given a bass note and melody notes that appear at the same time,
 * determine what figures would produce those notes above the bass.
 */
class FigureInference
{
    /**
     * Infer figures from melody notes above a bass note.
     * Returns array of figures [{number, alter}, ...] or empty if can't infer.
     *
     * Algorithm:
     * - If only 1 melody note: infer the chord type from that note
     * - If 2+ melody notes: infer intervals for each, then ensure we have a valid triad/chord
     */
    public function inferFromMelody(Note $bass, array $melodyNotes, int $keyFifths, string $keyMode): array
    {
        if (empty($melodyNotes)) {
            return [];
        }

        $scale = PitchHelper::buildScale($keyFifths, $keyMode);
        $bassPc = $bass->pitchClass();

        // For single soprano note, infer which chord it belongs to based on its interval
        if (count($melodyNotes) === 1) {
            return $this->inferChordFromSingleNote($bass, $melodyNotes[0], $keyFifths, $keyMode, $scale);
        }

        // For multiple melody notes, collect intervals from each
        $figures = [];
        foreach ($melodyNotes as $melody) {
            $melodyPc = $melody->pitchClass();
            $interval = $this->getGenericInterval($bassPc, $melodyPc, $scale);

            if ($interval > 1) { // Ignore unison
                $alter = $this->inferAlter($bass, $melody, $interval, $scale);
                $figures[] = ['number' => $interval, 'alter' => $alter];
            }
        }

        // Sort by interval
        usort($figures, fn($a, $b) => $a['number'] <=> $b['number']);

        // Standard abbreviation: 5 3 → just 5
        if (count($figures) === 2 && $figures[0]['number'] === 5 && $figures[1]['number'] === 3
            && $figures[0]['alter'] === 0 && $figures[1]['alter'] === 0) {
            return [['number' => 5, 'alter' => 0]];
        }

        return $figures ?: [];
    }

    /**
     * Infer chord type from a single soprano note.
     * Determines if it's root position (3,5), first inversion (6), or second inversion (6/4).
     */
    private function inferChordFromSingleNote(Note $bass, Note $soprano, int $keyFifths, string $keyMode, array $scale): array
    {
        $bassPc = $bass->pitchClass();
        $sopranoPc = $soprano->pitchClass();

        // Determine the interval from bass to soprano
        $interval = $this->getGenericInterval($bassPc, $sopranoPc, $scale);

        // Common soprano positions in figured bass realization:
        // - Interval 3 (third above): root position (5,3) or sometimes just 5
        // - Interval 5 (fifth above): root position (5,3) or just 5
        // - Interval 6 (sixth above): first inversion (6)
        // - Interval 4 (fourth above): second inversion (4,3)

        $alter = $this->inferAlter($bass, $soprano, $interval, $scale);

        if ($interval === 3 || $interval === 5) {
            // Root position
            return [['number' => 5, 'alter' => 0]]; // 5 includes the 3 implicitly
        } elseif ($interval === 6) {
            // First inversion
            return [['number' => 6, 'alter' => $alter]];
        } elseif ($interval === 4) {
            // Second inversion
            return [['number' => 4, 'alter' => $alter], ['number' => 3, 'alter' => 0]];
        } else {
            // Default to root position if interval is ambiguous
            return [['number' => 5, 'alter' => 0]];
        }
    }

    /**
     * Calculate the generic diatonic interval from base to target pitch class.
     */
    private function getGenericInterval(int $basePc, int $targetPc, array $scale): int
    {
        // Find base position in scale
        $basePos = array_search($basePc, $scale);
        if ($basePos === false) {
            $basePos = $this->closestScaleDegreeIndex($basePc, $scale);
        }

        // Find target position (may need to go up an octave or search nearby octaves)
        $found = false;
        $targetPos = null;

        // Check current octave and next octave
        for ($oct = 0; $oct < 2; $oct++) {
            foreach ($scale as $idx => $pc) {
                if (($pc + $oct * 12) % 12 === $targetPc) {
                    $targetPos = $idx + $oct * 7;
                    $found = true;
                    break 2;
                }
            }
        }

        if (!$found) {
            return 0; // Can't determine interval
        }

        // Generic interval (1-based, where 1 = unison, 2 = second, 3 = third, etc.)
        return ($targetPos - $basePos) % 7 + 1;
    }

    /**
     * Find closest scale degree index for a chromatic pitch.
     */
    private function closestScaleDegreeIndex(int $pc, array $scale): int
    {
        $closest = 0;
        $minDist = 12;

        foreach ($scale as $idx => $scalePc) {
            $dist = min(abs($pc - $scalePc), 12 - abs($pc - $scalePc));
            if ($dist < $minDist) {
                $minDist = $dist;
                $closest = $idx;
            }
        }

        return $closest;
    }

    /**
     * Infer accidental alteration for a melody note relative to its scale position.
     */
    private function inferAlter(Note $bass, Note $melody, int $interval, array $scale): int
    {
        // Get the expected pitch class for this interval in the scale
        // getGenericInterval() returns 0 when it cannot place the note in the
        // scale; with no determinable interval there is no expected pitch to
        // compare against, so report no alteration.
        if ($interval < 1) {
            return $melody->alter;
        }

        $bassPc = $bass->pitchClass();
        $basePos = array_search($bassPc, $scale);
        if ($basePos === false) {
            $basePos = $this->closestScaleDegreeIndex($bassPc, $scale);
        }

        // Normalise into 0..6 — PHP's % keeps the sign of the operand, which can
        // yield a negative index for low base positions.
        $targetIdx = (($basePos + $interval - 1) % 7 + 7) % 7;
        $expectedPc = $scale[$targetIdx];

        $melodyPc = $melody->pitchClass();

        // Calculate semitone difference
        $diff = ($melodyPc - $expectedPc + 12) % 12;

        // Map to alter value
        if ($diff === 0) {
            return $melody->alter; // Natural
        } elseif ($diff === 1 || $diff === 11) {
            // Raised or lowered by semitone
            return $melody->alter > 0 ? 1 : -1;
        } elseif ($diff === 2) {
            return 1; // Sharp or double-sharp
        } elseif ($diff === 10) {
            return -1; // Flat or double-flat
        }

        return $melody->alter;
    }
}
