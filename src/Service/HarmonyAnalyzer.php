<?php

namespace App\Service;

use App\Model\Note;
use App\Model\Score;

/**
 * Analyzes the harmonic context of bass notes:
 *  - Scale degree (1..7)
 *  - Melodic motion type (step-up, step-down, leap-up, leap-down, same, start)
 *  - Key changes
 */
class HarmonyAnalyzer
{
    public function __construct(private readonly PitchHelper $pitch) {}

    /**
     * Compute the scale degree (1..7) of a note in a key.
     */
    public function scaleDegree(Note $note, int $keyFifths, string $keyMode): int
    {
        $scale = PitchHelper::buildScale($keyFifths, $keyMode);
        $pc    = $note->pitchClass();

        // Find exact match
        $idx = array_search($pc, $scale);
        if ($idx !== false) {
            return $idx + 1;
        }

        // No exact match → find closest
        $bestDeg  = 1;
        $bestDist = 12;
        foreach ($scale as $deg => $scalePc) {
            $dist = min(abs($pc - $scalePc), 12 - abs($pc - $scalePc));
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestDeg  = $deg + 1;
            }
        }
        return $bestDeg;
    }

    /**
     * Determine melodic motion from prevNote to currNote.
     * Returns 'start', 'step-up', 'step-down', 'leap-up', 'leap-down', 'same'
     * along with leap size in semitones.
     */
    public function motion(?Note $prevNote, ?Note $currNote): array
    {
        if ($prevNote === null || $prevNote->isRest()) {
            return ['type' => 'start', 'size' => 0];
        }
        if ($currNote === null || $currNote->isRest()) {
            return ['type' => 'start', 'size' => 0];
        }

        $interval = $currNote->midiPitch() - $prevNote->midiPitch();
        $absInt   = abs($interval);

        if ($absInt === 0) {
            return ['type' => 'same', 'size' => 0];
        }

        // Generic (diatonic) step = 1 or 2 semitones
        $isStep = ($absInt <= 2);

        if ($isStep && $interval > 0) {
            return ['type' => 'step-up', 'size' => $absInt];
        }
        if ($isStep && $interval < 0) {
            return ['type' => 'step-down', 'size' => $absInt];
        }
        if ($interval > 0) {
            return ['type' => 'leap-up', 'size' => $absInt];
        }
        return ['type' => 'leap-down', 'size' => $absInt];
    }

    /**
     * Compute generic (diatonic) interval in semitones
     */
    public function genericInterval(int $semitones): int
    {
        // Map semitones to generic interval
        return match(true) {
            $semitones <= 2  => 2,   // second
            $semitones <= 4  => 3,   // third
            $semitones <= 5  => 4,   // fourth
            $semitones <= 7  => 5,   // fifth
            $semitones <= 9  => 6,   // sixth
            $semitones <= 11 => 7,   // seventh
            default          => 8,   // octave+
        };
    }
}
