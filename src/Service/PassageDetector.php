<?php

namespace App\Service;

use App\Model\Score;

class PassageDetector
{
    public function __construct(private readonly KeyDetector $keyDetector) {}

    /**
     * Detect passages in a score and their keys.
     * Returns array of passages with: {start_measure, end_measure, key, confidence}
     */
    public function detectPassages(Score $score): array
    {
        if (empty($score->measures)) {
            return [];
        }

        $passages = [];
        $currentPassageStart = $score->measures[0]->number;
        $currentBassNotes = [];
        $prevKeySignature = null;

        foreach ($score->measures as $i => $measure) {
            // Check if this measure has an explicit key change (different from previous)
            $hasKeyChange = $i > 0 && isset($measure->keySignature) && $measure->keySignature !== $prevKeySignature;

            $currentBassNotes = array_merge($currentBassNotes, $measure->bassNotes);

            // Split on explicit key change
            if ($hasKeyChange && !empty($currentBassNotes)) {
                $passages[] = $this->createPassage($currentPassageStart, $measure->number - 1, $currentBassNotes);
                $currentPassageStart = $measure->number;
                $currentBassNotes = [];
            }
            // Or split on cadential point (long note)
            elseif ($this->isCadentialPoint($measure) && !empty($currentBassNotes)) {
                $passages[] = $this->createPassage($currentPassageStart, $measure->number, $currentBassNotes);
                $currentPassageStart = $i + 1 < count($score->measures) ? $score->measures[$i + 1]->number : null;
                $currentBassNotes = [];
            }

            $prevKeySignature = $measure->keySignature;
        }

        // Final passage
        if (!empty($currentBassNotes) && $currentPassageStart !== null) {
            $lastMeasure = end($score->measures);
            $passages[] = $this->createPassage($currentPassageStart, $lastMeasure->number, $currentBassNotes);
        }

        // Ensure minimum passage length (at least 2 measures)
        return $this->validatePassages($passages);
    }

    private function isCadentialPoint($measure): bool
    {
        if (empty($measure->bassNotes)) {
            return false;
        }

        // Long note (half, whole, dotted) at end of measure
        $lastNote = end($measure->bassNotes);
        if ($lastNote->duration >= 3.5) { // 3.5 ≈ dotted half
            return true;
        }

        return false;
    }

    private function shouldSplitHere(int $measureNum, int $totalMeasures): bool
    {
        // Default: split every 4 measures if not enough cadential points
        return $measureNum > 1 && ($measureNum - 1) % 4 === 0;
    }

    private function createPassage(int $startMeasure, int $endMeasure, array $bassNotes): array
    {
        $keyDetection = $this->keyDetector->detectKey($bassNotes);

        return [
            'start_measure' => $startMeasure,
            'end_measure' => $endMeasure,
            'key' => $keyDetection['key'],
            'confidence' => $keyDetection['confidence'],
            'signals' => $keyDetection['signals'],
        ];
    }

    private function validatePassages(array $passages): array
    {
        // Merge passages that are too short
        $validated = [];
        foreach ($passages as $passage) {
            $length = $passage['end_measure'] - $passage['start_measure'] + 1;
            if ($length < 2) {
                // Merge with previous or next
                if (!empty($validated)) {
                    $prev = array_pop($validated);
                    $prev['end_measure'] = $passage['end_measure'];
                    $prev['key'] = $passage['key'];
                    $prev['confidence'] = $passage['confidence'];
                    $validated[] = $prev;
                } else {
                    $validated[] = $passage;
                }
            } else {
                $validated[] = $passage;
            }
        }

        return $validated;
    }
}
