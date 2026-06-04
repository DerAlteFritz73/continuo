<?php

namespace App\Service;

use App\Model\Chord;
use App\Model\Note;
use App\Repository\VoiceLeadingRuleRepository;

/**
 * Applies voice-leading rules to produce smooth, historically-correct
 * basso continuo realizations.
 *
 * Rules implemented (in priority order):
 *
 * 1. RANGE CONSTRAINTS (Baroque keyboard style, Gasparini):
 *    - Soprano: C4–G5
 *    - Alto:    G3–C5
 *    - Tenor:   G3–E4  (hard floor G3 per historical practice)
 *    - Bass:    given (unchanged)
 *
 * 2. FORBIDDEN PARALLELS (Fux / Gasparini / Delair):
 *    - No parallel (consecutive) perfect fifths between any pair of voices
 *    - No parallel (consecutive) perfect octaves between any pair of voices
 *    - No parallel (consecutive) unisons
 *    - Hidden (direct) fifths/octaves forbidden between outer voices when
 *      soprano moves by leap
 *
 * 3. DOUBLING RULES (Gasparini / Delair):
 *    - In root position: double the bass (root)
 *    - In first inversion: double the third or fifth (not the bass)
 *    - In second inversion: double the fifth (= the bass note) — cadential 6/4
 *    - Never double the leading tone (scale degree 7)
 *    - Never double the 7th of a chord
 *
 * 4. VOICE LEADING / LAW OF SHORTEST WAY (Delair):
 *    - Prefer common tones between chords; retain them in the same voice
 *    - Exception for repeated chords: shift position away from range extremes
 *    - Prefer steps over leaps
 *    - Contrary motion between soprano and bass preferred
 *    - One voice may leap if others move by step/common tone
 *
 * 5. SEVENTH CHORD RESOLUTION (Rameau / Gasparini):
 *    - 7th resolves down by step
 *    - Leading tone resolves up by half step
 *    - 5th of V7 may omit for four-part writing
 *
 * 6. SUSPENSION RESOLUTION:
 *    - 9-8: 9th resolves down to 8th (unison with bass)
 *    - 7-6: 7th resolves down to 6th
 *    - 4-3: 4th resolves down to 3rd
 *
 * Rules are loaded from the database via VoiceLeadingRuleRepository and
 * compiled into closures at first use (lazy, cached per request).
 */
class VoiceLeadingEngine
{
    // Voice range MIDI limits: [min, max]
    private const RANGES = [
        'soprano' => [60, 79],  // C4–G5
        'alto'    => [55, 72],  // G3–C5
        'tenor'   => [55, 64],  // G3–E4  (floor raised from C3 to avoid muddy bass register)
    ];

    // Maximum right-hand span: soprano − tenor must not exceed a 9th (major 9th = 14 semitones)
    private const MAX_HAND_SPAN = 14;

    // Perfect intervals in semitones (mod 12)
    private const PERFECT_CONSONANCES = [0, 7]; // unison/octave=0, fifth=7

    /** @var array<int, \Closure>|null Compiled rule closures, null until first use */
    private ?array $compiledRules = null;

    /** @var array<string, array{citations: array, translation: string}>|null DB rule data, null until first use */
    private ?array $dbRulesMap = null;

    /** Current key context, set per realize() call */
    private int $keyFifths = 0;
    private string $keyMode = 'major';

    public function __construct(
        private readonly PitchHelper $pitchHelper,
        private readonly VoiceLeadingRuleRepository $ruleRepo,
    ) {}

