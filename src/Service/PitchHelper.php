<?php

namespace App\Service;

use App\Model\Note;

/**
 * Static helper for pitch arithmetic: intervals, key-aware accidentals,
 * scale degree computation, diatonic transposition.
 */
class PitchHelper
{
    // Chromatic pitch classes (C=0)
    public const STEPS = ['C', 'D', 'E', 'F', 'G', 'A', 'B'];

    // Semitone distances from C for each step
    private const STEP_SEMITONES = [
        'C' => 0, 'D' => 2, 'E' => 4, 'F' => 5,
        'G' => 7, 'A' => 9, 'B' => 11,
    ];

    // Scale degrees in semitones for major scale
    private const MAJOR_SCALE = [0, 2, 4, 5, 7, 9, 11]; // degrees 1-7

    // Scale degrees in semitones for natural minor
    private const MINOR_SCALE = [0, 2, 3, 5, 7, 8, 10];

    /**
     * Build the 7 diatonic pitch classes for a given key.
     * Returns array indexed 0..6 (scale degrees 1..7), each value is pitch class 0..11.
     *
     * Uses the circle-of-fifths formula directly so flat keys (e.g. Bb, Eb) are correct.
     * Major tonic pc = ((fifths * 7) % 12 + 12) % 12
     * Minor tonic pc = ((fifths * 7 - 3) % 12 + 12) % 12  (relative minor is 3 semitones below major)
     */
    public static function buildScale(int $keyFifths, string $keyMode): array
    {
        if (strtolower($keyMode) === 'minor') {
            $tonicPc = ((($keyFifths * 7) - 3) % 12 + 12) % 12;
        } else {
            $tonicPc = (($keyFifths * 7) % 12 + 12) % 12;
        }

        $template = strtolower($keyMode) === 'minor'
            ? self::MINOR_SCALE
            : self::MAJOR_SCALE;

        return array_map(fn(int $interval) => ($tonicPc + $interval) % 12, $template);
    }

    /**
     * Given a bass MIDI pitch and a generic interval (1=unison, 3=third, 5=fifth, etc.),
     * return the MIDI pitch of the diatonic note above in the given key.
     * keyFifths: circle-of-fifths position, keyMode: 'major'|'minor'
     */
    public static function diatonicInterval(
        Note $bass,
        int  $interval,
        int  $keyFifths,
        string $keyMode
    ): Note {
        $scale   = self::buildScale($keyFifths, $keyMode);
        $bassPc  = $bass->pitchClass();

        // Find bass scale degree index
        $bassIdx = array_search($bassPc, $scale);
        if ($bassIdx === false) {
            // Bass is chromatic / altered — find closest scale degree
            $bassIdx = self::closestScaleDegree($bassPc, $scale);
        }

        // Compute target scale index (0-based)
        $targetIdx = ($bassIdx + ($interval - 1)) % 7;
        $octaveShift = intdiv($bassIdx + ($interval - 1), 7);

        $targetPc = $scale[$targetIdx];

        // Determine target MIDI pitch (above the bass)
        $bassOctave = $bass->octave;
        $targetMidi = ($bassOctave + 1) * 12 + $targetPc;

        // Ensure target is above bass
        $bassMidi = $bass->midiPitch();
        while ($targetMidi <= $bassMidi) {
            $targetMidi += 12;
        }
        // Bring within a reasonable range (no more than 2 octaves above bass)
        while ($targetMidi - $bassMidi > 24) {
            $targetMidi -= 12;
        }

        return self::midiToNote($targetMidi, $bass->duration, $bass->type, $bass->voice, $keyFifths);
    }

