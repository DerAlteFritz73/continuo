<?php

namespace App\Service;

use App\Model\Measure;

/**
 * Reads the Jans / Rule-of-the-Octave "logic of sound progression" from the bass
 * (see {@see RuleOfTheOctave}). For each phrase it reports:
 *
 *   - stability profile — how many structural chords are stable / mobile /
 *     dissonant (the raw material of the "p–i–p" reading);
 *   - p–i–p arcs — motions from a stable chord through one or more mobile /
 *     dissonant chords to the next stable chord (the units of chord movement,
 *     as opposed to Rameau's rigid up–down or functional T–S–D);
 *   - octave-rule spans — stepwise bass runs (Terzgang / Quartgang / Quintgang)
 *     and whether their chords follow the Rule of the Octave;
 *   - 5–6 motions — a fifth giving way to a sixth over a held bass note, which
 *     destabilises it and sets up an ascent (dynamisation);
 *   - ficta — a chromatic bass tone resolving by step, read (after Jans) as an
 *     accidental leading-tone intensification of the motion rather than as a
 *     modulation or secondary dominant.
 *
 * Only structural bass notes (figured, on a beat, or agogically long) are read as
 * chords, so passing quavers do not distort the progression. Unfigured chords
 * take their stability from the Rule of the Octave for their scale degree — the
 * bass note's meaning coming from the key context when nothing else specifies it.
 */
class SoundProgressionDetector
{
    /** Number of chords in a stepwise run → the interval it spans. */
    private const GANG_NAMES = [3 => 'third', 4 => 'fourth', 5 => 'fifth'];

    public function __construct(
        private readonly HarmonyAnalyzer $analyzer,
        private readonly RuleOfTheOctave $octaveRule,
    ) {}

    /**
     * @param Measure[] $measures
     *
     * @return array{stability:array{stable:int,mobile:int,dissonant:int},
     *               pip_arcs:list<array{start_measure:int,end_measure:int,length:int}>,
     *               octave_rule:list<array{type:string,direction:string,start_measure:int,end_measure:int,conforms:bool}>,
     *               five_six:list<array{measure:int}>,
     *               ficta:list<array{measure:int,note:string}>}
     */
    public function detect(array $measures, int $keyFifths, string $keyMode, int $divisions = 1): array
    {
        $divisions = max(1, $divisions);
        $all       = $this->flattenBass($measures, $divisions);
        $chords    = $this->structuralChords($all, $keyFifths, $keyMode);

        return [
            'stability'   => $this->stabilityCounts($chords),
            'pip_arcs'    => $this->pipArcs($chords),
            'octave_rule' => $this->octaveRuleSpans($chords),
            'five_six'    => $this->fiveSix($all),
            'ficta'       => $this->ficta($all, $keyFifths, $keyMode),
        ];
    }

    /**
     * Structural chords with scale degree, figures, bass direction and stability.
     * Unfigured chords take their stability from the Rule of the Octave.
     *
     * @param list<array> $all every non-rest bass note in order
     *
     * @return list<array{measure:int, note:\App\Model\Note, degree:int, nums:int[], figured:bool, motionIn:string, direction:string, stability:string}>
     */
    private function structuralChords(array $all, int $fifths, string $mode): array
    {
        $struct = array_values(array_filter($all, fn(array $e): bool => $e['structural']));

        $out = [];
        $n   = count($struct);
        for ($i = 0; $i < $n; $i++) {
            $note  = $struct[$i]['note'];
            $prev  = $struct[$i - 1]['note'] ?? null;
            $next  = $struct[$i + 1]['note'] ?? null;
            $mIn   = $this->analyzer->motion($prev, $note)['type'];
            $mOut  = $this->analyzer->motion($note, $next)['type'];
            $dir   = $this->direction($mIn, $mOut);
            $deg   = $this->analyzer->scaleDegree($note, $fifths, $mode);

            $stability = $struct[$i]['figured']
                ? $this->octaveRule->stabilityFromFigures($struct[$i]['nums'])
                : $this->octaveRule->expected($deg, $dir)['stability'];

            $out[] = [
                'measure'   => $struct[$i]['measure'],
                'note'      => $note,
                'degree'    => $deg,
                'nums'      => $struct[$i]['nums'],
                'figured'   => $struct[$i]['figured'],
                'motionIn'  => $mIn,
                'direction' => $dir,
                'stability' => $stability,
            ];
        }

        return $out;
    }

    private function direction(string $mIn, string $mOut): string
    {
        foreach ([$mIn, $mOut] as $m) {
            if ($m === 'step-up')   return 'ascending';
            if ($m === 'step-down') return 'descending';
        }

        return 'ascending';
    }

    /** @param list<array{stability:string}> $chords */
    private function stabilityCounts(array $chords): array
    {
        $counts = ['stable' => 0, 'mobile' => 0, 'dissonant' => 0];
        foreach ($chords as $c) {
            $counts[$c['stability']]++;
        }

        return $counts;
    }

