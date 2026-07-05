<?php

namespace App\Service;

use App\Model\Measure;

/**
 * Turns a figured bass line into a phrase-level Roman-numeral progression.
 *
 * This is deliberately baroque-aware rather than a generic chord-guesser: the
 * chord identity comes from the composer's own figures (the numbers a continuo
 * player reads), not from inferring harmony out of a pitch soup. The bass note
 * fixes the sounding chord member, the figures fix the inversion and any
 * chromatic alteration, and the local key fixes the diatonic quality.
 *
 *   figure set              bass is    →  inversion
 *   ─────────────────────────────────────────────────────────
 *   (none) / 5 3            root          root position triad
 *   6 / 6 3                 3rd           first inversion   (⁶)
 *   6 4                     5th           second inversion  (⁶₄)
 *   7 / 7 5 3               root          root position 7th (⁷)
 *   6 5                     3rd           first inversion 7th  (⁶₅)
 *   4 3                     5th           second inversion 7th (⁴₃)
 *   4 2 / 2                 7th           third inversion 7th  (⁴₂)
 *
 * A conservative second pass relabels chromatically-raised major chords that
 * fall a fifth to the next root as applied dominants (V/x) — the standard
 * baroque tonicisation — without disturbing diatonic V–I motion.
 */
class RomanNumeralAnalyzer
{
    public function __construct(private readonly HarmonyAnalyzer $analyzer) {}

    private const DEGREE_ROMAN = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII'];

    /** Diatonic triad qualities per scale degree. Minor uses the continuo norm (major V, diminished vii°). */
    private const QUALITY_MAJOR = [1 => 'maj', 2 => 'min', 3 => 'min', 4 => 'maj', 5 => 'maj', 6 => 'min', 7 => 'dim'];
    private const QUALITY_MINOR = [1 => 'min', 2 => 'dim', 3 => 'maj', 4 => 'min', 5 => 'maj', 6 => 'maj', 7 => 'dim'];

    private const SUP = ['0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹'];
    private const SUB = ['0' => '₀', '1' => '₁', '2' => '₂', '3' => '₃', '4' => '₄', '5' => '₅', '6' => '₆', '7' => '₇', '8' => '₈', '9' => '₉'];

    /**
     * Analyse a run of measures under one local key.
     *
     * Only *structural* bass notes are read as chords: those carrying a figure,
     * falling on a beat, or long enough to be agogically weighted. Short,
     * off-beat, unfigured notes are treated as melodic (passing / neighbour)
     * and skipped, so a running continuo bass yields a harmonic reduction
     * rather than a chord on every quaver.
     *
     * @param Measure[] $measures
     *
     * @return list<array{measure:int, roman:string, degree:int, quality:string, seventh:bool, applied:bool}>
     */
    public function analyze(array $measures, int $keyFifths, string $keyMode, int $divisions = 1): array
    {
        $scale     = PitchHelper::buildScale($keyFifths, $keyMode);
        $divisions = max(1, $divisions);

        // First pass: one chord reading per structural bass note.
        $chords = [];
        foreach ($measures as $measure) {
            $offsetQn = 0.0;
            foreach ($measure->bassNotes as $note) {
                $durationQn = $note->duration / $divisions;
                if (!$note->isRest() && $this->isStructural($note, $offsetQn, $durationQn)) {
                    $chords[] = $this->readChord($note, $measure->number, $keyFifths, $keyMode, $scale);
                }
                $offsetQn += $durationQn;
            }
        }

        // Second pass: tonicisations (applied dominants).
        $this->markAppliedDominants($chords, $scale);

        // Render + collapse consecutive repeats so the progression stays readable.
        $progression = [];
        $lastRoman   = null;
        foreach ($chords as $c) {
            $roman = $this->render($c);
            if ($roman === $lastRoman) {
                continue;
            }
            $progression[] = [
                'measure'    => $c['measure'],
                'roman'      => $roman,
                'degree'     => $c['rootDeg'],
                'quality'    => $c['quality'],
                'seventh'    => $c['seventh'],
                'applied'    => $c['applied'] !== null,
                'suspension' => $c['suspension'],
            ];
            $lastRoman = $roman;
        }

        return $progression;
    }