    /**
     * Choose upper voices (soprano, alto, tenor) for a chord given:
     *  - The required intervals above bass (from FiguredBassInterpreter)
     *  - The previous chord (for voice leading)
     *  - Key context
     *
     * Returns the Chord with upperVoices populated.
     */
    public function realize(
        Chord   $chord,
        array   $intervals,    // [['interval'=>int,'alter'=>int,'explicit'=>bool]]
        ?Chord  $prevChord,
        int     $keyFifths,
        string  $keyMode,
        bool    $isLeadingTone7th = false,
        ?int    $melodyPc = null,  // pitch class (0–11) of the melody note sounding now
        int     $numVoices = 4,    // total voices: 4 = soprano+alto+tenor+bass, 3 = alto+soprano+bass
    ): Chord {
        $this->keyFifths = $keyFifths;
        $this->keyMode   = $keyMode;

        $bass = $chord->bass;

        // Build candidate pitches for each interval
        $candidatePitches = $this->buildCandidates($bass, $intervals, $keyFifths, $keyMode);

        if (empty($candidatePitches)) {
            // Fallback: triad
            $candidatePitches = $this->buildCandidates(
                $bass,
                [['interval'=>3,'alter'=>0,'explicit'=>false],['interval'=>5,'alter'=>0,'explicit'=>false]],
                $keyFifths, $keyMode
            );
        }

        $prevUpperMidis = $prevChord
            ? array_map(fn(Note $n) => $n->midiPitch(), $prevChord->upperVoices)
            : [];
        $prevBassMidi = $prevChord ? $prevChord->bass->midiPitch() : null;

        // When the user requested 3 voices, check whether this particular chord requires
        // a 4th voice: either because the harmony needs 4 distinct pitch classes (7th chords
        // and similar) or because an unresolved voice-leading obligation from the previous
        // chord (leading-tone resolution up, chordal-7th resolution down) cannot be handled
        // cleanly with only two upper voices.
        $effectiveVoices = $numVoices === 3
            ? $this->effectiveVoiceCount($intervals, $prevChord, $keyFifths, $keyMode)
            : $numVoices;

        // Choose pitches by minimizing total voice movement
        $chosen = $this->chooseVoices($candidatePitches, $prevUpperMidis, $bass->midiPitch(), $isLeadingTone7th, $prevBassMidi, $melodyPc, $effectiveVoices);

        // Voice name order matches the upperVoices array order (lowest first)
        $voiceNames = $effectiveVoices === 3
            ? ['alto', 'soprano']
            : ['tenor', 'alto', 'soprano'];

        foreach ($chosen as $idx => $midi) {
            $voiceName = $voiceNames[$idx] ?? 'soprano';
            $note      = PitchHelper::midiToNote($midi, $bass->duration, $bass->type, $idx + 2, $keyFifths);
            $chord->addUpperVoice($note);
        }

        return $chord;
    }

    /**
     * Determine the effective voice count for a single chord when the user preference
     * is 3 voices ("whenever possible").
     *
     * Upgrades to 4 voices when:
     *  1. The chord has ≥ 3 intervals above the bass (7th chords, 6/5, 4/3 etc.) — four
     *     distinct pitch classes cannot be covered by two upper voices.
     *  2. The previous chord had a chordal seventh (interval 7 in figures) — the 7th must
     *     resolve downward by step, and a dedicated tenor voice makes this smooth.
     *  3. The previous chord's upper voices contained the diatonic leading tone — it must
     *     resolve upward by a half step; an extra voice prevents the resolution from
     *     forcing a forbidden parallel or omitting a required chord tone.
     */
    private function effectiveVoiceCount(array $intervals, ?Chord $prevChord, int $keyFifths, string $keyMode): int
    {
        // Rule 1: harmony needs 4 pitch classes
        if (count($intervals) >= 3) {
            return 4;
        }

        if ($prevChord === null) {
            return 3;
        }

        // Rule 2: previous chord had a chordal 7th
        foreach ($prevChord->figures as $fig) {
            if (($fig['number'] ?? 0) === 7) {
                return 4;
            }
        }

        // Rule 3: previous chord had the leading tone in an upper voice
        $scale   = PitchHelper::buildScale($keyFifths, $keyMode);
        $tonicPc = $scale[0];
        $ltPc    = ($tonicPc - 1 + 12) % 12;
        foreach ($prevChord->upperVoices as $voice) {
            if ($voice->pitchClass() === $ltPc) {
                return 4;
            }
        }

        return 3;
    }

