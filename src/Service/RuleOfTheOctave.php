<?php

namespace App\Service;

/**
 * The Rule of the Octave (Oktavregel) and the stable–unstable–stable logic of
 * sound progression — the "p–i–p principle" — after Markus Jans, *Basstöne und
 * ihre Bedeutungsmöglichkeiten im Kontext* (GMTH Proceedings 2002).
 *
 * The historical logic of chord connection (pre-Rameau, pre-functional) rests on
 * two primary sonority categories:
 *   - the root-position triad (5/3, "Terzquintakkord") — STABLE (perfect
 *     consonance): apt as a point of departure, an intermediate goal, or a close;
 *   - the sixth chord (6/3, "Sextakkord") — MOBILE (imperfect consonance): the
 *     carrier of motion.
 * Dissonances (6/5, 4/3, 4/2, 6/4) sharpen a chord's tendency and its resolution
 * — they are the strongest movement chords. A sound progression runs
 * stable → (one or more unstable) → stable ("p–i(–ii…)–p").
 *
 * The Rule of the Octave fixes, for each scale degree of a stepwise bass, the
 * idiomatic chord: 5/3 on the stable degrees (1, 5, 8) that frame or divide the
 * octave, sixth chords on the mobile degrees (2, 3, 6, 7), and dissonances on 4
 * and on the leading tone whose direction they clarify. One common form
 * (Campion / Fenaroli) is encoded here; major and minor share the figure shapes
 * (only the accidentals differ, which does not affect the sonority category).
 */
class RuleOfTheOctave
{
    public const STABLE    = 'stable';
    public const MOBILE    = 'mobile';
    public const DISSONANT = 'dissonant';

    /** Idiomatic chord shape per scale degree, bass ascending. */
    private const ASCENDING  = [1 => '53', 2 => '6', 3 => '6', 4 => '65', 5 => '53', 6 => '6', 7 => '6'];
    /** Idiomatic chord shape per scale degree, bass descending. */
    private const DESCENDING = [1 => '53', 2 => '6', 3 => '6', 4 => '42', 5 => '53', 6 => '6', 7 => '43'];

    private const SHAPE_FIGURES = ['53' => '5/3', '6' => '6', '65' => '6/5', '43' => '4/3', '42' => '4/2', '64' => '6/4'];

    /**
     * The Rule-of-the-Octave chord expected on a scale degree for a bass moving
     * in the given direction ('ascending' | 'descending').
     *
     * @return array{shape:string, figures:string, stability:string}
     */
    public function expected(int $degree, string $direction): array
    {
        $degree = (($degree - 1) % 7 + 7) % 7 + 1;
        $table  = $direction === 'descending' ? self::DESCENDING : self::ASCENDING;
        $shape  = $table[$degree] ?? '53';

        return [
            'shape'     => $shape,
            'figures'   => self::SHAPE_FIGURES[$shape],
            'stability' => $this->shapeStability($shape),
        ];
    }

    /** Stability category of a chord shape. */
    public function shapeStability(string $shape): string
    {
        return match ($shape) {
            '53'    => self::STABLE,
            '6'     => self::MOBILE,
            default => self::DISSONANT,   // 6/5, 4/3, 4/2, 6/4
        };
    }

    /**
     * Stability of an actual chord, read from its figure numbers: a seventh, a
     * second, a 6/4, or a 4/3 makes it dissonant; a bare sixth makes it mobile;
     * a root-position triad (or an unfigured 5/3) is stable.
     *
     * @param int[] $nums figure numbers present on the bass note
     */
    public function stabilityFromFigures(array $nums): string
    {
        $has = static fn(int $n): bool => in_array($n, $nums, true);

        if ($has(7) || $has(2)
            || ($has(6) && $has(5))   // 6/5 — first-inversion seventh
            || ($has(4) && $has(3))   // 4/3 — second-inversion seventh
            || ($has(6) && $has(4))   // 6/4 — dissonant fourth
        ) {
            return self::DISSONANT;
        }
        if ($has(6)) {
            return self::MOBILE;
        }

        return self::STABLE;
    }

    /**
     * Whether an actual chord conforms to the Rule of the Octave for its degree
     * and direction — compared at the level of the sonority category (stable /
     * mobile / dissonant), which is what the historical rule fixes.
     *
     * @param int[] $nums
     */
    public function conforms(int $degree, string $direction, array $nums): bool
    {
        return $this->stabilityFromFigures($nums) === $this->expected($degree, $direction)['stability'];
    }
}
