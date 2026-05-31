<?php

namespace App\Service;

use App\Model\Chord;
use App\Model\Measure;
use App\Model\Note;
use App\Model\Score;

/**
 * Main orchestrator for basso continuo realization.
 *
 * Algorithm (based on Wead & Knopke ICMC 2007 + Gasparini 1729 + Delair 1724):
 *
 * For each bass note in sequence:
 *  1. Determine if figured bass is already present
 *  2. If not, run the unfigured bass decision tree:
 *     a. Compute scale degree
 *     b. Compute melodic motion (prev → curr, curr → next)
 *     c. Select figures from decision tree
 *  3. Expand figures to full interval list (FiguredBassInterpreter)
 *  4. Realize upper voices (VoiceLeadingEngine)
 *  5. Store realized Chord back into Measure
 */
class ContinuoRealizer
{
    public function __construct(
        private readonly FiguredBassInterpreter $interpreter,
        private readonly HarmonyAnalyzer        $analyzer,
        private readonly VoiceLeadingEngine     $voiceLeading,
    ) {}

    public function realize(Score $score, int $numVoices = 4): Score
    {
        $prevChord    = null;
        $prevNote     = null;
        $allBassNotes = $this->collectAllBassNotes($score);
        $totalNotes   = count($allBassNotes);

        $noteIndex = 0;

        foreach ($score->measures as $measure) {
            $keyFifths  = $measure->keySignature['fifths'] ?? $score->keyFifths;
            $keyMode    = $measure->keySignature['mode']   ?? $score->keyMode;
            $bassOffset = 0.0; // cumulative quarter-note offset within this measure

            foreach ($measure->bassNotes as $i => $bassNote) {
                if ($bassNote->isRest()) {
                    $prevNote   = null;
                    $prevChord  = null;
                    $bassOffset += $bassNote->duration;
                    $noteIndex++;
                    continue;
                }

                // Determine motion context
                $nextNote = $allBassNotes[$noteIndex + 1] ?? null;
                $nextNote = ($nextNote && $nextNote->isRest()) ? null : $nextNote;

                $currMotion = $this->analyzer->motion($prevNote, $bassNote);
                $nextMotion = $this->analyzer->motion($bassNote, $nextNote);

                // Scale degree of current bass note
                $scaleDeg = $this->analyzer->scaleDegree($bassNote, $keyFifths, $keyMode);

                // Get raw figures (from file or from decision tree)
                $rawFigures   = $bassNote->figuredBass;
                $decisionSteps = [];
                $figuresSource = 'file';

                if (empty($rawFigures)) {
                    // Unfigured bass → run decision tree
                    $figuresSource  = 'computed';
                    $decisionResult = $this->interpreter->unfiguredDecision(
                        scaleDegree: $scaleDeg,
                        motion: $currMotion['type'],
                        nextMotion: $nextMotion['type'],
                        mode: $keyMode,
                        leapSize: $this->analyzer->genericInterval($currMotion['size']),
                    );
                    $rawFigures    = $decisionResult['figures'];
                    $decisionSteps = $decisionResult['trace'];
                }

                // Expand figures to interval list
                $intervals = $this->interpreter->expand($rawFigures, $bassNote, $keyFifths, $keyMode);

                // Determine if bass is leading tone (scale degree 7)
                $isLeadingTone = ($scaleDeg === 7);

                // Build chord object
                $chord = new Chord(
                    bass: $bassNote,
                    figures: $rawFigures,
                    chordSymbol: $this->chordSymbol($scaleDeg, $rawFigures, $keyMode),
                );

                // Store decision context and trace in chord
                $chord->decisionTrace = [
                    'scaleDegree'  => $scaleDeg,
                    'motionIn'     => $currMotion['type'],
                    'motionInSize' => $currMotion['size'] ?? 0,
                    'motionOut'    => $nextMotion['type'],
                    'figuresSource' => $figuresSource,
                    'keyFifths'    => $keyFifths,
                    'keyMode'      => $keyMode,
                    'steps'        => $decisionSteps,
                ];

                // Find melody pitch class sounding at this beat (if any)
                $melodyPc = $this->findMelodyPc($measure->melodyNotes, $bassOffset);

                // Realize upper voices
                $chord = $this->voiceLeading->realize(
                    chord: $chord,
                    intervals: $intervals,
                    prevChord: $prevChord,
                    keyFifths: $keyFifths,
                    keyMode: $keyMode,
                    isLeadingTone7th: $isLeadingTone,
                    melodyPc: $melodyPc,
                    numVoices: $numVoices,
                );

                // Append voice-leading trace to decision steps (works for both
                // figured and unfigured notes: figured notes get only VL steps,
                // unfigured notes get the figure-decision steps + VL steps).
                $vlTrace = $this->voiceLeading->traceVoiceLeading($chord, $prevChord, $keyFifths, $keyMode);
                $chord->decisionTrace['steps'] = array_merge($decisionSteps, $vlTrace);

                $measure->realizedChords[$i] = $chord;

                $prevNote   = $bassNote;
                $prevChord  = $chord;
                $bassOffset += $bassNote->duration;
                $noteIndex++;
            }
        }

        return $score;
    }

