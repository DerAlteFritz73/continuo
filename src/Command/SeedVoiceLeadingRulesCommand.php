<?php

namespace App\Command;

use App\Entity\VoiceLeadingRule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-voice-leading-rules',
    description: 'Seed voice-leading rules from historical treatises (idempotent)',
)]
class SeedVoiceLeadingRulesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repo = $this->em->getRepository(VoiceLeadingRule::class);

        $rules = $this->getRuleDefinitions();
        $seeded = 0;
        $updated = 0;

        foreach ($rules as $data) {
            $existing = $repo->findOneBy(['name' => $data['name']]);

            if ($existing !== null) {
                // Full upsert: keep the DB rule in sync with the code (source of
                // truth), including the implementation body — not just citations.
                $existing->setSource($data['source'])
                    ->setPriority($data['priority'])
                    ->setDefinition($data['definition'])
                    ->setTranslation($data['translation'])
                    ->setImplementation($data['implementation'])
                    ->setCitations($data['citations']);
                $updated++;
                $io->note(sprintf('Updated existing rule: %s', $data['name']));
                continue;
            }

            $rule = (new VoiceLeadingRule())
                ->setName($data['name'])
                ->setSource($data['source'])
                ->setPriority($data['priority'])
                ->setDefinition($data['definition'])
                ->setTranslation($data['translation'])
                ->setImplementation($data['implementation'])
                ->setCitations($data['citations'])
                ->setEnabled(true);

            $this->em->persist($rule);
            $seeded++;
        }

        $this->em->flush();
        $io->success(sprintf('%d rule(s) seeded, %d rule(s) had citations updated.', $seeded, $updated));

        return Command::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function getRuleDefinitions(): array
    {
        return [
            [
                'priority' => 10,
                'name'     => 'voice_range',
                'source'   => 'Lambert [1707]; Christensen 2002, 40',
                'definition' => 'The upper voice must never go beyond e\'\' or f\'\'; the lower limit is normally d\'.',
                'translation' => 'Each upper voice must stay within its assigned MIDI range (soprano: D4–E5; alto: A3–C5; tenor: G3–A4). Notes outside these bounds incur a penalty proportional to the distance.',
                'citations' => [
                    [
                        'author' => 'Lambert, Michel de',
                        'ref'    => 'Nouveau traité de l\'accompagnement du Clavecin. Paris, 1707. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'The upper voice of the chordal accompaniment must never go beyond e\'\' or f\'\' except when the bass moves into the alto register, in which case all the notes become very high.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 40.',
                        'lang'   => 'en',
                        'text'   => 'The normal upper limit is e\'\'; the lower limit normally d\', with c\' and b representing exceptional cases.',
                    ],
                ],
                'implementation' => <<<'PHP'
$cost = 0.0;
$ranges = $ctx['ranges'];
$voiceNames = ['tenor', 'alto', 'soprano'];
foreach ($ctx['curr'] as $i => $midi) {
    $vName = $voiceNames[$i] ?? 'soprano';
    [$lo, $hi] = $ranges[$vName];
    if ($midi < $lo) { $cost += ($lo - $midi) * 3; }
    if ($midi > $hi) { $cost += ($midi - $hi) * 3; }
}
return $cost;
PHP,
            ],

            [
                'priority' => 11,
                'name'     => 'tenor_min_g3',
                'source'   => 'Christensen 2002, 40',
                'definition' => 'The tenor must not descend below G3 so as not to be confused with the bass.',
                'translation' => 'The tenor (lowest of the three right-hand voices) must stay at or above G3 (MIDI 55). Descending below this point blurs the distinction between tenor and bass, and produces a muddy texture.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 40.',
                        'lang'   => 'en',
                        'text'   => 'The lower limit [of the right hand] is normally d\', with c\' and b representing exceptional cases. Below g the texture becomes confused with the bass.',
                    ],
                ],
                'implementation' => <<<'PHP'
$tenor = $ctx['curr'][0] ?? 55;
if ($tenor < 55) { return (55 - $tenor) * 50.0; }
return 0.0;
PHP,
            ],

            [
                'priority' => 12,
                'name'     => 'chord_third_present',
                'source'   => 'Christensen 2002, 10, 62',
                'definition' => 'The third of the chord must always be present in the upper voices.',
                'translation' => 'The third above the bass must be represented in at least one of the three upper voices. Without the third the chord is incomplete and the harmony ambiguous.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'The basic chord [in French basso continuo] consists of the octave, the fifth, and the third. Of these, the third is the defining interval: without it the chord type cannot be determined.',
                    ],
                ],
                'implementation' => <<<'PHP'
