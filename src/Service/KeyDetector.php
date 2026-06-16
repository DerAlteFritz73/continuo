<?php

namespace App\Service;

use App\Model\Note;

class KeyDetector
{
    /**
     * Detect the key of a passage based on bass notes and accidentals.
     * Returns array with detected key, confidence, and signal details.
     */
    public function detectKey(array $bassNotes): array
    {
        if (empty($bassNotes)) {
            return ['key' => ['fifths' => 0, 'mode' => 'major'], 'confidence' => 'low', 'reason' => 'no_notes'];
        }

        $finalNote = $this->getFinalNote($bassNotes);
        $accidentalSignal = $this->analyzeAccidentals($bassNotes);
        $cadenceSignal = $this->analyzeCadence($bassNotes);
        $histogramSignal = $this->analyzeHistogram($bassNotes);

        $signals = [
            'final_note' => $finalNote,
            'accidentals' => $accidentalSignal,
            'cadence' => $cadenceSignal,
            'histogram' => $histogramSignal,
        ];

        $detectedKey = $this->resolveKey($signals);
        $confidence = $this->scoreConfidence($signals, $detectedKey);

        return [
            'key' => $detectedKey,
            'confidence' => $confidence,
            'signals' => $signals,
        ];
    }

    private function getFinalNote(array $bassNotes): ?array
    {
        for ($i = count($bassNotes) - 1; $i >= 0; $i--) {
            if (!$bassNotes[$i]->isRest()) {
                return [
                    'step' => $bassNotes[$i]->step,
                    'alter' => $bassNotes[$i]->alter,
                ];
            }
        }
        return null;
    }

    private function analyzeAccidentals(array $bassNotes): ?array
    {
        $accidentals = [];
        foreach ($bassNotes as $note) {
            if (!$note->isRest() && $note->alter !== 0) {
                $key = $note->step . $note->alter;
                $accidentals[$key] = ($accidentals[$key] ?? 0) + 1;
            }
        }

        if (empty($accidentals)) {
            return ['type' => 'none', 'suggested_keys' => []];
        }

        // Leading tones suggest the key (e.g., B# or C# suggests D major)
        $leadingTones = [
            'B1' => 'D', 'C1' => 'D', 'F1' => 'G', 'G1' => 'A',
            'A1' => 'B', 'E1' => 'F', 'D1' => 'E',
            'E-1' => 'F', 'A-1' => 'B', 'B-1' => 'C', 'D-1' => 'E',
        ];

        $suggestedKeys = [];
        foreach ($accidentals as $note => $count) {
            if ($count >= 2 && isset($leadingTones[$note])) {
                $root = $leadingTones[$note];
                $key = $root . ' major';
                $suggestedKeys[$key] = ($suggestedKeys[$key] ?? 0) + $count;
            }
        }

        arsort($suggestedKeys);

        return [
            'type' => 'present',
            'accidentals' => $accidentals,
            'suggested_keys' => array_slice($suggestedKeys, 0, 3, true),
        ];
    }

    private function analyzeCadence(array $bassNotes): array
    {
        if (count($bassNotes) < 2) {
            return ['type' => 'insufficient'];
        }

        $lastTwo = [];
        $count = 0;
        for ($i = count($bassNotes) - 1; $i >= 0 && $count < 2; $i--) {
            if (!$bassNotes[$i]->isRest()) {
                array_unshift($lastTwo, $bassNotes[$i]);
                $count++;
            }
        }

        if (count($lastTwo) < 2) {
            return ['type' => 'insufficient'];
        }

        $note1 = $lastTwo[0];
        $note2 = $lastTwo[1];

        $interval = $note2->midiPitch() - $note1->midiPitch();
        $interval = $interval % 12; // normalize to octave

        $isLeap = abs($interval) >= 5; // 4th or larger
        $isFifthLeap = in_array(abs($interval), [5, 7]); // 4th or 5th

        if ($isFifthLeap) {
            return [
                'type' => 'strong_leap',
                'from' => $note1->step . ($note1->alter !== 0 ? ($note1->alter > 0 ? '#' : 'b') : ''),
                'to' => $note2->step . ($note2->alter !== 0 ? ($note2->alter > 0 ? '#' : 'b') : ''),
                'interval' => $interval,
            ];
        }

        return ['type' => 'weak_or_step'];
    }

