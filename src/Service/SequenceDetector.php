<?php

namespace App\Service;

use App\Model\Measure;

/**
 * Detects the stock baroque sequential patterns from the bass line.
 *
 * These matter for tonal interpretation: a circle-of-fifths run implies a chain
 * of transient tonicisations rather than a real modulation, and stepwise
 * sequences (parallel ⁶ chords, 7–6 chains) shape where phrases push and rest.
 * Only the bass contour is needed, so this runs directly on the parsed bass.
 *
 * Patterns are found as maximal runs of a consistent bass interval class:
 *   - circle of fifths — descending 5th / ascending 4th roots (−7 or +5 semis)
 *   - descending steps  — falling 2nds  (−1 or −2 semis)
 *   - ascending steps   — rising 2nds   (+1 or +2 semis)
 */
class SequenceDetector
{
    public const CIRCLE_OF_FIFTHS = 'circle_of_fifths';
    public const DESCENDING_STEPS = 'descending_steps';
    public const ASCENDING_STEPS  = 'ascending_steps';

    /** A pattern needs at least this many consecutive matching moves (→ this many + 1 notes). */
    private const MIN_MOVES = 3;

    /**
     * @param Measure[] $measures
     *
     * @return list<array{type:string, start_measure:int, end_measure:int, length:int}>
     */
    public function detect(array $measures): array
    {
        $bass = $this->flattenBass($measures);
        if (count($bass) < self::MIN_MOVES + 1) {
            return [];
        }

        // Directed semitone interval between successive bass notes.
        $moves = [];
        for ($i = 1; $i < count($bass); $i++) {
            $moves[] = [
                'semis'   => $bass[$i]['midi'] - $bass[$i - 1]['midi'],
                'measure' => $bass[$i]['measure'],
                'from'    => $bass[$i - 1]['measure'],
            ];
        }

        $patterns = [];
        foreach ([self::CIRCLE_OF_FIFTHS, self::DESCENDING_STEPS, self::ASCENDING_STEPS] as $type) {
            foreach ($this->findRuns($moves, $type) as $run) {
                $patterns[] = $run;
            }
        }

        // Report earliest first.
        usort($patterns, static fn(array $a, array $b): int => $a['start_measure'] <=> $b['start_measure']);

        return $patterns;
    }

    /**
     * @param list<array{semis:int, measure:int, from:int}> $moves
     *
     * @return list<array{type:string, start_measure:int, end_measure:int, length:int}>
     */
    private function findRuns(array $moves, string $type): array
    {
        $runs   = [];
        $length = 0;
        $start  = null;

        foreach ($moves as $move) {
            if ($this->matches($move['semis'], $type)) {
                if ($length === 0) {
                    $start = $move['from'];
                }
                $length++;
                $end = $move['measure'];
            } else {
                if ($length >= self::MIN_MOVES) {
                    $runs[] = ['type' => $type, 'start_measure' => $start, 'end_measure' => $end, 'length' => $length];
                }
                $length = 0;
            }
        }

        if ($length >= self::MIN_MOVES) {
            $runs[] = ['type' => $type, 'start_measure' => $start, 'end_measure' => $end, 'length' => $length];
        }

        return $runs;
    }

    private function matches(int $semis, string $type): bool
    {
        return match ($type) {
            self::CIRCLE_OF_FIFTHS => $semis === -7 || $semis === 5,   // down P5 / up P4
            self::DESCENDING_STEPS => $semis === -1 || $semis === -2,
            self::ASCENDING_STEPS  => $semis === 1 || $semis === 2,
            default                => false,
        };
    }

    /**
     * @param Measure[] $measures
     *
     * @return list<array{midi:int, measure:int}>
     */
    private function flattenBass(array $measures): array
    {
        $bass = [];
        foreach ($measures as $measure) {
            foreach ($measure->bassNotes as $note) {
                if (!$note->isRest()) {
                    $bass[] = ['midi' => $note->midiPitch(), 'measure' => $measure->number];
                }
            }
        }

        return $bass;
    }
}