$tonicPc  = $ctx['keyMode'] === 'minor'
    ? ((($ctx['keyFifths'] * 7) - 3) % 12 + 12) % 12
    : (($ctx['keyFifths'] * 7) % 12 + 12) % 12;
$steps    = $ctx['keyMode'] === 'minor' ? [0,2,3,5,7,8,10] : [0,2,4,5,7,9,11];
$scalePcs = array_map(fn($i) => ($tonicPc + $i) % 12, $steps);
$bassPc   = $ctx['bassCurr'] % 12;
$deg      = array_search($bassPc, $scalePcs);
if ($deg === false) { return 0.0; } // chromatic bass — skip
$thirdPc  = $scalePcs[($deg + 2) % 7];
$upperPcs = array_map(fn($m) => $m % 12, $ctx['curr']);
if (!in_array($thirdPc, $upperPcs, true)) { return 25.0; }
return 0.0;
PHP,
            ],

            [
                'priority' => 13,
                'name'     => 'soprano_upper_limit_e5',
                'source'   => 'Lambert [1707]; Christensen 2002, 40',
                'definition' => 'The soprano must never go beyond E5 (e\'\').',
                'translation' => 'The soprano (top voice of the right hand) must not exceed E5 (MIDI 76). Notes above this limit incur a penalty proportional to the excess.',
                'citations' => [
                    [
                        'author' => 'Lambert, Michel de',
                        'ref'    => 'Nouveau traité de l\'accompagnement du Clavecin. Paris, 1707. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'The upper voice of the chordal accompaniment must never go beyond e\'\' or f\'\' except when the bass moves into the alto register.',
                    ],
                ],
                'implementation' => <<<'PHP'
$soprano = $ctx['curr'][2] ?? 76;
if ($soprano > 76) { return ($soprano - 76) * 5.0; }
return 0.0;
PHP,
            ],

            [
                'priority' => 20,
                'name'     => 'rh_span_limit',
                'source'   => 'Christensen 2002, 40, 100',
                'definition' => 'The span between soprano and tenor in the right hand must not exceed a ninth.',
                'translation' => 'The interval between the highest and lowest notes of the right hand must not exceed a ninth (14 semitones). Wider spacings are physically awkward and produce a thin, unsupported texture between the outer voices.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 40.',
                        'lang'   => 'en',
                        'text'   => 'Four-voice realization is the bedrock of all thoroughbass playing. The right hand spans must remain comfortable and musical at all times.',
                    ],
                ],
                'implementation' => <<<'PHP'
if (count($ctx['curr']) < 3) { return 0.0; }
$span = $ctx['curr'][2] - $ctx['curr'][0]; // soprano - tenor
if ($span > 14) { return ($span - 14) * 10.0; }
return 0.0;
PHP,
            ],

            [
                'priority' => 30,
                'name'     => 'no_parallel_fifths',
                'source'   => 'Lambert [1707]; Christensen 2002, 18, 28',
                'definition' => 'Parallel fifths between any two voices moving in the same direction are forbidden.',
                'translation' => 'When any two voices both move and the interval between them is a fifth (7 semitones mod 12) both before and after the motion, a parallel fifth results. Each such pair incurs a heavy penalty.',
                'citations' => [
                    [
                        'author' => 'Lambert, Michel de',
                        'ref'    => 'Nouveau traité de l\'accompagnement du Clavecin. Paris, 1707. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'When the bass line ascends in stepwise motion, it sometimes becomes necessary to double the third so as to avoid parallel fifths.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'Two consecutive sixth chords with doubled voices produce parallel fifths and parallel octaves at once; avoid by alternating simple and doubled sixth chords.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
$cost = 0.0;
$allCurr = array_merge($ctx['curr'], [$ctx['bassCurr']]);
$allPrev = array_merge($ctx['prev'], [$ctx['bassPrev']]);
$n = count($allCurr);
for ($a = 0; $a < $n; $a++) {
    for ($b = $a + 1; $b < $n; $b++) {
        if (!isset($allPrev[$a]) || !isset($allPrev[$b])) { continue; }
        $prevInt = abs($allPrev[$a] - $allPrev[$b]) % 12;
        $currInt = abs($allCurr[$a] - $allCurr[$b]) % 12;
        $moved = ($allPrev[$a] !== $allCurr[$a]) || ($allPrev[$b] !== $allCurr[$b]);
        if ($moved && $prevInt === 7 && $currInt === 7) { $cost += 40.0; }
    }
}
return $cost;
PHP,
            ],

            [
                'priority' => 32,
                'name'     => 'no_leading_tone_doubling',
                'source'   => 'Lambert [1707]; Heinichen [1728]; Christensen 2002, 18, 65',
                'definition' => 'The leading tone must never be doubled in any pair of voices.',
                'translation' => 'The leading tone (major seventh of the scale, one semitone below the tonic) must appear in at most one voice. Doubling it creates an obligatory parallel motion to the tonic, which causes parallel octaves.',
                'citations' => [
                    [
                        'author' => 'Lambert, Michel de',
                        'ref'    => 'Nouveau traité de l\'accompagnement du Clavecin. Paris, 1707. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'When the bass ascends from the leading tone to the tonic, the third or the sixth must be doubled.',
                    ],
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. 2nd ed. Dresden, 1728 [1711]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 15.',
                        'lang'   => 'en',
                        'text'   => 'Never double a chromatically altered note that functions as a leading tone. This rule is extremely important.',
                    ],
                ],
                'implementation' => <<<'PHP'
$tonicPc = $ctx['keyMode'] === 'minor'
    ? ((($ctx['keyFifths'] * 7) - 3) % 12 + 12) % 12
    : (($ctx['keyFifths'] * 7) % 12 + 12) % 12;
$ltPc    = ($tonicPc + 11) % 12;
$allPcs  = array_map(fn($m) => $m % 12, array_merge($ctx['curr'], [$ctx['bassCurr']]));
$count   = count(array_filter($allPcs, fn($pc) => $pc === $ltPc));
if ($count > 1) { return 60.0 * ($count - 1); }
return 0.0;
PHP,
            ],

            [
                'priority' => 33,
                'name'     => 'no_chromatic_leading_tone_doubling',
                'source'   => 'Heinichen [1728]; Christensen 2002, 65',
                'definition' => 'Never double a chromatically altered note that functions as a leading tone.',
                'translation' => 'Never double a chromatically altered note that functions as a leading tone (i.e. any note outside the diatonic scale of the current key). One occurrence is permissible; two or more are forbidden.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. 2nd ed. Dresden, 1728 [1711]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 15.',
                        'lang'   => 'en',
                        'text'   => 'Never double a chromatically altered note that functions as a leading tone. This rule is extremely important.',
                    ],
                ],
                'implementation' => <<<'PHP'