    /**
     * Convert a MIDI pitch to a Note object with the correct step/octave/alter.
     * keyFifths: use negative values (flat keys) to prefer flat spellings,
     *            positive (sharp keys) to prefer sharp spellings.
     */
    public static function midiToNote(int $midi, float $duration = 1.0, string $type = 'quarter', ?int $voice = null, int $keyFifths = 0): Note
    {
        $pc     = $midi % 12;
        $octave = intdiv($midi, 12) - 1;

        // Flat keys: prefer flat enharmonic spellings for chromatic pitches
        $flatPcMap = [
            0  => ['C', 0],
            1  => ['D', -1],  // Db
            2  => ['D', 0],
            3  => ['E', -1],  // Eb
            4  => ['E', 0],
            5  => ['F', 0],
            6  => ['G', -1],  // Gb
            7  => ['G', 0],
            8  => ['A', -1],  // Ab
            9  => ['A', 0],
            10 => ['B', -1],  // Bb
            11 => ['B', 0],
        ];

        // Sharp keys: prefer sharp spellings
        $sharpPcMap = [
            0  => ['C', 0],
            1  => ['C', 1],   // C#
            2  => ['D', 0],
            3  => ['D', 1],   // D#
            4  => ['E', 0],
            5  => ['F', 0],
            6  => ['F', 1],   // F#
            7  => ['G', 0],
            8  => ['G', 1],   // G#
            9  => ['A', 0],
            10 => ['A', 1],   // A#
            11 => ['B', 0],
        ];

        $pcMap = ($keyFifths < 0) ? $flatPcMap : $sharpPcMap;
        [$step, $alter] = $pcMap[$pc];
        return new Note(step: $step, octave: $octave, duration: $duration, alter: $alter, type: $type, voice: $voice);
    }

    /**
     * Convert MIDI pitch to Note, preferring a specific enharmonic spelling (step).
     */
    public static function midiToNoteWithStep(int $midi, string $preferredStep, float $duration = 1.0, string $type = 'quarter', ?int $voice = null): Note
    {
        $octave = intdiv($midi, 12) - 1;
        $pc     = $midi % 12;
        $expected = self::STEP_SEMITONES[$preferredStep];
        $alter    = ($pc - $expected + 12) % 12;

        // Normalize: if alter >= 6 use negative (flat)
        if ($alter > 2) {
            $alter -= 12; // e.g., 10 → -2 (double flat, unusual)
        }

        // Clamp
        $alter = max(-2, min(2, $alter));
        return new Note(step: $preferredStep, octave: $octave, duration: $duration, alter: $alter, type: $type, voice: $voice);
    }

    public static function stepToPitchClass(string $step): int
    {
        return self::STEP_SEMITONES[$step] ?? 0;
    }

    /**
     * Returns the tonic step letter for a given key signature.
     */
    public static function tonicFromFifths(int $fifths, string $mode): string
    {
        // Indexed from fifths -7 to +7 (offset by 7)
        // Major:  Cb  Gb  Db  Ab  Eb  Bb  F   C   G   D   A   E   B   F#  C#
        $majorOrder = ['Cb','Gb','Db','Ab','Eb','Bb','F','C','G','D','A','E','B','F#','C#'];
        // Minor:  ab  eb  bb  f   c   g   d   a   e   b   f#  c#  g#  d#  a#
        $minorOrder = ['Ab','Eb','Bb','F','C','G','D','A','E','B','F#','C#','G#','D#','A#'];

        $order = strtolower($mode) === 'minor' ? $minorOrder : $majorOrder;
        // fifths ranges -7..7; we offset by 7 to index
        $idx = $fifths + 7;
        return $order[$idx] ?? 'C';
    }

    /**
     * Semitone interval between two MIDI pitches (always positive, mod 12).
     */
    public static function intervalClass(int $midi1, int $midi2): int
    {
        return abs($midi1 - $midi2) % 12;
    }

    private static function closestScaleDegree(int $pitchClass, array $scale): int
    {
        $best = 0;
        $bestDist = 12;
        foreach ($scale as $idx => $pc) {
            $dist = min(abs($pc - $pitchClass), 12 - abs($pc - $pitchClass));
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best     = $idx;
            }
        }
        return $best;
    }

    /**
     * The diatonic step letter N semitones above a given step.
     * stepIndex: index into STEPS array.
     * count: number of diatonic steps (1-indexed generic interval - 1).
     */
    public static function stepAtDiatonic(string $fromStep, int $genericIntervalMinus1): string
    {
        $idx = array_search($fromStep, self::STEPS);
        if ($idx === false) {
            return 'C';
        }
        return self::STEPS[($idx + $genericIntervalMinus1) % 7];
    }
}
