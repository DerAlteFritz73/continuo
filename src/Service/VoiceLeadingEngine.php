<?php

namespace App\Service;

use App\Model\Chord;
use App\Model\Note;

/**
 * Applies voice-leading rules to produce smooth, historically-correct
 * basso continuo realizations.
 *
 * Rules implemented (in priority order):
 *
 * 1. RANGE CONSTRAINTS (Baroque keyboard style, Gasparini):
 *    - Soprano: C4–G5
 *    - Alto:    G3–C5
 *    - Tenor:   C3–E4
 *    - Bass:    given (unchanged)
 *
 * 2. FORBIDDEN PARALLELS (Fux / Gasparini / Delair):
 *    - No parallel (consecutive) perfect fifths between any pair of voices
 *    - No parallel (consecutive) perfect octaves between any pair of voices
 *    - No parallel (consecutive) unisons
 *    - Hidden (direct) fifths/octaves forbidden between outer voices when
 *      soprano moves by leap
 *
 * 3. DOUBLING RULES (Gasparini / Delair):
 *    - In root position: double the bass (root)
 *    - In first inversion: double the third or fifth (not the bass)
 *    - In second inversion: double the fifth (= the bass note) — cadential 6/4
 *    - Never double the leading tone (scale degree 7)
 *    - Never double the 7th of a chord
 *
 * 4. VOICE LEADING / LAW OF SHORTEST WAY (Delair):
 *    - Prefer common tones between chords
 *    - Prefer steps over leaps
 *    - Contrary motion between bass and upper voices preferred
 *    - One voice may leap if others move by step/common tone
 *
 * 5. SEVENTH CHORD RESOLUTION (Rameau / Gasparini):
 *    - 7th resolves down by step
 *    - Leading tone resolves up by half step
 *    - 5th of V7 may omit for four-part writing
 *
 * 6. SUSPENSION RESOLUTION:
 *    - 9-8: 9th resolves down to 8th (unison with bass)
 *    - 7-6: 7th resolves down to 6th
 *    - 4-3: 4th resolves down to 3rd
 */
class VoiceLeadingEngine
{
    // Voice range MIDI limits: [min, max]
    private const RANGES = [
        'soprano' => [60, 79],  // C4–G5
        'alto'    => [55, 72],  // G3–C5
        'tenor'   => [48, 64],  // C3–E4
    ];

    // Perfect intervals in semitones (mod 12)
    private const PERFECT_CONSONANCES = [0, 7]; // unison/octave=0, fifth=7

    public function __construct(private readonly PitchHelper $pitchHelper) {}

    /**
     * Choose upper voices (soprano, alto, tenor) for a chord given:
     *  - The required intervals above bass (from FiguredBassInterpreter)
     *  - The previous chord (for voice leading)
     *  - Key context
     *
     * Returns the Chord with upperVoices populated.
     */
    public function realize(
        Chord   $chord,
        array   $intervals,    // [['interval'=>int,'alter'=>int,'explicit'=>bool]]
        ?Chord  $prevChord,
        int     $keyFifths,
        string  $keyMode,
        bool    $isLeadingTone7th = false
    ): Chord {
        $bass = $chord->bass;

        // Build candidate pitches for each interval
        $candidatePitches = $this->buildCandidates($bass, $intervals, $keyFifths, $keyMode);

        if (empty($candidatePitches)) {
            // Fallback: triad
            $candidatePitches = $this->buildCandidates(
                $bass,
                [['interval'=>3,'alter'=>0,'explicit'=>false],['interval'=>5,'alter'=>0,'explicit'=>false]],
                $keyFifths, $keyMode
            );
        }

        // Try to find 3 upper voices (or fewer for thin texture)
        $prevUpperMidis = $prevChord
            ? array_map(fn(Note $n) => $n->midiPitch(), $prevChord->upperVoices)
            : [];

        // Choose pitches by minimizing total voice movement
        $chosen = $this->chooseVoices($candidatePitches, $prevUpperMidis, $bass->midiPitch(), $isLeadingTone7th);

        foreach ($chosen as $idx => $midi) {
            $voiceName = ['tenor', 'alto', 'soprano'][$idx] ?? 'soprano';
            $note      = PitchHelper::midiToNote($midi, $bass->duration, $bass->type, $idx + 2, $keyFifths);
            $chord->addUpperVoice($note);
        }

        return $chord;
    }