    private function analyzeHistogram(array $bassNotes): array
    {
        $histogram = [];
        foreach ($bassNotes as $note) {
            if (!$note->isRest()) {
                $pitchClass = $note->step . ($note->alter !== 0 ? ($note->alter > 0 ? '+' : '-') : '');
                $histogram[$pitchClass] = ($histogram[$pitchClass] ?? 0) + $note->duration;
            }
        }

        arsort($histogram);

        return [
            'distribution' => array_slice($histogram, 0, 5, true),
            'most_common' => array_key_first($histogram),
        ];
    }

    private function resolveKey(array $signals): array
    {
        $candidates = [];

        // Strong signal: final note
        if ($signals['final_note']) {
            $step = $signals['final_note']['step'];
            $fifths = $this->stepToFifths($step);
            $candidates[$step . '_major'] = ['step' => $step, 'fifths' => $fifths, 'mode' => 'major', 'weight' => 3];
            $candidates[$step . '_minor'] = ['step' => $step, 'fifths' => $fifths, 'mode' => 'minor', 'weight' => 3];
        }

        // Accidental signal
        if ($signals['accidentals']['type'] === 'present' && !empty($signals['accidentals']['suggested_keys'])) {
            foreach ($signals['accidentals']['suggested_keys'] as $keyName => $count) {
                [$root, $mode] = explode(' ', $keyName);
                $fifths = $this->stepToFifths($root);
                $candidates[$keyName] = [
                    'step' => $root,
                    'fifths' => $fifths,
                    'mode' => $mode,
                    'weight' => ($candidates[$keyName]['weight'] ?? 0) + 2,
                ];
            }
        }

        // Cadence signal
        if ($signals['cadence']['type'] === 'strong_leap') {
            $to = $signals['cadence']['to'];
            $step = rtrim($to, '#b');
            $fifths = $this->stepToFifths($step);
            $candidates[$step . '_major'] = [
                'step' => $step,
                'fifths' => $fifths,
                'mode' => 'major',
                'weight' => ($candidates[$step . '_major']['weight'] ?? 0) + 2,
            ];
        }

        if (empty($candidates)) {
            return ['fifths' => 0, 'mode' => 'major'];
        }

        // Pick the candidate with highest weight
        usort($candidates, fn($a, $b) => $b['weight'] <=> $a['weight']);
        $best = array_shift($candidates);

        return ['fifths' => $best['fifths'], 'mode' => $best['mode']];
    }

    private function stepToFifths(string $step): int
    {
        $fifthsMap = [
            'C' => 0, 'G' => 1, 'D' => 2, 'A' => 3, 'E' => 4, 'B' => 5, 'F#' => 6, 'C#' => 7,
            'F' => -1, 'Bb' => -2, 'Eb' => -3, 'Ab' => -4, 'Db' => -5, 'Gb' => -6, 'Cb' => -7,
        ];
        return $fifthsMap[$step] ?? 0;
    }

    private function scoreConfidence(array $signals, array $detectedKey): string
    {
        $agreingSignals = 0;

        if ($signals['final_note']) {
            $agreingSignals++;
        }

        if ($signals['accidentals']['type'] === 'present' && !empty($signals['accidentals']['suggested_keys'])) {
            $agreingSignals++;
        }

        if ($signals['cadence']['type'] === 'strong_leap') {
            $agreingSignals++;
        }

        if ($agreingSignals >= 3) {
            return 'high';
        } elseif ($agreingSignals >= 2) {
            return 'medium';
        }

        return 'low';
    }
}