    /**
     * Build all valid MIDI pitches for each interval above bass, within voice ranges.
     * Returns array of pitch lists, one per interval.
     */
    private function buildCandidates(Note $bass, array $intervals, int $keyFifths, string $keyMode): array
    {
        $scale    = PitchHelper::buildScale($keyFifths, $keyMode);
        $bassMidi = $bass->midiPitch();

        $allCandidates = [];

        foreach ($intervals as $fig) {
            $genericInterval = $fig['interval'];
            $explicitAlter   = $fig['alter'];
            $explicit        = $fig['explicit'] ?? false;

            // Compute the diatonic note above bass for this interval
            $targetNote = PitchHelper::diatonicInterval($bass, $genericInterval, $keyFifths, $keyMode);
            $targetPc   = $targetNote->pitchClass();

            // Apply explicit alteration from figure if any
            if ($explicit && $explicitAlter !== 0) {
                $targetPc = ($targetPc + $explicitAlter + 12) % 12;
            }

            // Octave transpositions in the combined range: midi = ($o+1)*12 + pc
            $candidates = [];
            for ($o = 2; $o <= 5; $o++) {
                $midi = ($o + 1) * 12 + $targetPc;
                if ($midi > $bassMidi && $midi <= self::RANGES['soprano'][1] + 12) {
                    $candidates[] = $midi;
                }
            }

            if (!empty($candidates)) {
                $allCandidates[] = $candidates;
            }
        }

        return $allCandidates;
    }

    /**
     * Choose 3 upper voice MIDI pitches (or fewer) from candidate lists.
     * Algorithm:
     *  1. Assign each interval candidate list to a voice (tenor=lowest, soprano=highest)
     *  2. Minimize total motion from previous chord
     *  3. Enforce range constraints
     *  4. Check for forbidden parallels (post-selection)
     *  5. Doubling: every voice draws from the full pool of chord-tone pitch classes
     *     (no fixed interval→voice assignment) so the optimizer can freely mix doublings.
     */
    private function chooseVoices(array $candidateLists, array $prevMidis, int $bassMidi, bool $isLeadingTone, ?int $prevBassMidi = null, ?int $melodyPc = null, int $numVoices = 4): array
    {
        if (empty($candidateLists)) {
            return [];
        }

        // Collect the pitch classes required by the figured-bass intervals.
        $requiredPcs = [];
        foreach ($candidateLists as $list) {
            foreach ($list as $midi) {
                $pc = $midi % 12;
                if (!in_array($pc, $requiredPcs, true)) {
                    $requiredPcs[] = $pc;
                }
            }
        }

        // Melody completion: remove melody PC from required set so the freed voice
        // can use a better doubling.
        $requiredPcsForVoices = $requiredPcs;
        if ($melodyPc !== null && in_array($melodyPc, $requiredPcsForVoices, true)) {
            $requiredPcsForVoices = array_values(
                array_filter($requiredPcsForVoices, fn($pc) => $pc !== $melodyPc)
            );
        }

        // Build the full chord-tone pool.
        // In 3-voice mode (2 upper voices): allow root doubling when ≤ 1 required PC
        //   so that simple triads (e.g. 5-3) still cover root + third.
        // In 4-voice mode (3 upper voices): allow root doubling when ≤ 2 required PCs.
        $numUpper   = $numVoices === 3 ? 2 : 3;
        $allPcs     = $requiredPcs;
        $maxForRoot = $numVoices === 3 ? 1 : 2;
        if (count($requiredPcs) <= $maxForRoot) {
            $bassPc = $bassMidi % 12;
            if (!in_array($bassPc, $allPcs, true)) {
                $allPcs[] = $bassPc;
            }
        }

        // Build per-voice candidate lists within each voice's range.
        $voiceRanges = $numVoices === 3
            ? [self::RANGES['alto'], self::RANGES['soprano']]
            : [self::RANGES['tenor'], self::RANGES['alto'], self::RANGES['soprano']];

        $filtered = [];
        for ($v = 0; $v < $numUpper; $v++) {
            [$min, $max] = $voiceRanges[$v];
            $list = [];
            foreach ($allPcs as $pc) {
                for ($o = 2; $o <= 5; $o++) {
                    $midi = ($o + 1) * 12 + $pc;
                    if ($midi >= $min && $midi <= $max && $midi > $bassMidi) {
                        $list[] = $midi;
                    }
                }
            }
            sort($list);
            $filtered[$v] = array_values(array_unique($list));

            if (empty($filtered[$v])) {
                $list = [];
                foreach ($allPcs as $pc) {
                    for ($o = 2; $o <= 5; $o++) {
                        $midi = ($o + 1) * 12 + $pc;
                        if ($midi >= ($min - 5) && $midi <= ($max + 5) && $midi > $bassMidi) {
                            $list[] = $midi;
                        }
                    }
                }
                sort($list);
                $filtered[$v] = array_values(array_unique($list));
            }

            if (empty($filtered[$v])) {
                $filtered[$v] = [$bassMidi + 7];
            }
        }

        $limit = 8;

        if ($numVoices === 3) {
            // ── 3-voice mode: alto + soprano only ────────────────────────────
            $bestChosen = [$filtered[0][0], $filtered[1][0]];
            $a_opts     = array_slice($filtered[0], 0, $limit);
            $s_opts     = array_slice($filtered[1], 0, $limit);

            $found = $this->searchVoices2($a_opts, $s_opts, $prevMidis, $bassMidi, self::MAX_HAND_SPAN, $prevBassMidi, $requiredPcsForVoices, $melodyPc);
            if ($found !== null) {
                return $found;
            }
            $found = $this->searchVoices2($a_opts, $s_opts, $prevMidis, $bassMidi, 16, $prevBassMidi, $requiredPcsForVoices, $melodyPc);
            return $found ?? $bestChosen;
        }

        // ── 4-voice mode: tenor + alto + soprano ─────────────────────────────
        $bestChosen = [$filtered[0][0], $filtered[1][0], $filtered[2][0]];
        $t_opts     = array_slice($filtered[0], 0, $limit);
        $a_opts     = array_slice($filtered[1], 0, $limit);
        $s_opts     = array_slice($filtered[2], 0, $limit);

        $found = $this->searchVoices($t_opts, $a_opts, $s_opts, $prevMidis, $bassMidi, self::MAX_HAND_SPAN, $prevBassMidi, $requiredPcsForVoices, $melodyPc);
        if ($found !== null) {
            return $found;
        }
        $found = $this->searchVoices($t_opts, $a_opts, $s_opts, $prevMidis, $bassMidi, 16, $prevBassMidi, $requiredPcsForVoices, $melodyPc);
        return $found ?? $bestChosen;
    }