    /**
     * A bass note anchors a chord when it carries a figure, lands on a beat
     * (integer quarter-note offset), or is at least a quarter long. Everything
     * else is treated as a melodic passing/neighbour tone.
     */
    private function isStructural($note, float $offsetQn, float $durationQn): bool
    {
        if (!empty($note->figuredBass)) {
            return true;
        }
        if ($durationQn >= 0.99) {   // quarter note or longer (agogic weight)
            return true;
        }
        // On a beat: fractional part of the quarter-note offset is ~0.
        return abs($offsetQn - round($offsetQn)) < 0.05;
    }

    /**
     * Resolve one bass note + its figures into a chord reading (root scale
     * degree, quality, inversion, chromatic third flag).
     *
     * @param int[] $scale
     *
     * @return array{measure:int, bassDeg:int, rootDeg:int, rootPc:int, quality:string,
     *               seventh:bool, inversion:int, raisedThird:bool, applied:?int, suspension:?string}
     */
    private function readChord($note, int $measure, int $keyFifths, string $keyMode, array $scale): array
    {
        $bassDeg = $this->analyzer->scaleDegree($note, $keyFifths, $keyMode);
        $nums    = array_map(static fn(array $f): int => (int) $f['number'], $note->figuredBass);

        // Which chord member is the bass, is this a seventh chord, and is a
        // suspension figured over it? The chord identity is the RESOLUTION chord.
        [$bassMember, $seventh, $inversion, $suspension] = $this->classifyInversion($nums);

        // Root degree = shift the bass degree down by however far the bass sits above the root.
        $offset  = ['root' => 0, 'third' => 2, 'fifth' => 4, 'seventh' => 6][$bassMember];
        $rootDeg = (($bassDeg - $offset - 1) % 7 + 7) % 7 + 1;

        $quality     = $this->diatonicQuality($rootDeg, $keyMode);
        $raisedThird = $bassMember === 'root' && $this->figuresRaiseThird($note->figuredBass);
        if ($raisedThird) {
            $quality = 'maj';   // a chromatically raised third makes the triad major
        }

        return [
            'measure'     => $measure,
            'bassDeg'     => $bassDeg,
            'rootDeg'     => $rootDeg,
            'rootPc'      => $scale[$rootDeg - 1],
            'quality'     => $quality,
            'seventh'     => $seventh,
            'inversion'   => $inversion,
            'raisedThird' => $raisedThird,
            'applied'     => null,   // filled in by markAppliedDominants()
            'suspension'  => $suspension,
        ];
    }

    /**
     * Classify a figure stack into [bassMember, isSeventh, inversion, suspension].
     *
     * Suspensions are matched first and read as non-chord tones: the chord
     * identity is its resolution (a 4–3 or 9–8 leaves a root triad, a 7–6 leaves
     * a first-inversion ⁶ chord) with the suspension carried alongside. The
     * petite sixte "6 4 3" and "6 5" seventh chord fall through to the genuine
     * chord cases.
     *
     * @param int[] $nums figure numbers present on the bass note
     *
     * @return array{0:string,1:bool,2:int,3:?string} [bassMember, isSeventh, inversion, suspension]
     */
    private function classifyInversion(array $nums): array
    {
        $has = static fn(int $n): bool => in_array($n, $nums, true);

        // Suspensions (non-chord tones) — resolution defines the chord.
        if ($has(4) && $has(3) && !$has(6)) {
            return ['root', false, 0, '4–3'];
        }
        if ($has(7) && $has(6) && !$has(5)) {
            return ['third', false, 1, '7–6'];
        }
        if ($has(9) && !$has(7)) {
            return ['root', false, 0, '9–8'];
        }

        [$member, $seventh, $inversion] = match (true) {
            $has(2)                       => ['seventh', true, 3],   // 4/2, 2
            $has(6) && $has(5)            => ['third',   true, 1],   // 6/5
            $has(4) && $has(3)            => ['fifth',   true, 2],   // 6/4/3 petite sixte
            $has(6) && $has(4)            => ['fifth',   false, 2],  // 6/4 triad
            $has(6)                       => ['third',   false, 1],  // 6, 6/3
            $has(7)                       => ['root',    true, 0],   // 7
            default                       => ['root',    false, 0],  // 5/3, empty
        };

        return [$member, $seventh, $inversion, null];
    }

