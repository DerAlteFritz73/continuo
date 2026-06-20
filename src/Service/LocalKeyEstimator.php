<?php

namespace App\Service;

use App\Model\Note;

/**
 * Local key / mode estimation via the Krumhansl–Schmuckler algorithm.
 *
 * A duration-weighted pitch-class histogram is correlated (Pearson) against
 * the 24 Krumhansl–Kessler probe-tone profiles (12 major + 12 minor). The
 * highest correlation wins; the coefficient doubles as a confidence measure,
 * and the gap to the runner-up disambiguates closely-related keys.
 *
 * Unlike the previous bass-only heuristic this is meant to be fed ALL sounding
 * pitches (bass + any upper / melody voices). Considering the full texture is
 * what makes *mode* detection reliable — the bass alone rarely distinguishes a
 * major key from its relative minor.
 */
class LocalKeyEstimator
{
    /** Krumhansl–Kessler major profile, tonic-relative (index 0 = tonic). */
    private const MAJOR_PROFILE = [
        6.35, 2.23, 3.48, 2.33, 4.38, 4.09, 2.52, 5.19, 2.39, 3.66, 2.29, 2.88,
    ];

    /** Krumhansl–Kessler minor profile, tonic-relative. */
    private const MINOR_PROFILE = [
        6.33, 2.68, 3.52, 5.38, 2.60, 3.53, 2.54, 4.75, 3.98, 2.69, 3.34, 3.17,
    ];

    /**
     * Major-key tonic pitch-class → number of fifths (-7..7), using the
     * conventional spelling that stays within the usual circle of fifths.
     */
    private const PC_TO_FIFTHS = [
        0 => 0, 1 => -5, 2 => 2, 3 => -3, 4 => 4, 5 => -1,
        6 => 6, 7 => 1, 8 => -4, 9 => 3, 10 => -2, 11 => 5,
    ];

    /**
     * Estimate the key from a duration-weighted pitch-class histogram.
     *
     * @param float[] $histogram 12 weights indexed by pitch class (C = 0)
     *
     * @return array{fifths:int, mode:string, tonicPc:int, correlation:float,
     *               confidence:string, alternatives:list<array{fifths:int,mode:string,tonicPc:int,correlation:float}>}
     */
    public function estimateFromHistogram(array $histogram): array
    {
        if (array_sum($histogram) <= 0.0) {
            return [
                'fifths'       => 0,
                'mode'         => 'major',
                'tonicPc'      => 0,
                'correlation'  => 0.0,
                'confidence'   => 'low',
                'alternatives' => [],
            ];
        }

        $scored = [];
        for ($tonic = 0; $tonic < 12; $tonic++) {
            $scored[] = $this->scoreCandidate($histogram, $tonic, 'major');
            $scored[] = $this->scoreCandidate($histogram, $tonic, 'minor');
        }

        usort($scored, static fn(array $a, array $b): int => $b['correlation'] <=> $a['correlation']);

        $best                 = $scored[0];
        $best['confidence']   = $this->confidence($best['correlation'], $scored[1]['correlation'] ?? 0.0);
        $best['alternatives'] = array_slice($scored, 1, 3);

        return $best;
    }

    /**
     * Convenience: estimate directly from a list of notes (any voices).
     *
     * @param Note[] $notes
     */
    public function estimateFromNotes(array $notes): array
    {
        return $this->estimateFromHistogram($this->histogramFromNotes($notes));
    }

    /**
     * Build a duration-weighted pitch-class histogram from notes. Rests are
     * skipped; notes with non-positive duration count as a single unit so a
     * mis-parsed duration never silently drops a pitch.
     *
     * @param Note[] $notes
     *
     * @return float[]
     */
    public function histogramFromNotes(array $notes): array
    {
        $hist = array_fill(0, 12, 0.0);
        foreach ($notes as $note) {
            if ($note->isRest()) {
                continue;
            }
            $hist[$note->pitchClass()] += $note->duration > 0 ? $note->duration : 1.0;
        }

        return $hist;
    }

    /**
     * @return array{fifths:int, mode:string, tonicPc:int, correlation:float}
     */
    private function scoreCandidate(array $histogram, int $tonic, string $mode): array
    {
        $profile = $mode === 'minor' ? self::MINOR_PROFILE : self::MAJOR_PROFILE;

        // Rotate the histogram so the candidate tonic sits at index 0.
        $rotated = [];
        for ($i = 0; $i < 12; $i++) {
            $rotated[$i] = $histogram[($i + $tonic) % 12];
        }

        // A minor key shares its signature with the major a minor-third above.
        $relativeMajorPc = $mode === 'minor' ? ($tonic + 3) % 12 : $tonic;

        return [
            'fifths'      => self::PC_TO_FIFTHS[$relativeMajorPc],
            'mode'        => $mode,
            'tonicPc'     => $tonic,
            'correlation' => $this->pearson($rotated, $profile),
        ];
    }

    /**
     * Pearson correlation coefficient between two equal-length vectors.
     *
     * @param float[] $x
     * @param float[] $y
     */
    private function pearson(array $x, array $y): float
    {
        $n  = count($x);
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;

        $num = 0.0;
        $dx  = 0.0;
        $dy  = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a    = $x[$i] - $mx;
            $b    = $y[$i] - $my;
            $num += $a * $b;
            $dx  += $a * $a;
            $dy  += $b * $b;
        }

        $den = sqrt($dx * $dy);

        return $den > 0.0 ? $num / $den : 0.0;
    }

    /**
     * Map the winning correlation (and its margin over the runner-up) onto the
     * coarse low/medium/high scale the UI already understands.
     */
    private function confidence(float $best, float $second): string
    {
        if ($best >= 0.70 && ($best - $second) >= 0.04) {
            return 'high';
        }
        if ($best >= 0.50) {
            return 'medium';
        }

        return 'low';
    }
}