    /**
     * Inner search loop: evaluate all tenor/alto/soprano combinations within $maxSpan.
     * Returns the best [tenor, alto, soprano] array, or null if no combination qualifies.
     */
    private function searchVoices(
        array $tOpts, array $aOpts, array $sOpts,
        array $prevMidis, int $bassMidi, int $maxSpan, ?int $prevBassMidi = null,
        array $requiredPcs = [], ?int $melodyPc = null
    ): ?array {
        $bestCost   = PHP_INT_MAX;
        $bestChosen = null;

        $isStart = empty($prevMidis);

        foreach ($tOpts as $t) {
            if ($t <= $bassMidi) {
                continue;
            }
            foreach ($aOpts as $a) {
                foreach ($sOpts as $s) {
                    // Voice ordering: tenor <= alto <= soprano
                    if (!($t <= $a && $a <= $s)) {
                        continue;
                    }
                    // Prevent adjacent-voice unisons (collapse of voice independence)
                    if ($t === $a || $a === $s) {
                        continue;
                    }
                    // Right-hand span constraint
                    if (($s - $t) > $maxSpan) {
                        continue;
                    }

                    $cost = $this->voiceCost([$t, $a, $s], $prevMidis, $bassMidi, $isStart, $prevBassMidi);

                    // Hard penalty for omitting a required figured-bass interval.
                    // 150 per missing PC dwarfs any motion-cost gain (max ~30), so the
                    // optimizer will always prefer a voicing that includes all figure tones.
                    if (!empty($requiredPcs)) {
                        $presentPcs = [$t % 12, $a % 12, $s % 12];
                        foreach ($requiredPcs as $rpc) {
                            if (!in_array($rpc, $presentPcs, true)) {
                                $cost += 150.0;
                            }
                        }
                    }

                    // Melody avoidance: penalise doubling the melody pitch class.
                    // 40 per voice is enough to steer away when alternatives exist,
                    // but stays below the required-PC penalty (150) so that figured
                    // bass intervals are never omitted just to avoid a melody clash.
                    if ($melodyPc !== null) {
                        if ($t % 12 === $melodyPc) $cost += 40.0;
                        if ($a % 12 === $melodyPc) $cost += 40.0;
                        if ($s % 12 === $melodyPc) $cost += 40.0;
                    }

                    if ($cost < $bestCost) {
                        $bestCost   = $cost;
                        $bestChosen = [$t, $a, $s];
                    }
                }
            }
        }

        return $bestChosen;
    }