$tonicPc  = $ctx['keyMode'] === 'minor'
    ? ((($ctx['keyFifths'] * 7) - 3) % 12 + 12) % 12
    : (($ctx['keyFifths'] * 7) % 12 + 12) % 12;
$steps    = $ctx['keyMode'] === 'minor' ? [0,2,3,5,7,8,10] : [0,2,4,5,7,9,11];
$scalePcs = array_map(fn($i) => ($tonicPc + $i) % 12, $steps);
$allPcs   = array_map(fn($m) => $m % 12, array_merge($ctx['curr'], [$ctx['bassCurr']]));
$cost     = 0.0;
$chromCounts = [];
foreach ($allPcs as $pc) {
    if (!in_array($pc, $scalePcs, true)) {
        $chromCounts[$pc] = ($chromCounts[$pc] ?? 0) + 1;
    }
}
foreach ($chromCounts as $count) {
    if ($count > 1) { $cost += 50.0 * ($count - 1); }
}
return $cost;
PHP,
            ],

            [
                'priority' => 34,
                'name'     => 'no_seventh_doubling',
                'source'   => 'Lambert [1707]; Heinichen [1728]; Christensen 2002, 28, 76',
                'definition' => 'The dissonant seventh must not be doubled in any pair of voices.',
                'translation' => 'The dissonant seventh of a chord (the pitch a minor or major seventh above the bass) must never be doubled in any pair of voices.',
                'citations' => [
                    [
                        'author' => 'Lambert, Michel de',
                        'ref'    => 'Nouveau traité de l\'accompagnement du Clavecin. Paris, 1707. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'It is better to play the third and the fifth with the seventh rather than the third and the octave. The 3 and 8 should only be played when otherwise unavoidable.',
                    ],
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. 2nd ed. Dresden, 1728 [1711]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 16.',
                        'lang'   => 'en',
                        'text'   => 'Often this seventh is followed immediately by its resolution (7–6). The other voices normally combined with it are the third and the octave. Instead of playing the octave, it is also possible to double the third.',
                    ],
                ],
                'implementation' => <<<'PHP'
