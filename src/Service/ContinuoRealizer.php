<?php

namespace App\Service;

use App\Model\Chord;
use App\Model\Measure;
use App\Model\Note;
use App\Model\Score;

/**
 * Main orchestrator for basso continuo realization.
 *
 * Algorithm (based on Wead & Knopke ICMC 2007 + Gasparini 1708 + Delair 1724):
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

    public function realize(Score $score): Score
    {
        $prevChord    = null;
        $prevNote     = null;
        $allBassNotes = $this->collectAllBassNotes($score);
        $totalNotes   = count($allBassNotes);

        $noteIndex = 0;

        foreach ($score->measures as $measure) {
            $keyFifths = $measure->keySignature['fifths'] ?? $score->keyFifths;
            $keyMode   = $measure->keySignature['mode']   ?? $score->keyMode;

            foreach ($measure->bassNotes as $i => $bassNote) {
                if ($bassNote->isRest()) {
                    $prevNote  = null;
                    $prevChord = null;
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
                $rawFigures = $bassNote->figuredBass;

                if (empty($rawFigures)) {
                    // Unfigured bass → run decision tree
                    $rawFigures = $this->interpreter->unfiguredDecision(
                        scaleDegree: $scaleDeg,
                        motion: $currMotion['type'],
                        nextMotion: $nextMotion['type'],
                        mode: $keyMode,
                        leapSize: $this->analyzer->genericInterval($currMotion['size']),
                    );
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

                // Realize upper voices
                $chord = $this->voiceLeading->realize(
                    chord: $chord,
                    intervals: $intervals,
                    prevChord: $prevChord,
                    keyFifths: $keyFifths,
                    keyMode: $keyMode,
                    isLeadingTone7th: $isLeadingTone,
                );

                $measure->realizedChords[$i] = $chord;

                $prevNote  = $bassNote;
                $prevChord = $chord;
                $noteIndex++;
            }
        }

        return $score;
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

        // Inversion suffix
        if (in_array(6, $nums) && in_array(4, $nums)) {
            return $base . '⁶₄';
        }
        if (in_array(6, $nums) && !in_array(4, $nums) && !in_array(7, $nums)) {
            return $base . '⁶';
        }
        if (in_array(7, $nums)) {
            return $base . '⁷';
        }
        if (in_array(6, $nums) && in_array(5, $nums)) {
            return $base . '⁶₅';
        }
        if (in_array(4, $nums) && in_array(3, $nums)) {
            return $base . '⁴₃';
        }
        if (in_array(4, $nums) && in_array(2, $nums)) {
            return $base . '²';
        }

        return $base;
    }
}