    /**
     * 2-voice inner search loop (3-voice mode: alto + soprano).
     * Returns the best [alto, soprano] array, or null if none qualifies.
     */
    private function searchVoices2(
        array $aOpts, array $sOpts,
        array $prevMidis, int $bassMidi, int $maxSpan, ?int $prevBassMidi = null,
        array $requiredPcs = [], ?int $melodyPc = null
    ): ?array {
        $bestCost   = PHP_INT_MAX;
        $bestChosen = null;
        $isStart    = empty($prevMidis);

        foreach ($aOpts as $a) {
            if ($a <= $bassMidi) {
                continue;
            }
            foreach ($sOpts as $s) {
                if ($a > $s)           continue; // alto ≤ soprano
                if ($a === $s)         continue; // no unison
                if (($s - $a) > $maxSpan) continue;

                $cost = $this->voiceCost2([$a, $s], $prevMidis, $bassMidi, $isStart, $prevBassMidi);

                if (!empty($requiredPcs)) {
                    $present = [$a % 12, $s % 12];
                    foreach ($requiredPcs as $rpc) {
                        if (!in_array($rpc, $present, true)) {
                            $cost += 150.0;
                        }
                    }
                }

                if ($melodyPc !== null) {
                    if ($a % 12 === $melodyPc) $cost += 40.0;
                    if ($s % 12 === $melodyPc) $cost += 40.0;
                }

                if ($cost < $bestCost) {
                    $bestCost   = $cost;
                    $bestChosen = [$a, $s];
                }
            }
        }

        return $bestChosen;
    }

    /**
     * Cost function for 2-voice (alto + soprano) mode.
     * Index 0 = alto, index 1 = soprano.
     */
    private function voiceCost2(array $curr, array $prevMidis, int $bassCurr, bool $isStart, ?int $prevBassMidi): float
    {
        $cost    = 0.0;
        $bassPrev = $isStart ? $bassCurr : ($prevBassMidi ?? $bassCurr);

        // Motion from previous chord
        foreach ($curr as $i => $midi) {
            $prev = $prevMidis[$i] ?? null;
            if ($prev !== null) {
                $motion = abs($midi - $prev);
                if ($motion === 0)     $cost += 0;
                elseif ($motion <= 2)  $cost += 1;
                elseif ($motion <= 4)  $cost += 4;
                elseif ($motion <= 7)  $cost += 9;
                else                   $cost += $motion * 3;
            }
        }

        // Contrary motion: soprano (index 1) vs bass
        if (!$isStart && isset($prevMidis[1])) {
            $bassDir = $bassCurr - $bassPrev;
            $sopDir  = $curr[1] - $prevMidis[1];
            if ($bassDir !== 0 && $sopDir !== 0 && (($bassDir > 0) === ($sopDir > 0))) {
                $cost += 6.0;
            }
        }

        // Parallel perfect consonances (check alto–soprano and each vs bass)
        if (!$isStart && count($prevMidis) >= 2) {
            $allCurr = [$curr[0], $curr[1], $bassCurr];
            $allPrev = [$prevMidis[0], $prevMidis[1], $bassPrev];
            for ($i = 0; $i < 3; $i++) {
                for ($j = $i + 1; $j < 3; $j++) {
                    $pInt = abs($allPrev[$i] - $allPrev[$j]) % 12;
                    $cInt = abs($allCurr[$i] - $allCurr[$j]) % 12;
                    if ($pInt === $cInt && ($allPrev[$i] !== $allCurr[$i] || $allPrev[$j] !== $allCurr[$j])) {
                        if ($cInt === 7)  $cost += 40;
                        elseif ($cInt === 0) $cost += 60;
                    }
                }
            }
        }

        // Voice crossing
        if ($curr[0] > $curr[1]) {
            $cost += 100;
        }

        // Outside ideal range
        foreach ($curr as $i => $midi) {
            [$lo, $hi] = $i === 0 ? self::RANGES['alto'] : self::RANGES['soprano'];
            if ($midi < $lo) $cost += ($lo - $midi) * 3;
            if ($midi > $hi) $cost += ($midi - $hi) * 3;
        }

        return $cost;
    }