    /**
     * p–i–p arcs: from a stable chord, through ≥1 mobile/dissonant chord, to the
     * next stable chord.
     *
     * @param list<array> $chords
     */
    private function pipArcs(array $chords): array
    {
        $arcs     = [];
        $start    = null;
        $unstable = 0;
        foreach ($chords as $c) {
            if ($c['stability'] === RuleOfTheOctave::STABLE) {
                if ($start !== null && $unstable > 0) {
                    $arcs[] = [
                        'start_measure' => $start['measure'],
                        'end_measure'   => $c['measure'],
                        'length'        => $unstable,
                    ];
                }
                $start    = $c;
                $unstable = 0;
            } elseif ($start !== null) {
                $unstable++;
            }
        }

        return $arcs;
    }

    /**
     * Maximal stepwise bass runs (same direction, ≥3 chords) = Terz/Quart/Quint-
     * gänge, with Rule-of-the-Octave conformance over their figured chords.
     *
     * @param list<array> $chords
     */
    private function octaveRuleSpans(array $chords): array
    {
        $spans = [];
        $n     = count($chords);
        $i     = 0;
        while ($i < $n - 1) {
            $step = $chords[$i + 1]['motionIn'];
            if ($step !== 'step-up' && $step !== 'step-down') {
                $i++;
                continue;
            }

            $j = $i + 1;
            while ($j < $n && $chords[$j]['motionIn'] === $step) {
                $j++;
            }
            $run   = array_slice($chords, $i, $j - $i);
            $count = count($run);

            if ($count >= 3) {
                $direction = $step === 'step-up' ? 'ascending' : 'descending';
                $conforms  = true;
                foreach ($run as $c) {
                    if ($c['figured'] && !$this->octaveRule->conforms($c['degree'], $direction, $c['nums'])) {
                        $conforms = false;
                        break;
                    }
                }
                $spans[] = [
                    'type'          => self::GANG_NAMES[$count] ?? 'octave',
                    'direction'     => $direction,
                    'start_measure' => $run[0]['measure'],
                    'end_measure'   => $run[$count - 1]['measure'],
                    'conforms'      => $conforms,
                ];
            }

            $i = $j - 1;
        }

        return $spans;
    }

    /**
     * 5–6 motions: a sixth arriving over a held bass note that carried no sixth
     * (dynamisation), or a 5 and 6 figured together on one note.
     *
     * @param list<array> $all
     */
    private function fiveSix(array $all): array
    {
        $has  = static fn(array $nums, int $n): bool => in_array($n, $nums, true);
        $seen = [];
        foreach ($all as $i => $e) {
            $single = $has($e['nums'], 5) && $has($e['nums'], 6);

            $prev  = $all[$i - 1] ?? null;
            $held  = $prev !== null && $prev['midi'] === $e['midi']
                && !$has($prev['nums'], 6) && !$has($prev['nums'], 7)
                && $has($e['nums'], 6) && !$has($e['nums'], 7);

            if (($single || $held) && !isset($seen[$e['measure']])) {
                $seen[$e['measure']] = ['measure' => $e['measure']];
            }
        }

        return array_values($seen);
    }

    /**
     * Ficta: a chromatic bass tone (outside the local scale) that resolves by
     * step to the next bass note.
     *
     * @param list<array> $all
     */
    private function ficta(array $all, int $fifths, string $mode): array
    {
        $scale = PitchHelper::buildScale($fifths, $mode);
        $out   = [];
        for ($i = 0; $i < count($all) - 1; $i++) {
            $note = $all[$i]['note'];
            if (in_array($note->pitchClass(), $scale, true)) {
                continue;   // diatonic
            }
            $step = abs($all[$i + 1]['note']->midiPitch() - $note->midiPitch());
            if ($step >= 1 && $step <= 2) {
                $out[] = ['measure' => $all[$i]['measure'], 'note' => (string) $note];
            }
        }

        return $out;
    }

    /**
     * Every non-rest bass note in order, annotated with figures, MIDI and whether
     * it is a structural (chord-bearing) note.
     *
     * @param Measure[] $measures
     *
     * @return list<array{measure:int, note:\App\Model\Note, midi:int, nums:int[], figured:bool, structural:bool}>
     */
    private function flattenBass(array $measures, int $divisions): array
    {
        $events = [];
        foreach ($measures as $measure) {
            $offset = 0.0;
            foreach ($measure->bassNotes as $note) {
                $dq = $note->duration / $divisions;
                if (!$note->isRest()) {
                    $onBeat  = abs($offset - round($offset)) < 0.05;
                    $figured = !empty($note->figuredBass);
                    $events[] = [
                        'measure'    => $measure->number,
                        'note'       => $note,
                        'midi'       => $note->midiPitch(),
                        'nums'       => array_map(static fn(array $f): int => (int) $f['number'], $note->figuredBass),
                        'figured'    => $figured,
                        'structural' => $figured || $dq >= 0.99 || $onBeat,
                    ];
                }
                $offset += $dq;
            }
        }

        return $events;
    }
}
