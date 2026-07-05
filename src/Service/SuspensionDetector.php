<?php

namespace App\Service;

use App\Model\Measure;

/**
 * Detects suspensions (and the related retardation/bass-suspension figures) from
 * the figured bass — a defining baroque dissonance where a voice is held over a
 * chord change and then resolves down by step.
 *
 * Suspensions are read straight from the composer's figures, which encode them
 * as a dissonant interval above the bass resolving to its consonance:
 *
 *   4–3   the classic cadential suspension over a held/root bass
 *   7–6   over a bass (often a chain in a descending-step sequence)
 *   9–8   the ninth resolving to the octave
 *   2–3   a bass suspension (the bass itself is the suspended dissonance)
 *
 * Two encodings are recognised: the pair stacked on ONE bass note (compact
 * notation, e.g. "4 3"), and the pair spread across two events on a HELD bass
 * (the dissonance figure, then the resolution figure). The petite-sixte "6 4 3"
 * and the "6 5" seventh chord are deliberately NOT read as suspensions — they
 * are genuine chords.
 *
 * Most suspensions in a solo sonata are carried by the SOLOIST rather than the
 * continuo figures, so a second, register-aware pass reads the melody line(s):
 * a melody note held while the bass changes underneath it, prepared as a
 * consonance, sounding a dissonance against the new bass, and resolving down by
 * step. This needs the octave/MIDI now stored on melodyNotes.
 */
class SuspensionDetector
{
    /** Dissonant interval → the consonance it resolves down to. */
    private const PAIRS = [4 => 3, 7 => 6, 9 => 8, 2 => 3];

    /** Consonant intervals above the bass (mod octave) — used for the preparation test. */
    private const CONSONANCES = [0, 3, 4, 7, 8, 9];

    /**
     * @param Measure[] $measures
     *
     * @return list<array{measure:int, type:string, held:bool, source:string}>
     */
    public function detect(array $measures, int $divisions = 1): array
    {
        $events = $this->flattenBass($measures);
        $found  = [];   // dedupe key "measure:type" → entry

        foreach ($events as $idx => $event) {
            $nums = $event['nums'];

            // Case A — the pair stacked on a single bass note.
            foreach (self::PAIRS as $dissonance => $resolution) {
                if (!in_array($dissonance, $nums, true) || !in_array($resolution, $nums, true)) {
                    continue;
                }
                if ($dissonance === 4 && in_array(6, $nums, true)) {
                    continue;   // 6 4 3 is the petite sixte, not a 4–3 suspension
                }
                if ($dissonance === 7 && in_array(5, $nums, true)) {
                    continue;   // 7 5 is a seventh chord, not a 7–6 suspension
                }
                $this->add($found, $event['measure'], $dissonance . '–' . $resolution, false, 'figures');
            }

            // Case B — dissonance then resolution across a HELD (repeated) bass.
            $prev = $events[$idx - 1] ?? null;
            if ($prev !== null && $prev['midi'] === $event['midi']) {
                foreach (self::PAIRS as $dissonance => $resolution) {
                    $prepared = in_array($dissonance, $prev['nums'], true)
                        && !in_array($resolution, $prev['nums'], true);
                    $resolves = in_array($resolution, $nums, true)
                        && !in_array($dissonance, $nums, true);
                    if ($prepared && $resolves) {
                        $this->add($found, $event['measure'], $dissonance . '–' . $resolution, true, 'figures');
                    }
                }
            }
        }

        $this->detectMelodic($measures, max(1, $divisions), $found);

        $result = array_values($found);
        usort($result, static fn(array $a, array $b): int => $a['measure'] <=> $b['measure']);

        return $result;
    }