    /**
     * Cost function for a chord voicing.
     * Delegates to DB-loaded rule closures; falls back to hard-coded logic if no
     * rules are found (e.g., empty database during first boot before seeding).
     */
    private function voiceCost(array $curr, array $prev, int $bassCurr, bool $isStart, ?int $prevBassMidi = null): float
    {
        $rules = $this->loadRules();

        if (empty($rules)) {
            return $this->voiceCostFallback($curr, $prev, $bassCurr, $prevBassMidi);
        }

        $bassPrev = $isStart ? $bassCurr : ($prevBassMidi ?? $bassCurr);

        $ctx = [
            'prev'      => $prev,
            'curr'      => $curr,
            'bassPrev'  => $bassPrev,
            'bassCurr'  => $bassCurr,
            'ranges'    => self::RANGES,
            'isStart'   => $isStart,
            'keyFifths' => $this->keyFifths,
            'keyMode'   => $this->keyMode,
        ];

        $cost = 0.0;
        foreach ($rules as $closure) {
            $cost += (float) $closure($ctx);
        }

        return $cost;
    }

    /**
     * Lazy-load and compile rule closures from the database.
     *
     * @return array<int, \Closure>
     */
    private function loadRules(): array
    {
        if ($this->compiledRules !== null) {
            return $this->compiledRules;
        }

        $this->compiledRules = [];

        try {
            foreach ($this->ruleRepo->findActiveOrderedByPriority() as $rule) {
                $body = $rule->getImplementation();
                // ⚠️  SECURITY: eval() compiles PHP code from database. This is intentional but dangerous:
                // If the database is compromised, arbitrary code can execute. Mitigations in place:
                // 1. Database access restricted to app + admin only
                // 2. Rules manually reviewed before deployment
                // 3. Fallback to hard-coded rules if compilation fails
                // For production, consider: syntax validation, custom DSL, or code review workflow.
                $fn = eval(sprintf('return function(array $ctx): float { %s };', $body));
                if ($fn instanceof \Closure) {
                    $this->compiledRules[] = $fn;
                }
            }
        } catch (\Throwable) {
            // DB unavailable — fall back to hard-coded rules (empty list triggers voiceCostFallback)
            $this->compiledRules = [];
        }

        return $this->compiledRules;
    }

    /**
     * Hard-coded fallback cost function used when the rules table is empty
     * (e.g., immediately after install, before `app:seed-voice-leading-rules` runs).
     */
    private function voiceCostFallback(array $chosen, array $prevMidis, int $bassMidi, ?int $prevBassMidi = null): float
    {
        $cost = 0.0;

        // Motion from previous chord — prefer common tones, then steps
        foreach ($chosen as $i => $midi) {
            $prev = $prevMidis[$i] ?? null;
            if ($prev !== null) {
                $motion = abs($midi - $prev);
                if ($motion === 0) {
                    $cost += 0;        // common tone — best
                } elseif ($motion <= 2) {
                    $cost += 1;        // step
                } elseif ($motion <= 4) {
                    $cost += 4;        // small leap
                } elseif ($motion <= 7) {
                    $cost += 9;        // leap of 5th/6th
                } else {
                    $cost += $motion * 3; // large leap — heavy penalty
                }
            }
        }

        // Contrary motion between soprano and bass
        if ($prevBassMidi !== null && count($prevMidis) >= 3) {
            $bassDir = $bassMidi - $prevBassMidi;
            $sopDir  = $chosen[2] - $prevMidis[2];
            if ($bassDir !== 0 && $sopDir !== 0 && (($bassDir > 0) === ($sopDir > 0))) {
                $cost += 6.0;
            }
        }

        // Parallel perfect consonances penalty
        $allCurr = array_merge($chosen, [$bassMidi]);
        $allPrev = array_merge($prevMidis, []);

        if (count($prevMidis) >= 3) {
            $allPrev = array_merge($prevMidis, [$bassMidi]);
            $n = count($allCurr);
            for ($a = 0; $a < $n; $a++) {
                for ($b = $a + 1; $b < $n; $b++) {
                    if (!isset($allPrev[$a]) || !isset($allPrev[$b])) continue;
                    $prevInterval = abs($allPrev[$a] - $allPrev[$b]) % 12;
                    $currInterval = abs($allCurr[$a] - $allCurr[$b]) % 12;
                    $moved = ($allPrev[$a] !== $allCurr[$a]) || ($allPrev[$b] !== $allCurr[$b]);
                    if ($moved && $prevInterval === $currInterval) {
                        if ($currInterval === 7) {
                            $cost += 40;  // parallel 5ths
                        } elseif ($currInterval === 0) {
                            $cost += 60;  // parallel octaves/unisons
                        }
                    }
                }
            }
        }

        // Voice crossing
        for ($i = 0; $i < count($chosen) - 1; $i++) {
            if ($chosen[$i] > $chosen[$i + 1]) {
                $cost += 100;
            }
        }

        // Outside ideal range
        $ranges = [self::RANGES['tenor'], self::RANGES['alto'], self::RANGES['soprano']];
        foreach ($chosen as $i => $midi) {
            [$lo, $hi] = $ranges[$i];
            if ($midi < $lo) $cost += ($lo - $midi) * 3;
            if ($midi > $hi) $cost += ($midi - $hi) * 3;
        }

        return $cost;
    }