    /**
     * Find the melody pitch class (0–11) sounding at $beatOffset quarters into
     * the measure.  Returns null when no melody note covers that position.
     *
     * @param array<array{offset:float,duration:float,pc:int}> $melodyNotes
     */
    private function findMelodyPc(array $melodyNotes, float $beatOffset): ?int
    {
        foreach ($melodyNotes as $mn) {
            if ($mn['offset'] <= $beatOffset + 0.001
                && $beatOffset  <  $mn['offset'] + $mn['duration'] - 0.001) {
                return $mn['pc'];
            }
        }
        return null;
    }

    /**
     * Flatten all bass notes from all measures into a single array for lookahead.
     */
    private function collectAllBassNotes(Score $score): array
    {
        $all = [];
        foreach ($score->measures as $measure) {
            foreach ($measure->bassNotes as $note) {
                $all[] = $note;
            }
        }
        return $all;
    }

    /**
     * Generate a Roman numeral chord symbol for display purposes.
     */
    private function chordSymbol(int $scaleDeg, array $figures, string $mode): string
    {
        $isMajor = strtolower($mode) === 'major';
        $nums    = array_column($figures, 'number');

        // Major/minor quality by scale degree
        $majorQuality  = ['I', 'ii', 'iii', 'IV', 'V', 'vi', 'vii°'];
        $minorQuality  = ['i', 'ii°', 'III', 'iv', 'V', 'VI', 'vii°'];

        $symbols = $isMajor ? $majorQuality : $minorQuality;
        $base    = $symbols[$scaleDeg - 1] ?? '?';

        // Inversion suffix — check most-specific cases first so that fully-spelled chords
        // (e.g. [6,4,2] or [6,5,3]) are not mistaken for less-specific patterns.
        if (in_array(2, $nums) && !in_array(9, $nums)) {  // 4/2 or 6/4/2 or 2 alone
            return $base . '²';
        }
        if (in_array(4, $nums) && in_array(3, $nums)) {   // 4/3 or 6/4/3
            return $base . '⁴₃';
        }
        if (in_array(6, $nums) && in_array(5, $nums)) {   // 6/5 or 6/5/3
            return $base . '⁶₅';
        }
        if (in_array(7, $nums)) {                          // 7, 7/5, 7/5/3
            return $base . '⁷';
        }
        if (in_array(6, $nums) && in_array(4, $nums)) {   // 6/4 (cadential)
            return $base . '⁶₄';
        }
        if (in_array(6, $nums)) {                          // 6 alone → first inversion triad
            return $base . '⁶';
        }

        return $base;
    }
}