    /**
     * Melodic suspensions: a held soloist note over a bass change, prepared as a
     * consonance, dissonant against the new bass, resolving down a step.
     *
     * @param Measure[]           $measures
     * @param array<string,array> $found     by reference
     */
    private function detectMelodic(array $measures, int $divisions, array &$found): void
    {
        [$bass, $melody] = $this->buildTimelines($measures, $divisions);
        if (count($bass) < 2 || count($melody) < 2) {
            return;
        }

        for ($i = 0; $i < count($melody) - 1; $i++) {
            $held    = $melody[$i];
            $resolve = $melody[$i + 1];

            // Resolution must directly follow and fall a step (1–2 semitones).
            $stepDown = $held['midi'] - $resolve['midi'];
            if ($stepDown < 1 || $stepDown > 2 || abs($resolve['start'] - $held['end']) > 1e-6) {
                continue;
            }

            $prepBass = $this->bassActiveAt($bass, $held['start']);
            $suspBass = $this->bassActiveAt($bass, $held['end'] - 1e-6);
            if ($prepBass === null || $suspBass === null || $prepBass['time'] === $suspBass['time']) {
                continue;   // the bass has to move under the held note
            }

            // Prepared as a consonance, dissonant against the new bass.
            $prepInt = (($held['midi'] - $prepBass['midi']) % 12 + 12) % 12;
            $suspInt = (($held['midi'] - $suspBass['midi']) % 12 + 12) % 12;
            if (!in_array($prepInt, self::CONSONANCES, true)) {
                continue;
            }
            $type = $this->classifyMelodicInterval($suspInt);
            if ($type === null) {
                continue;
            }

            $this->add($found, $suspBass['measure'], $type, true, 'melody');
        }
    }

    /** Map a dissonant interval above the bass (mod octave) to a suspension label. */
    private function classifyMelodicInterval(int $interval): ?string
    {
        return match ($interval) {
            5           => '4–3',   // perfect fourth
            10, 11      => '7–6',   // minor / major seventh
            1, 2        => '9–8',   // a step above the bass in register = a ninth
            default     => null,    // tritone / consonances → not a plain suspension
        };
    }

    /** The last bass onset sounding at or before time $t. */
    private function bassActiveAt(array $bass, float $t): ?array
    {
        $active = null;
        foreach ($bass as $b) {
            if ($b['time'] <= $t + 1e-6) {
                $active = $b;
            } else {
                break;
            }
        }

        return $active;
    }

    /**
     * Flatten bass onsets and melody notes onto one quarter-note timeline across
     * the given measures (measure length = sum of its bass note durations).
     *
     * @param Measure[] $measures
     *
     * @return array{0:list<array{time:float,midi:int,measure:int}>, 1:list<array{start:float,end:float,midi:int}>}
     */
    private function buildTimelines(array $measures, int $divisions): array
    {
        $bass   = [];
        $melody = [];
        $measureStart = 0.0;

        foreach ($measures as $measure) {
            $length = 0.0;
            $offset = 0.0;
            foreach ($measure->bassNotes as $note) {
                $durQn = $note->duration / $divisions;
                if (!$note->isRest()) {
                    $bass[] = ['time' => $measureStart + $offset, 'midi' => $note->midiPitch(), 'measure' => $measure->number];
                }
                $offset += $durQn;
                $length += $durQn;
            }

            foreach ($measure->melodyNotes as $mn) {
                $start = $measureStart + ($mn['offset'] ?? 0.0);
                $melody[] = [
                    'start' => $start,
                    'end'   => $start + ($mn['duration'] ?? 0.0),
                    'midi'  => $mn['midi'] ?? ($mn['note']?->midiPitch() ?? 0),
                ];
            }

            $measureStart += $length > 0 ? $length : 4.0;
        }

        usort($bass,   static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
        usort($melody, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        return [$bass, $melody];
    }

    /** @param array<string,array> $found */
    private function add(array &$found, int $measure, string $type, bool $held, string $source): void
    {
        $key = $measure . ':' . $type;
        if (!isset($found[$key])) {
            $found[$key] = ['measure' => $measure, 'type' => $type, 'held' => $held, 'source' => $source];
        }
    }

    /**
     * @param Measure[] $measures
     *
     * @return list<array{measure:int, midi:int, nums:int[]}>
     */
    private function flattenBass(array $measures): array
    {
        $events = [];
        foreach ($measures as $measure) {
            foreach ($measure->bassNotes as $note) {
                if ($note->isRest()) {
                    continue;
                }
                $events[] = [
                    'measure' => $measure->number,
                    'midi'    => $note->midiPitch(),
                    'nums'    => array_map(static fn(array $f): int => (int) $f['number'], $note->figuredBass),
                ];
            }
        }

        return $events;
    }
}