    /**
     * Lazy-load the DB rules citation/translation map.
     *
     * @return array<string, array{citations: array, translation: string}>
     */
    private function loadDbRulesMap(): array
    {
        if ($this->dbRulesMap !== null) {
            return $this->dbRulesMap;
        }
        try {
            $this->dbRulesMap = $this->ruleRepo->findCitationsMap();
        } catch (\Throwable) {
            $this->dbRulesMap = [];
        }
        return $this->dbRulesMap;
    }

    /**
     * Collect citations + translation from one or more DB rules by name.
     * Citations from multiple rules are merged into a single flat array.
     *
     * @param  string[] $dbNames  DB rule names (keys in the citations map)
     * @return array               Flat list of citation objects
     */
    private function dbCitations(array $dbNames): array
    {
        $map    = $this->loadDbRulesMap();
        $result = [];
        foreach ($dbNames as $name) {
            if (!isset($map[$name])) {
                continue;
            }
            foreach ($map[$name]['citations'] as $c) {
                // Attach the rule-level English translation to each citation entry
                // so the template can show it alongside the original-language text.
                $c['translation'] = $map[$name]['translation'];
                $result[] = $c;
            }
        }
        return $result;
    }

    /**
     * Produce a human-readable trace of the voice-leading choices made for $chord
     * relative to $prevChord.  Returns an array of step objects compatible with
     * the chord-inspector UI (same format as FiguredBassInterpreter::unfiguredDecision).
     */
    public function traceVoiceLeading(Chord $chord, ?Chord $prevChord, int $keyFifths, string $keyMode): array
    {
        $steps      = [];
        $currUpper  = $chord->upperVoices;
        $voiceNames = count($currUpper) === 2 ? ['Alto', 'Soprano'] : ['Tenor', 'Alto', 'Soprano'];
        $prevUpper  = $prevChord ? $prevChord->upperVoices : [];

        // ── Per-voice motion ──────────────────────────────────────────────────
        foreach ($currUpper as $vi => $currNote) {
            $name      = $voiceNames[$vi] ?? 'Voice';
            $currLabel = $this->noteLabel($currNote);
            $prevNote  = $prevUpper[$vi] ?? null;

            if ($prevNote === null) {
                $steps[] = ['test' => $name . ': ' . $currLabel . ' (opening)', 'passed' => true, 'isDecision' => false];
                continue;
            }

            $motion    = $currNote->midiPitch() - $prevNote->midiPitch();
            $abs       = abs($motion);
            $dir       = $motion > 0 ? '↑' : ($motion < 0 ? '↓' : '');
            $prevLabel = $this->noteLabel($prevNote);

            if ($abs === 0) {
                $desc = 'common tone';
            } elseif ($abs <= 2) {
                $desc = 'step ' . $dir;
            } elseif ($abs <= 4) {
                $desc = 'small leap ' . $dir . ' (' . $abs . ' st.)';
            } else {
                $desc = 'leap ' . $dir . ' (' . $abs . ' st.)';
            }

            $steps[] = [
                'test'       => $name . ': ' . $prevLabel . ' → ' . $currLabel . ' — ' . $desc,
                'passed'     => $abs <= 7,
                'isDecision' => false,
            ];
        }

        // ── Parallel consonances ─────────────────────────────────────────────
        if ($prevChord !== null) {
            $violations = $this->checkParallels($prevChord, $chord);
            $parallelCitations = $this->dbCitations(['no_parallel_fifths', 'no_parallel_octaves']);
            if (empty($violations)) {
                $steps[] = [
                    'test'       => 'No parallel 5ths or octaves',
                    'passed'     => true,
                    'isDecision' => true,
                    'rule'       => 'Forbidden Parallels',
                    'source'     => 'Gasparini 1729; Delair 1724',
                    'reason'     => 'All voice pairs move without forbidden parallels',
                    'citations'  => $parallelCitations,
                ];
            } else {
                foreach ($violations as $v) {
                    $steps[] = [
                        'test'       => 'Parallel: ' . $v,
                        'passed'     => false,
                        'isDecision' => true,
                        'rule'       => 'Forbidden Parallels',
                        'source'     => 'Gasparini 1729; Delair 1724',
                        'reason'     => $v,
                        'citations'  => $parallelCitations,
                    ];
                }
            }

            // ── Contrary motion (soprano vs bass) ────────────────────────────
            $prevSop  = $prevUpper[2] ?? ($prevUpper[1] ?? ($prevUpper[0] ?? null));
            $currSop  = $currUpper[2]  ?? ($currUpper[1]  ?? ($currUpper[0]  ?? null));
            if ($prevSop && $currSop) {
                $bassDir = $chord->bass->midiPitch() - $prevChord->bass->midiPitch();
                $sopDir  = $currSop->midiPitch()     - $prevSop->midiPitch();
                if ($bassDir !== 0 && $sopDir !== 0) {
                    $contrary = ($bassDir > 0) !== ($sopDir > 0);
                    $steps[] = [
                        'test'       => $contrary
                                        ? 'Soprano and bass move in contrary motion'
                                        : 'Soprano and bass move in similar motion',
                        'passed'     => $contrary,
                        'isDecision' => true,
                        'rule'       => 'Contrary Motion (outer voices)',
                        'source'     => 'Delair 1724',
                        'reason'     => $contrary
                                        ? 'Outer voices in opposite directions — preferred'
                                        : 'Similar motion of outer voices — penalised',
                        'citations'  => $this->dbCitations(['contrary_motion_soprano_bass']),
                    ];
                }
            }
        }

        // ── Right-hand span ───────────────────────────────────────────────────
        if (count($currUpper) >= 3) {
            $span = $currUpper[2]->midiPitch() - $currUpper[0]->midiPitch();
            $steps[] = [
                'test'   => 'Right-hand span: ' . $span . ' st. (' . ($span <= self::MAX_HAND_SPAN ? 'within' : 'exceeds') . ' 9th limit)',
                'passed' => $span <= self::MAX_HAND_SPAN,
                'isDecision' => false,
            ];
        }

        return $steps;
    }