    private function diatonicQuality(int $degree, string $keyMode): string
    {
        $table = strtolower($keyMode) === 'minor' ? self::QUALITY_MINOR : self::QUALITY_MAJOR;

        return $table[$degree] ?? 'maj';
    }

    /** True when the figures carry a chromatically raised third (a standalone sharp, or 3 with alter > 0). */
    private function figuresRaiseThird(array $figures): bool
    {
        foreach ($figures as $f) {
            if ((int) $f['number'] === 3 && (int) ($f['alter'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Relabel chromatically-raised major chords that fall a fifth to the next
     * root as applied dominants. Conservative on purpose: a plain diatonic major
     * triad (I, IV, V) is never touched — only a chord whose third has been
     * explicitly raised can tonicise.
     *
     * @param array<int,array> $chords by reference
     * @param int[]            $scale
     */
    private function markAppliedDominants(array &$chords, array $scale): void
    {
        for ($i = 0; $i < count($chords) - 1; $i++) {
            $c = $chords[$i];
            $next = $chords[$i + 1];

            if (!$c['raisedThird'] || $c['quality'] !== 'maj' || $c['rootDeg'] === 5) {
                continue;
            }

            // Root of this chord a perfect fifth above the next chord's root?
            if ((($c['rootPc'] - $next['rootPc']) % 12 + 12) % 12 === 7) {
                $chords[$i]['applied'] = $next['rootDeg'];
            }
        }
    }

    /**
     * Render a chord reading as a Roman numeral with stacked inversion figures.
     */
    private function render(array $c): string
    {
        if ($c['applied'] !== null) {
            $target = $this->baseNumeral($c['applied'], $this->diatonicQualityForTarget($c['applied']));
            return 'V' . ($c['seventh'] ? self::SUP['7'] : '') . '/' . $target;
        }

        $base    = $this->baseNumeral($c['rootDeg'], $c['quality']);
        $figures = $this->inversionFigures($c['inversion'], $c['seventh']);

        return $base . $figures . $this->suspensionFigures($c['suspension'] ?? null);
    }

    /** Render a suspension like "4–3" as superscript "⁴⁻³". */
    private function suspensionFigures(?string $suspension): string
    {
        if ($suspension === null) {
            return '';
        }
        [$dissonance, $resolution] = explode('–', $suspension);

        return ' ' . (self::SUP[$dissonance] ?? $dissonance)
            . "\u{207B}" . (self::SUP[$resolution] ?? $resolution);
    }

    /** Diatonic case for an applied-dominant's target (V/ii shows a lowercase target). */
    private function diatonicQualityForTarget(int $degree): string
    {
        // The target keeps its own diatonic colour in the home key's major mode context.
        return self::QUALITY_MAJOR[$degree] ?? 'maj';
    }

    private function baseNumeral(int $degree, string $quality): string
    {
        $roman = self::DEGREE_ROMAN[$degree] ?? '?';
        if ($quality === 'min' || $quality === 'dim') {
            $roman = strtolower($roman);
        }
        if ($quality === 'dim') {
            $roman .= '°';
        } elseif ($quality === 'aug') {
            $roman .= '⁺';
        }

        return $roman;
    }

    private function inversionFigures(int $inversion, bool $seventh): string
    {
        if ($seventh) {
            return match ($inversion) {
                0 => self::SUP['7'],
                1 => self::SUP['6'] . self::SUB['5'],
                2 => self::SUP['4'] . self::SUB['3'],
                3 => self::SUP['4'] . self::SUB['2'],
                default => '',
            };
        }

        return match ($inversion) {
            1 => self::SUP['6'],
            2 => self::SUP['6'] . self::SUB['4'],
            default => '',
        };
    }
}