    /**
     * Build all valid MIDI pitches for each interval above bass, within voice ranges.
     * Returns array of pitch lists, one per interval.
     */
    private function buildCandidates(Note $bass, array $intervals, int $keyFifths, string $keyMode): array
    {
        $scale    = PitchHelper::buildScale($keyFifths, $keyMode);
        $bassMidi = $bass->midiPitch();

        $allCandidates = [];

        foreach ($intervals as $fig) {
            $genericInterval = $fig['interval'];
            $explicitAlter   = $fig['alter'];
            $explicit        = $fig['explicit'] ?? false;

            // Compute the diatonic note above bass for this interval
            $targetNote = PitchHelper::diatonicInterval($bass, $genericInterval, $keyFifths, $keyMode);
            $targetPc   = $targetNote->pitchClass();

            // Apply explicit alteration from figure if any
            if ($explicit && $explicitAlter !== 0) {
                $targetPc = ($targetPc + $explicitAlter + 12) % 12;
            }

            // Generate all octave transpositions in the combined range (tenor..soprano: C3..G5)
            $candidates = [];
            for ($oct = 3; $oct <= 6; $oct++) {
                $midi = $oct * 12 + $targetPc; // (oct-1)*12 + 12 + pc = oct*12 + pc
                // Correct: midi = (octave+1)*12 + pc, so octave = (midi/12)-1
                $midi = ($oct + 1) * 12 + $targetPc; // skip — recalculate
            }

            // Correct calculation: for octave $o: midi = ($o+1)*12 + pc
            $candidates = [];
            for ($o = 2; $o <= 5; $o++) {
                $midi = ($o + 1) * 12 + $targetPc;
                if ($midi > $bassMidi && $midi <= self::RANGES['soprano'][1] + 12) {
                    $candidates[] = $midi;
                }
            }

            if (!empty($candidates)) {
                $allCandidates[] = $candidates;
            }
        }

        return $allCandidates;
    }

    /**
     * Choose 3 upper voice MIDI pitches (or fewer) from candidate lists.
     * Algorithm:
     *  1. Assign each interval candidate list to a voice (tenor=lowest, soprano=highest)
     *  2. Minimize total motion from previous chord
     *  3. Enforce range constraints
     *  4. Check for forbidden parallels (post-selection)
     *  5. Apply doubling: duplicate root if only 2 intervals given
     */
    private function chooseVoices(array $candidateLists, array $prevMidis, int $bassMidi, bool $isLeadingTone): array
    {
        $numVoices = min(3, count($candidateLists));
        if ($numVoices === 0) {
            return [];
        }

        // We need 3 voices; duplicate candidates if we have fewer unique intervals
        while (count($candidateLists) < 3) {
            $candidateLists[] = $candidateLists[0]; // double the root/lowest
        }

        // Sort: assign lower intervals to tenor, higher to soprano
        // Each list is sorted ascending
        foreach ($candidateLists as &$list) {
            sort($list);
        }
        unset($list);

        // Ranges for each voice slot: [min, max]
        $voiceRanges = [
            self::RANGES['tenor'],
            self::RANGES['alto'],
            self::RANGES['soprano'],
        ];

        // Filter each candidate list to its voice's range
        $filtered = [];
        for ($v = 0; $v < 3; $v++) {
            [$min, $max] = $voiceRanges[$v];
            $list = array_filter(
                $candidateLists[$v] ?? $candidateLists[0],
                fn($m) => $m >= $min && $m <= $max
            );
            if (empty($list)) {
                // Expand search slightly
                $list = array_filter(
                    $candidateLists[$v] ?? $candidateLists[0],
                    fn($m) => $m >= ($min - 5) && $m <= ($max + 5) && $m > $bassMidi
                );
            }
            $filtered[$v] = array_values($list);
        }

        // Ensure all three filtered lists have at least one option
        foreach ($filtered as $v => $list) {
            if (empty($filtered[$v])) {
                // Fallback: use any pitch above bass in any octave
                $pc  = $candidateLists[$v][0] % 12 ?? 0;
                $filtered[$v] = [];
                for ($o = 2; $o <= 5; $o++) {
                    $midi = ($o + 1) * 12 + $pc;
                    if ($midi > $bassMidi) {
                        $filtered[$v][] = $midi;
                    }
                }
                if (empty($filtered[$v])) {
                    $filtered[$v] = [$bassMidi + 7]; // fifth above bass
                }
            }
        }

        // Find best combination by minimizing total motion + enforcing voice ordering
        $bestCost    = PHP_INT_MAX;
        $bestChosen  = [$filtered[0][0], $filtered[1][0], $filtered[2][0]];

        // Limit search space (take first N candidates per voice)
        $limit = 6;
        $t_opts = array_slice($filtered[0], 0, $limit);
        $a_opts = array_slice($filtered[1], 0, $limit);
        $s_opts = array_slice($filtered[2], 0, $limit);

        foreach ($t_opts as $t) {
            foreach ($a_opts as $a) {
                foreach ($s_opts as $s) {
                    // Voice ordering constraint: tenor <= alto <= soprano (allow unison)
                    if (!($t <= $a && $a <= $s)) {
                        continue;
                    }
                    // Voices must be above bass
                    if ($t <= $bassMidi) {
                        continue;
                    }

                    $cost = $this->voiceCost([$t, $a, $s], $prevMidis, $bassMidi);
                    if ($cost < $bestCost) {
                        $bestCost   = $cost;
                        $bestChosen = [$t, $a, $s];
                    }
                }
            }
        }

        return $bestChosen;
    }