    private function noteLabel(Note $note): string
    {
        $acc = match($note->alter) { 1 => '#', -1 => 'b', 2 => '##', -2 => 'bb', default => '' };
        return $note->step . $acc . $note->octave;
    }

    /**
     * Check if parallel fifths or octaves exist between two chords.
     * Returns array of violations (for diagnostic output).
     */
    public function checkParallels(Chord $prev, Chord $curr): array
    {
        $violations = [];
        $prevNotes  = $prev->allNotes();
        $currNotes  = $curr->allNotes();

        $n = min(count($prevNotes), count($currNotes));

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $prevInterval = ($prevNotes[$i]->midiPitch() - $prevNotes[$j]->midiPitch()) % 12;
                $currInterval = ($currNotes[$i]->midiPitch() - $currNotes[$j]->midiPitch()) % 12;

                $prevAbs = abs($prevInterval);
                $currAbs = abs($currInterval);

                if (
                    in_array($prevAbs, self::PERFECT_CONSONANCES) &&
                    $prevAbs === $currAbs &&
                    // Voices must actually move (not a unison on the same pitch)
                    $prevNotes[$i]->midiPitch() !== $currNotes[$i]->midiPitch()
                ) {
                    $type = $prevAbs === 7 ? 'parallel fifths' : 'parallel octaves/unisons';
                    $violations[] = "Voices $i and $j: $type";
                }
            }
        }

        return $violations;
    }
}
