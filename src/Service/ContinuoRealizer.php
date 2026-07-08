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
        private readonly FigureInference        $figureInference,
    ) {}

    public function realize(Score $score, int $numVoices = 4): Score
    {
        $prevChord    = null;
        $prevNote     = null;
        $allBassNotes = $this->collectAllBassNotes($score);
        $totalNotes   = count($allBassNotes);

        $noteIndex = 0;

        foreach ($score->measures as $measure) {
            // Prefer an explicit source key signature; fall back to the
            // auto-detected local key (display-only, never serialized), then the
            // global key. This lets modulating pieces realize against the right
            // scale without altering the output armature.
            $keyFifths  = $measure->keySignature['fifths'] ?? $measure->detectedKey['fifths'] ?? $score->keyFifths;
            $keyMode    = $measure->keySignature['mode']   ?? $measure->detectedKey['mode']   ?? $score->keyMode;
            $bassOffset = 0.0; // cumulative quarter-note offset within this measure

            // Figure-derived accidentals persist to the barline: an accidental a
            // figure puts on a note letter (e.g. the D♯ of 's'/#3 or of '4+') stays
            // in force for that letter on later, unfigured notes of the SAME
            // measure — exactly as a written accidental does. Keyed by note letter
            // → absolute alteration. Reset each measure. Keeps e.g. a tonicised
            // dominant's D♯ (M5, B major) from slipping back to the diatonic D♮.
            $activeAccidentals = [];

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

                // Get raw figures (from file, from melody, or from decision tree)
                $rawFigures   = $bassNote->figuredBass;
                $decisionSteps = [];
                $figuresSource = 'file';

                if (empty($rawFigures)) {
                    // Try to infer figures from melody notes (if available)
                    $melodyNotesAtBeat = $this->getMelodyNotesAtBeat($measure->melodyNotes, $bassOffset);
                    if (!empty($melodyNotesAtBeat)) {
                        $inferredFigures = $this->figureInference->inferFromMelody($bassNote, $melodyNotesAtBeat, $keyFifths, $keyMode);
                        if (!empty($inferredFigures)) {
                            $rawFigures    = $inferredFigures;
                            $figuresSource = 'melody';
                            $decisionSteps = []; // Melody-inferred figures don't have decision trace
                        }
                    }

                    // If still no figures, run the unfigured bass decision tree
                    if (empty($rawFigures)) {
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
                }

                // Expand figures to interval list
                $intervals = $this->interpreter->expand($rawFigures, $bassNote, $keyFifths, $keyMode);

                // Apply accidental persistence: for each interval that carries NO
                // accidental of its own (alter 0 — whether unfigured, melody-
                // inferred, or a plain figure), if an accidental is already active
                // for that note letter this measure, force it (as an explicit
                // alteration so the voice-leading engine honours it). An interval
                // that specifies its own accidental (alter ≠ 0) is left untouched.
                foreach ($intervals as &$iv) {
                    if ($iv['alter'] !== 0) {
                        continue; // figure specifies its own accidental — self-defining
                    }
                    $note = PitchHelper::diatonicInterval($bassNote, $iv['interval'], $keyFifths, $keyMode);
                    if (array_key_exists($note->step, $activeAccidentals)) {
                        $delta = $activeAccidentals[$note->step] - $note->alter; // delta from diatonic spelling
                        if ($delta !== 0) {
                            $iv['alter']    = $delta;
                            $iv['explicit'] = true;
                        }
                    }
                }
                unset($iv);

                // Record accidentals introduced by this note's figures so they
                // persist to later notes of the measure — absolute alteration per
                // letter. Only genuine accidentals (alter ≠ 0) are recorded.
                foreach ($intervals as $iv) {
                    if ($iv['alter'] === 0) {
                        continue;
                    }
                    $note = PitchHelper::diatonicInterval($bassNote, $iv['interval'], $keyFifths, $keyMode);
                    $activeAccidentals[$note->step] = $note->alter + $iv['alter'];
                }

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

                // Highest melody (solo) pitch sounding across this chord's span — the
                // realization's top voice is kept at or below it (the keyboard right
                // hand stays under the soloist).
                $melodyCeiling = $this->findMelodyCeiling(
                    $measure->melodyNotes, $bassOffset, $bassNote->duration
                );

                // Fast passing note: a 16th (or shorter) that belongs to a run of
                // short notes (a neighbouring bass note is also short). Over such
                // notes the harmony moves too quickly for a full chord, so the
                // texture is thinned by one voice (see VoiceLeadingEngine::realize).
                $shortMax  = 0.26; // quarter-note units; a 16th ≈ 0.25
                $isShort   = $bassNote->duration <= $shortMax;
                $runOfShort = $isShort && (
                    ($prevNote && $prevNote->duration <= $shortMax)
                    || ($nextNote && $nextNote->duration <= $shortMax)
                );

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
                    melodyCeiling: $melodyCeiling,
                    lighten: $runOfShort,
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
     * Highest melody MIDI sounding anywhere within the bass note's span
     * [$beatOffset, $beatOffset + $durQuarters). The fast solo line often climbs
     * above its onset pitch during a single bass note, so the ceiling is the peak
     * over the whole span, not the pitch at the downbeat. Returns null when no
     * melody note overlaps (e.g. the soloist rests) — leaving the top voice free.
     *
     * @param array<array{offset:float,duration:float,note:Note}> $melodyNotes
     */
    private function findMelodyCeiling(array $melodyNotes, float $beatOffset, float $durQuarters): ?int
    {
        $end     = $beatOffset + $durQuarters;
        $ceiling = null;
        foreach ($melodyNotes as $mn) {
            if (!isset($mn['note']) || !$mn['note'] instanceof Note) {
                continue;
            }
            // Overlap of [offset, offset+duration) with [beatOffset, end).
            if ($mn['offset'] < $end - 0.001 && $mn['offset'] + $mn['duration'] > $beatOffset + 0.001) {
                $midi    = $mn['note']->midiPitch();
                $ceiling = $ceiling === null ? $midi : max($ceiling, $midi);
            }
        }
        return $ceiling;
    }

    /**
     * Get all melody Note objects sounding at $beatOffset quarters into the measure.
     * Returns array of Note objects (which may contain multiple notes if there are
     * overlapping or simultaneous melody notes).
     *
     * @param array<array{offset:float,duration:float,note:Note}> $melodyNotes
     * @return Note[]
     */
    private function getMelodyNotesAtBeat(array $melodyNotes, float $beatOffset): array
    {
        $notes = [];
        foreach ($melodyNotes as $mn) {
            if ($mn['offset'] <= $beatOffset + 0.001
                && $beatOffset  <  $mn['offset'] + $mn['duration'] - 0.001) {
                if (isset($mn['note']) && $mn['note'] instanceof Note) {
                    $notes[] = $mn['note'];
                }
            }
        }
        return $notes;
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