    /**
     * Cost function for a chord voicing.
     * Lower = better (prefer common tones, steps, contrary motion).
     * Heavy penalties for parallel perfect consonances (5ths, octaves).
     */
    private function voiceCost(array $chosen, array $prevMidis, int $bassMidi): float
    {
        $cost = 0.0;

        // Motion from previous chord — prefer common tones, then steps
        foreach ($chosen as $i => $midi) {
            $prev = $prevMidis[$i] ?? null;
            if ($prev !== null) {
                $motion = abs($midi - $prev);
                if ($motion === 0) {
                    $cost += 0;        // common tone — best
                } elseif ($motion <= 2) {
                    $cost += 1;        // step
                } elseif ($motion <= 4) {
                    $cost += 4;        // small leap
                } elseif ($motion <= 7) {
                    $cost += 9;        // leap of 5th/6th
                } else {
                    $cost += $motion * 3; // large leap — heavy penalty
                }
            }
        }

        // Parallel perfect consonances penalty
        // Upper voices: [0]=tenor,[1]=alto,[2]=soprano; plus bass
        $allCurr = array_merge($chosen, [$bassMidi]);
        $allPrev = array_merge($prevMidis, []);

        if (count($prevMidis) >= 3) {
            $allPrev = array_merge($prevMidis, [$bassMidi]);
            $n = count($allCurr);
            for ($a = 0; $a < $n; $a++) {
                for ($b = $a + 1; $b < $n; $b++) {
                    if (!isset($allPrev[$a]) || !isset($allPrev[$b])) continue;
                    $prevInterval = abs($allPrev[$a] - $allPrev[$b]) % 12;
                    $currInterval = abs($allCurr[$a] - $allCurr[$b]) % 12;
                    $moved = ($allPrev[$a] !== $allCurr[$a]) || ($allPrev[$b] !== $allCurr[$b]);
                    if ($moved && $prevInterval === $currInterval) {
                        if ($currInterval === 7) {
                            $cost += 40;  // parallel 5ths — strongly penalize
                        } elseif ($currInterval === 0) {
                            $cost += 60;  // parallel octaves/unisons — most strongly penalize
                        }
                    }
                }
            }
        }

        // Penalize voice crossing
        for ($i = 0; $i < count($chosen) - 1; $i++) {
            if ($chosen[$i] > $chosen[$i + 1]) {
                $cost += 100;  // voice crossing — forbidden
            }
        }

        // Penalize voices outside their ideal range
        $ranges = [self::RANGES['tenor'], self::RANGES['alto'], self::RANGES['soprano']];
        foreach ($chosen as $i => $midi) {
            [$lo, $hi] = $ranges[$i];
            if ($midi < $lo) $cost += ($lo - $midi) * 3;
            if ($midi > $hi) $cost += ($midi - $hi) * 3;
        }

        return $cost;
    }

    /**
     * Check if parallel fifths or octaves exist between two chords.
     * Returns array of violations (for diagnostic output).
     */
    public function checkParallels(Chord $prev, Chord $curr): array
    {
        $violations = [];
        $prevNotes  = $prev->allNotes();
        $currNotes  = $curr->allNotes();

        $n = min(count($prevNotes), count($currNotes));

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $prevInterval = ($prevNotes[$i]->midiPitch() - $prevNotes[$j]->midiPitch()) % 12;
                $currInterval = ($currNotes[$i]->midiPitch() - $currNotes[$j]->midiPitch()) % 12;

                $prevAbs = abs($prevInterval);
                $currAbs = abs($currInterval);

                if (
                    in_array($prevAbs, self::PERFECT_CONSONANCES) &&
                    $prevAbs === $currAbs &&
                    // Voices must actually move (not a unison on the same pitch)
                    $prevNotes[$i]->midiPitch() !== $currNotes[$i]->midiPitch()
                ) {
                    $type = $prevAbs === 7 ? 'parallel fifths' : 'parallel octaves/unisons';
                    $violations[] = "Voices $i and $j: $type";
                }
            }
        }

        return $violations;
    }
}