$bassPc = $ctx['bassCurr'] % 12;
$seventhCount = 0;
foreach ($ctx['curr'] as $m) {
    $interval = ($m % 12 - $bassPc + 12) % 12;
    if ($interval === 10 || $interval === 11) { $seventhCount++; }
}
if ($seventhCount > 1) { return 50.0 * ($seventhCount - 1); }
return 0.0;
PHP,
            ],

            [
                'priority' => 36,
                'name'     => 'no_ninth_doubling',
                'source'   => 'Heinichen [1728]; Christensen 2002, 81–82',
                'definition' => 'The dissonant ninth must not be doubled in any pair of voices.',
                'translation' => 'The dissonant ninth must never be doubled in any pair of voices. Only one voice may hold a ninth above the bass at a time.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. 2nd ed. Dresden, 1728 [1711]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 81–82.',
                        'lang'   => 'en',
                        'text'   => 'The ninth is prepared in the preceding chord and resolved to the next octave. The other notes in the chord are usually the third and the fifth.',
                    ],
                ],
                'implementation' => <<<'PHP'
$bassPc = $ctx['bassCurr'];
$count = 0;
foreach ($ctx['curr'] as $m) {
    $sem = $m - $bassPc;
    // Ninth = 13 (minor) or 14 (major) semitones above bass; also allow compound ninths
    if ($sem === 13 || $sem === 14 || $sem === 25 || $sem === 26) { $count++; }
}
if ($count > 1) { return 50.0 * ($count - 1); }
return 0.0;
PHP,
            ],

            [
                'priority' => 40,
                'name'     => 'no_parallel_octaves',
                'source'   => 'Lambert [1707]; Christensen 2002, 18, 42',
                'definition' => 'Parallel octaves between any two voices moving in the same direction are forbidden.',
                'translation' => 'When any two voices both move and the interval between them is an octave (0 semitones mod 12) both before and after the motion, a parallel octave results. Each such pair incurs a heavy penalty.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 42.',
                        'lang'   => 'en',
                        'text'   => 'In St. Lambert\'s example of stepwise bass motion in fast triple meter, the two hands proceed in strictly contrary motion. This helps to prevent parallel octaves or fifths from occurring.',
                    ],
                    [
                        'author' => 'Lambert, Michel de',
                        'ref'    => 'Nouveau traité de l\'accompagnement du Clavecin. Paris, 1707. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'Never play two consecutive sixth chords with doubled voices unless the doubled notes remain the same.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
$cost = 0.0;
$allCurr = array_merge($ctx['curr'], [$ctx['bassCurr']]);
$allPrev = array_merge($ctx['prev'], [$ctx['bassPrev']]);
$n = count($allCurr);
for ($a = 0; $a < $n; $a++) {
    for ($b = $a + 1; $b < $n; $b++) {
        if (!isset($allPrev[$a]) || !isset($allPrev[$b])) { continue; }
        $prevInt = abs($allPrev[$a] - $allPrev[$b]) % 12;
        $currInt = abs($allCurr[$a] - $allCurr[$b]) % 12;
        $moved = ($allPrev[$a] !== $allCurr[$a]) || ($allPrev[$b] !== $allCurr[$b]);
        if ($moved && $prevInt === 0 && $currInt === 0) { $cost += 60.0; }
    }
}
return $cost;
PHP,
            ],

            [
                'priority' => 45,
                'name'     => 'leading_tone_resolves_up',
                'source'   => 'Lambert [1707]; Christensen 2002, 18, 36',
                'definition' => 'The leading tone must resolve upward to the tonic.',
                'translation' => 'Whatever voice holds a leading tone (major 7th of the scale) must move upward — to the tonic — in the following chord. A voice that stayed on or descended from the leading tone incurs a penalty.',
                'citations' => [
                    [
                        'author' => 'Lambert, Michel de',
                        'ref'    => 'Nouveau traité de l\'accompagnement du Clavecin. Paris, 1707. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'When the bass ascends from the leading tone to the tonic, the third or the sixth must be doubled — implying that the leading tone itself must resolve upward to the tonic.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 36.',
                        'lang'   => 'en',
                        'text'   => 'The augmented fifth assumes the function of a leading tone when in the top voice; it should resolve upward to the sixth.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
$tonicPc = $ctx['keyMode'] === 'minor'
    ? ((($ctx['keyFifths'] * 7) - 3) % 12 + 12) % 12
    : (($ctx['keyFifths'] * 7) % 12 + 12) % 12;
$ltPc = ($tonicPc + 11) % 12;
$cost = 0.0;
foreach ($ctx['curr'] as $i => $curr) {
    $prev = $ctx['prev'][$i] ?? null;
    if ($prev === null || ($prev % 12) !== $ltPc) { continue; }
    if ($curr <= $prev) { $cost += 40.0; } // stayed or went down — must resolve up
}
return $cost;
PHP,
            ],

            [
                'priority' => 46,
                'name'     => 'seventh_resolves_down',
                'source'   => 'Lambert [1707]; Heinichen [1728]; Christensen 2002, 28, 78',
                'definition' => 'The dissonant seventh invariably resolves one step downward.',
                'translation' => 'Whatever the case, the dissonant seventh invariably resolves one step downward. A voice that held a seventh above the previous bass must move down in the current chord.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. 2nd ed. Dresden, 1728 [1711]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'Whatever way the seventh is introduced, it invariably resolves one step downwards.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 28.',
                        'lang'   => 'en',
                        'text'   => 'The dissonant 7 must always be resolved. The 6 is regularly omitted following the 7.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
$prevBassPc = $ctx['bassPrev'] % 12;
$cost = 0.0;
foreach ($ctx['curr'] as $i => $curr) {
    $prev = $ctx['prev'][$i] ?? null;
    if ($prev === null) { continue; }
    $interval = ($prev % 12 - $prevBassPc + 12) % 12;
    if ($interval === 10 || $interval === 11) {
        if ($curr >= $prev) { $cost += 50.0; } // must resolve downward
    }
}
return $cost;
PHP,
            ],

            [
                'priority' => 47,
                'name'     => 'fourth_resolves_down',
                'source'   => 'Lambert [1707]; Dandrieu [1719]; Heinichen [1728]; Christensen 2002, 22–23, 71',
                'definition' => 'The suspended fourth is always resolved downward by step to the third (4–3).',
                'translation' => 'A suspended fourth occurring in any upper voice is always sustained and resolved downward by step to the third. A voice that held a fourth (5 semitones) above the previous bass must move down.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. 2nd ed. Dresden, 1728 [1711]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 11.',
                        'lang'   => 'en',
                        'text'   => 'Pay special attention to the rule that the fourth occurring in the upper or middle voice of the preceding chord is always sustained in the same voice and resolved downward to the neighboring third (4–3). In this case, voice exchange is prohibited.',
                    ],
                    [
                        'author' => 'Dandrieu, Jean-François',
                        'ref'    => 'Principes de l\'Accompagnement du Clavecin. Paris, 1719. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 13.',
                        'lang'   => 'en',
                        'text'   => 'The chord with the fourth also includes the fifth and the octave. It is a dissonant chord that very frequently occurs on the dominant, but only when it can resolve to a major third on the same bass note.',
                    ],
                    [
                        'author' => 'Lambert, Michel de',
                        'ref'    => 'Nouveau traité de l\'accompagnement du Clavecin. Paris, 1707. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 12.',
                        'lang'   => 'en',
                        'text'   => 'The 4 must be prepared by the preceding chord and resolved stepwise downward. Outside cadential progressions, the resolution may be a half step or a whole step downward.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
$prevBassPc = $ctx['bassPrev'] % 12;
$cost = 0.0;
foreach ($ctx['curr'] as $i => $curr) {
    $prev = $ctx['prev'][$i] ?? null;
    if ($prev === null) { continue; }
    $interval = ($prev % 12 - $prevBassPc + 12) % 12;
    if ($interval === 5) {
        if ($curr >= $prev) { $cost += 45.0; } // must resolve downward
    }
}
return $cost;
PHP,
            ],

            [
                'priority' => 48,
                'name'     => 'ninth_resolves_down',
                'source'   => 'Heinichen [1728]; Christensen 2002, 81–82',
                'definition' => 'The suspended ninth is always resolved downward by step to the octave.',
                'translation' => 'The suspended ninth invariably resolves downward by step to the octave. Any upper voice holding a ninth (13 or 14 semitones) above the preceding bass must move down in the next chord.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. 2nd ed. Dresden, 1728 [1711]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 11.',
                        'lang'   => 'en',
                        'text'   => 'The ninth is prepared in the preceding chord and resolved to the next octave (8). If the 8 is omitted from the figure, the ninth resolves stepwise downward on the next bass note.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
$cost = 0.0;
foreach ($ctx['curr'] as $i => $curr) {
    $prev = $ctx['prev'][$i] ?? null;
    if ($prev === null) { continue; }
    $sem = $prev - $ctx['bassPrev'];
    if ($sem === 13 || $sem === 14 || $sem === 25 || $sem === 26) {
        if ($curr >= $prev) { $cost += 45.0; }
    }
}
return $cost;
PHP,
            ],

            [
                'priority' => 49,
                'name'     => 'augmented_fifth_resolves_up',
                'source'   => 'Heinichen [1728]; Christensen 2002, 84',
                'definition' => 'The augmented fifth, when it appears in the top voice, resolves upward to the sixth.',
                'translation' => 'The augmented fifth (#5 = 8 semitones above the bass), when it appears in the soprano (top voice), must resolve upward to the sixth. It acts as a secondary leading tone.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. 2nd ed. Dresden, 1728 [1711]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 14.',
                        'lang'   => 'en',
                        'text'   => 'The augmented fifth is used in cases where it has been prepared in the preceding chord and resolves upward to the sixth. In this case, it is usually combined with the third and the octave.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
$soprano     = $ctx['curr'][2];
$sopranoPrev = $ctx['prev'][2];
$interval    = ($sopranoPrev - $ctx['bassPrev'] + 1200) % 12;
if ($interval === 8) { // augmented fifth mod 12
    if ($soprano <= $sopranoPrev) { return 30.0; } // must resolve upward
}
return 0.0;
PHP,
            ],

            [
                'priority' => 50,
                'name'     => 'no_hidden_fifths',
                'source'   => 'Christensen 2002, 18',
                'definition' => 'Hidden (direct) fifths between soprano and bass are avoided when the soprano leaps.',
                'translation' => 'Hidden fifths occur when soprano and bass move in the same direction by leap into a perfect fifth. Penalise when the soprano leaps more than a step, both voices move in the same direction, and the resulting outer-voice interval is a fifth or octave.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'Contrary motion between the hands helps to prevent parallel octaves or fifths — including those approached by similar motion (hidden fifths) — from occurring.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
$soprano     = $ctx['curr'][2];
$sopranoPrev = $ctx['prev'][2];
$sopranoLeap = abs($soprano - $sopranoPrev) > 2;
if (!$sopranoLeap) { return 0.0; }
$currOuterInt = abs($soprano - $ctx['bassCurr']) % 12;
$bothSameDir  = (($soprano - $sopranoPrev) > 0) === (($ctx['bassCurr'] - $ctx['bassPrev']) > 0);
if ($bothSameDir && ($currOuterInt === 7 || $currOuterInt === 0)) { return 30.0; }
return 0.0;
PHP,
            ],

            [
                'priority' => 60,
                'name'     => 'no_voice_crossing',
                'source'   => 'Christensen 2002, 10',
                'definition' => 'The upper voices must not cross one another.',
                'translation' => 'The tenor, alto, and soprano voices must always remain in ascending order of pitch. A voice that crosses below a lower voice incurs a heavy penalty.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'In four-voice realization the voices must remain clearly distinct: tenor below alto below soprano. Voice crossing destroys this distinction and produces faulty voice leading.',
                    ],
                ],
                'implementation' => <<<'PHP'
$cost = 0.0;
for ($i = 0; $i < count($ctx['curr']) - 1; $i++) {
    if ($ctx['curr'][$i] > $ctx['curr'][$i + 1]) { $cost += 100.0; }
}
return $cost;
PHP,
            ],

            [
                'priority' => 65,
                'name'     => 'common_tone_retention',
                'source'   => 'Delair [1690]; Dandrieu [1719]; Christensen 2002, 40, 43',
                'definition' => 'Retain common tones between consecutive chords in the same voice whenever possible.',
                'translation' => 'If a pitch-class is shared between two consecutive chords, it should be kept in the same voice rather than being transferred. Unnecessary motion away from a common tone incurs a penalty.',
                'citations' => [
                    [
                        'author' => 'Delair, Denis',
                        'ref'    => 'Traité d\'accompagnement pour le théorbe et la clavessin. Paris, [1690]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 13.',
                        'lang'   => 'en',
                        'text'   => 'In pieces in a quick tempo, it is sufficient, for those bass notes falling on the latter half of a downbeat, to strike only those pitches not found in the harmony occurring on the downbeat, thus retaining every note in the previous chord that fits the new harmony.',
                    ],
                    [
                        'author' => 'Dandrieu, Jean-François',
                        'ref'    => 'Principes de l\'Accompagnement du Clavecin. Paris, 1719. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'To connect the harmonies in the best possible manner — this being the sine qua non of perfect thoroughbass playing.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
$cost = 0.0;
$prevPcs = array_values(array_unique(array_map(fn($m) => $m % 12, array_merge($ctx['prev'], [$ctx['bassPrev']]))));
$currPcs = array_values(array_unique(array_map(fn($m) => $m % 12, array_merge($ctx['curr'], [$ctx['bassCurr']]))));
$aPrev = $prevPcs; $aCurr = $currPcs; sort($aPrev); sort($aCurr);
$sameChord = ($aPrev === $aCurr);
if ($sameChord) {
    $names = ['tenor', 'alto', 'soprano'];
    foreach ($ctx['curr'] as $i => $midi) {
        $prev = $ctx['prev'][$i] ?? null;
        if ($prev === null) { continue; }
        [$lo, $hi] = $ctx['ranges'][$names[$i]];
        if ($midi === $prev && abs($midi - ($lo + $hi) / 2) > ($hi - $lo) * 0.35) {
            $cost += 3.0;
        }
    }
    return $cost;
}
foreach ($ctx['curr'] as $i => $midi) {
    $prev = $ctx['prev'][$i] ?? null;
    if ($prev === null) { continue; }
    if (in_array($prev % 12, $currPcs, true) && ($midi % 12) !== ($prev % 12)) {
        $cost += 8.0;
    }
}
return $cost;
PHP,
            ],

            [
                'priority' => 66,
                'name'     => 'no_fourth_doubling',
                'source'   => 'Heinichen [1728]; Christensen 2002, 71',
                'definition' => 'The suspended fourth must not be doubled; only one voice may hold it at a time.',
                'translation' => 'The suspended fourth must never be doubled. Only one upper voice may hold a fourth (5 semitones) above the bass at a time, since it is a dissonance requiring a single prepared resolution.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. 2nd ed. Dresden, 1728 [1711]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 11.',
                        'lang'   => 'en',
                        'text'   => 'The fourth occurring in the upper or middle voice of the preceding chord is always sustained in the same voice and resolved downward to the neighboring third (4–3). In this case, voice exchange is prohibited.',
                    ],
                ],
                'implementation' => <<<'PHP'
$bassPc = $ctx['bassCurr'];
$count = 0;
foreach ($ctx['curr'] as $m) {
    $interval = ($m - $bassPc + 1200) % 12;
    if ($interval === 5) { $count++; } // perfect fourth = 5 semitones
}
if ($count > 1) { return 50.0 * ($count - 1); }
return 0.0;
PHP,
            ],

            [
                'priority' => 70,
                'name'     => 'prefer_stepwise_motion',
                'source'   => 'Dandrieu [1719]; Christensen 2002, 40; Wead & Knopke, ICMC 2007, §3.2',
                'definition' => 'Prefer common tones, then stepwise motion; penalize leaps according to size.',
                'translation' => 'Smooth voice leading is the primary criterion for chord position. Common tones cost nothing; semitone or whole-tone steps cost little; leaps grow increasingly expensive.',
                'citations' => [
                    [
                        'author' => 'Dandrieu, Jean-François',
                        'ref'    => 'Principes de l\'Accompagnement du Clavecin. Paris, 1719. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'To connect the harmonies in the best possible manner — this being the sine qua non of perfect thoroughbass playing.',
                    ],
                    [
                        'author' => 'Wead, Andrew, and Ian Knopke',
                        'ref'    => '"Basso Continuo Realization." In Proceedings of the International Computer Music Conference (ICMC). Copenhagen, 2007. §3.2.',
                        'lang'   => 'en',
                        'text'   => 'Prefer common tones, then stepwise motion; penalize leaps according to size.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || empty($ctx['prev'])) { return 0.0; }
$cost = 0.0;
foreach ($ctx['curr'] as $i => $midi) {
    $prev = $ctx['prev'][$i] ?? null;
    if ($prev === null) { continue; }
    $motion = abs($midi - $prev);
    // Each voice is an independent line that must move smoothly: common tones
    // and steps are nearly free, a third is idiomatic, but anything beyond a
    // fourth is a real leap and is penalised steeply (matching the editorial
    // reference, whose voices leap past a fourth only a few % of the time).
    if ($motion === 0)      { $cost -= 2.0; /* common tone — retained */ }
    elseif ($motion <= 2)   { $cost += 1.0; }
    elseif ($motion <= 4)   { $cost += 3.0; }
    elseif ($motion === 5)  { $cost += 7.0; }
    elseif ($motion <= 7)   { $cost += 16.0; }
    elseif ($motion <= 9)   { $cost += 28.0; }
    else                    { $cost += $motion * 4.0; }
}
return $cost;
PHP,
            ],

            [
                'priority' => 72,
                'name'     => 'seventh_prefer_fifth_over_octave',
                'source'   => 'Lambert [1707]; Christensen 2002, 28',
                'definition' => 'With a seventh chord, it is better to play the third and fifth than the third and octave.',
                'translation' => 'When realizing a seventh chord, it is better to include the fifth rather than the octave of the bass. If the chord contains a seventh but has an octave doubling of the bass instead of a fifth, apply a soft penalty.',
                'citations' => [
                    [
                        'author' => 'Lambert, Michel de',
                        'ref'    => 'Nouveau traité de l\'accompagnement du Clavecin. Paris, 1707. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'It is better to play the third and the fifth with the seventh rather than the third and the octave. The 3 and 8 should only be played when otherwise unavoidable.',
                    ],
                ],
                'implementation' => <<<'PHP'
$bassPc = $ctx['bassCurr'] % 12;
$allPcs = array_map(fn($m) => $m % 12, $ctx['curr']);
$hasSeventh = false;
foreach ($allPcs as $pc) {
    $iv = ($pc - $bassPc + 12) % 12;
    if ($iv === 10 || $iv === 11) { $hasSeventh = true; break; }
}
if (!$hasSeventh) { return 0.0; }
$hasFifth  = false;
$hasOctave = false;
foreach ($allPcs as $pc) {
    $iv = ($pc - $bassPc + 12) % 12;
    if ($iv === 7) { $hasFifth  = true; }
    if ($iv === 0) { $hasOctave = true; }
}
if ($hasOctave && !$hasFifth) { return 15.0; }
return 0.0;
PHP,
            ],

            [
                'priority' => 75,
                'name'     => 'contrary_motion_soprano_bass',
                'source'   => 'Lambert [1707]; Christensen 2002, 42',
                'definition' => 'The soprano and bass should move in contrary motion whenever possible.',
                'translation' => 'The Soprano and Bass should move in contrary motion whenever possible. Similar motion between outer voices is penalised.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 42.',
                        'lang'   => 'en',
                        'text'   => 'In St. Lambert\'s example of stepwise bass motion in fast triple meter, the two hands proceed in strictly contrary motion. This helps to prevent parallel octaves or fifths from occurring.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
$bassDir = $ctx['bassCurr'] - $ctx['bassPrev'];
$sopDir  = $ctx['curr'][2] - $ctx['prev'][2];
if ($bassDir === 0 || $sopDir === 0) { return 0.0; }
if (($bassDir > 0) === ($sopDir > 0)) { return 6.0; }
return 0.0;
PHP,
            ],

            [
                'priority' => 78,
                'name'     => 'seventh_sequence_alternate_fifth_octave',
                'source'   => 'Telemann [1733]; Bach [c. 1738]; Heinichen [1728]; Christensen 2002, 77–78',
                'definition' => 'In a sequence of seventh chords with bass moving up a fourth or down a fifth, alternate the fifth and the octave between consecutive chords.',
                'translation' => 'In a sequence of seventh chords where the bass moves up a fourth or down a fifth, alternate between playing the fifth (and omitting the octave) in one chord and the octave (omitting the fifth) in the next. Two consecutive seventh chords that both include the fifth cause parallel fifths; two that both omit it sound thin.',
                'citations' => [
                    [
                        'author' => 'Telemann, Georg Philipp',
                        'ref'    => 'Singe-, Spiel- und General-Bass-Übungen. Hamburg, [1733]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'To play a series of consecutive seventh chords in four voices, one should alternately play a fifth in one chord and omit it in the next.',
                    ],
                    [
                        'author' => 'Bach, Johann Sebastian',
                        'ref'    => 'Vorschriften und Grundsätze zum vierstimmigen Spielen des General-Bass. [c. 1738]. Translated in Christensen, 18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'Play the first seventh chord with the fifth or the octave. Then, if the first chord uses the fifth, play the second with the octave, and vice versa.',
                    ],
                ],
                'implementation' => <<<'PHP'
if ($ctx['isStart'] || count($ctx['prev']) < 3) { return 0.0; }
// Only applies when a 7th was present in the previous chord
$prevBassPc = $ctx['bassPrev'] % 12;
$hasPrevSeventh = false;
foreach ($ctx['prev'] as $m) {
    $iv = ($m % 12 - $prevBassPc + 12) % 12;
    if ($iv === 10 || $iv === 11) { $hasPrevSeventh = true; break; }
}
if (!$hasPrevSeventh) { return 0.0; }
// Check if current chord also has a seventh
$currBassPc = $ctx['bassCurr'] % 12;
$hasCurrSeventh = false;
foreach ($ctx['curr'] as $m) {
    $iv = ($m % 12 - $currBassPc + 12) % 12;
    if ($iv === 10 || $iv === 11) { $hasCurrSeventh = true; break; }
}
if (!$hasCurrSeventh) { return 0.0; }
// Bass motion: up a 4th (5 semitones) or down a 5th (7 semitones)
$bassMotion = ($ctx['bassCurr'] - $ctx['bassPrev'] + 12) % 12;
if ($bassMotion !== 5 && $bassMotion !== 7) { return 0.0; }
// Check if both chords have a fifth — that causes parallel fifths → penalise
$prevHasFifth = false; $currHasFifth = false;
foreach ($ctx['prev'] as $m) { if (($m % 12 - $prevBassPc + 12) % 12 === 7) { $prevHasFifth = true; break; } }
foreach ($ctx['curr'] as $m) { if (($m % 12 - $currBassPc + 12) % 12 === 7) { $currHasFifth = true; break; } }
if ($prevHasFifth && $currHasFifth) { return 20.0; } // both have fifth → likely parallel fifths
return 0.0;
PHP,
            ],
        ];
    }
}
