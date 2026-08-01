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
                    ->setCitations($data['citations'])
                    ->setEnabled($data['enabled'] ?? true);
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
                ->setEnabled($data['enabled'] ?? true);

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
                'source'   => 'St. Lambert [1707]; Christensen 2002, 40',
                'definition' => 'The upper voice must never go beyond e\'\' or f\'\'; the lower limit is normally d\'.',
                'translation' => 'Each upper voice must stay within its assigned MIDI range (soprano: D4–E5; alto: A3–C5; tenor: G3–A4). Notes outside these bounds incur a penalty proportional to the distance.',
                'citations' => [
                    [
                        'author' => 'Saint-Lambert, Michel de',
                        'ref'    => 'Nouveau Traité de l\'Accompagnement du Clavecin. Paris, 1707. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 40.',
                        'lang'   => 'en',
                        'text'   => 'the upper voice of the chordal accompaniment must never go beyond e" or f" except when the bass moves into the alto register, in which case all the notes become very high.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 40.',
                        'lang'   => 'en',
                        'text'   => 'The ambitus seldom goes above c" or d". The lower limit normally lies around d\', with c\' and b representing exceptional cases.',
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
                        'text'   => 'The ambitus seldom goes above c" or d". The lower limit normally lies around d\', with c\' and b representing exceptional cases.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 100.',
                        'lang'   => 'en',
                        'text'   => 'The lowest register Telemann uses is normally g\' (for the top voice of the chord). Very rarely we also find f#\'.',
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
                        'author'         => 'Gasparini, Francesco',
                        'ref'            => 'L\'Armonico Pratico al Cimbalo. Quarta impressione. Bologna: Giuseppe Antonio Silvani, 1722, 11.',
                        'lang'           => 'it',
                        'text'           => 'Per accompagnar ogni nota, e formarla di perfetta Armonia, è necessario darle la Terza, Quinta, e Ottava; e questo serva di regola generale, e infallibile, se non si averà la certezza, che la nota richieda o Sesta, o altri accompagnamenti accidentali di Dissonanze, come si vedrà a suo luogo.',
                        'translation'    => 'Pour accompagner chaque note et la former en harmonie parfaite, il est nécessaire de lui donner la tierce, la quinte et l\'octave ; et que cela serve de règle générale et infaillible, à moins d\'avoir la certitude que la note demande une sixte, ou d\'autres accompagnements accidentels de dissonances, comme on le verra en son lieu.',
                        'translation_by' => 'traduction non éditoriale',
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
                'source'   => 'St. Lambert [1707]; Christensen 2002, 40',
                'definition' => 'The soprano must never go beyond E5 (e\'\').',
                'translation' => 'The soprano (top voice of the right hand) must not exceed E5 (MIDI 76). Notes above this limit incur a penalty proportional to the excess.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 40.',
                        'lang'   => 'en',
                        'text'   => 'Indeed, e" is the normal upper limit.',
                    ],
                    [
                        'author' => 'Saint-Lambert, Michel de',
                        'ref'    => 'Nouveau Traité de l\'Accompagnement du Clavecin. Paris, 1707. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 40.',
                        'lang'   => 'en',
                        'text'   => 'the upper voice of the chordal accompaniment must never go beyond e" or f" except when the bass moves into the alto register, in which case all the notes become very high.',
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
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 82.',
                        'lang'   => 'en',
                        'text'   => 'Because such consecutive dissonances are resolved downward, it frequently happens that the register of the right hand becomes too low, leaving no room to resolve [the dissonances] and impeding the movement of the left hand.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 8.',
                        'lang'   => 'en',
                        'text'   => 'practice the four-voice realizations—the bedrock of all thoroughbass playing—so carefully that it becomes almost second nature',
                    ],
                    [
                        'author' => 'Delair, Denis',
                        'ref'    => 'Traité d\'accompagnement pour le théorbe et le clavessin. Paris, 1690, 59 (fac-similé Minkoff, Genève, 1972). Transcription diplomatique ; u/v et i/j normalisés.',
                        'lang'   => 'fr',
                        'text'   => 'On remarquera qu\'à proportion que la basse monte dans la suitte, on doit prendre les accords de la main droite dans un éloignement convenable, prenant les accords au dessus de l\'étendue des notes de la basse qui suivent immediatement, afin de n\'estre pas obligé de monter les accords du dessus conjointement avec la basse, ce qui ne se pourroit faire sans embarasser les mains par la proximité où elles se trouveroient et sans faire deux quintes, ou deux octaves.',
                    ],
                    [
                        'author' => 'Delair, Denis',
                        'ref'    => 'Traité d\'accompagnement pour le théorbe et le clavessin. Paris, 1690, 59 (fac-similé Minkoff, Genève, 1972). Transcription diplomatique ; u/v et i/j normalisés.',
                        'lang'   => 'fr',
                        'text'   => 'mais lors que la basse décend de plusieurs notes, on doit prendre les accords de la main droite le plus pres de la basse que l\'on pourra, afin d\'avoir la liberté de monter ensuitte si l\'on s\'y trouve obligé sans qu\'il se rencontre un trop grand Intervale entre les deux mains, ce qui se doit éviter.',
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
                'source'   => 'St. Lambert [1707]; Christensen 2002, 18, 28',
                'definition' => 'Parallel fifths between any two voices moving in the same direction are forbidden.',
                'translation' => 'When any two voices both move and the interval between them is a fifth (7 semitones mod 12) both before and after the motion, a parallel fifth results. Each such pair incurs a heavy penalty.',
                'citations' => [
                    [
                        'author' => 'Saint-Lambert, Michel de',
                        'ref'    => 'Nouveau Traité de l\'Accompagnement du Clavecin. Paris, 1707. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'When playing a figured bass, it is important to observe a few rules for the movement of the two hands. The hands must always move in contrary motion. In other words, when the bass rises, the accompaniment [in the right hand] must descend, and vice versa. This will prevent any voice from forming consecutive octaves or fifths with the bass, which is strictly prohibited.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'Strict contrary motion must always be applied when a bass harmonized in root-position chords (as in the preceding example) proceeds in stepwise motion within the diatonic scale (mm. 3–4). Otherwise, the result will be parallel fifths and octaves at once.',
                    ],
                    [
                        'author' => 'Saint-Lambert, Michel de',
                        'ref'    => 'Nouveau Traité de l\'Accompagnement du Clavecin. Paris, 1707. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'Never play two consecutive sixth chords with doubled voices unless the doubled notes remain the same.',
                    ],
                    [
                        'author'         => 'Gasparini, Francesco',
                        'ref'            => 'L\'Armonico Pratico al Cimbalo. Quarta impressione. Bologna: Giuseppe Antonio Silvani, 1722, 13.',
                        'lang'           => 'it',
                        'text'           => 'Ne si considerino per adesso le Ottave, o Quinte, che si proibiscono una appresso l\'altra per l\'istesso moto, cioè due Quinte, e due Ottave, che a suo luogo si dirà il modo di fugir, o salvar simili errori.',
                        'translation'    => 'Que l\'on ne considère pas pour l\'instant les octaves ou les quintes, qui sont interdites l\'une après l\'autre par le même mouvement, c\'est-à-dire deux quintes et deux octaves ; on dira en son lieu la manière de fuir ou de sauver de semblables fautes.',
                        'translation_by' => 'traduction non éditoriale',
                    ],
                    [
                        'author' => 'Delair, Denis',
                        'ref'    => 'Traité d\'accompagnement pour le théorbe et le clavessin. Paris, 1690, 44 (fac-similé Minkoff, Genève, 1972). Transcription diplomatique ; u/v et i/j normalisés.',
                        'lang'   => 'fr',
                        'text'   => 'il n\'y a pas d\'autre raison que le defaut de varieté, ou de modulation, qui fait que deux quintes, et deux octaves de suite, sont défendües, quand on observera que deux quintes renversées, ou de mouvement contraire, sont permises aussi bien que deux quintes de diferentes especes, c\'est à dire une juste, et une fausse, par ce que, en ces deux manieres, il y à modulation et varieté.',
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
                'source'   => 'St. Lambert [1707]; Heinichen [1728]; Christensen 2002, 18, 65',
                'definition' => 'The leading tone must never be doubled in any pair of voices.',
                'translation' => 'The leading tone (major seventh of the scale, one semitone below the tonic) must appear in at most one voice. Doubling it creates an obligatory parallel motion to the tonic, which causes parallel octaves.',
                'citations' => [
                    [
                        'author' => 'Saint-Lambert, Michel de',
                        'ref'    => 'Nouveau Traité de l\'Accompagnement du Clavecin. Paris, 1707. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'When the bass ascends from the leading tone to the tonic, the third or the sixth must be doubled.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'This rule implies that you must never double a leading tone in the bass.',
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
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 65.',
                        'lang'   => 'en',
                        'text'   => 'Finally, never double a chromatically altered note that functions as a leading tone. This rule is extremely important.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 65.',
                        'lang'   => 'en',
                        'text'   => 'The following realization is correct, the note in the bass not being a leading tone (observe Heinichen\'s elegant voice leading).',
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
                'source'   => 'St. Lambert [1707]; Heinichen [1728]; Christensen 2002, 28, 76',
                'definition' => 'The dissonant seventh must not be doubled in any pair of voices.',
                'translation' => 'The dissonant seventh of a chord (the pitch a minor or major seventh above the bass) must never be doubled in any pair of voices.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 76.',
                        'lang'   => 'en',
                        'text'   => 'Often this seventh is followed immediately by its resolution (7-6). The other voices that are normally combined with it are the third and the octave. Instead of playing the octave, it is also possible to double the third, unless one is content with a three-voice chord.',
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
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 81.',
                        'lang'   => 'en',
                        'text'   => 'The other notes in the chord are usually the third and the fifth.',
                    ],
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 81.',
                        'lang'   => 'en',
                        'text'   => 'The ninth is generally played along with the fourth, seventh, or both. Each of these additional dissonances is handled in accordance with the usual rules of voice leading.',
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
                'source'   => 'St. Lambert [1707]; Christensen 2002, 18, 42',
                'definition' => 'Parallel octaves between any two voices moving in the same direction are forbidden.',
                'translation' => 'When any two voices both move and the interval between them is an octave (0 semitones mod 12) both before and after the motion, a parallel octave results. Each such pair incurs a heavy penalty.',
                'citations' => [
                    [
                        'author' => 'Saint-Lambert, Michel de',
                        'ref'    => 'Nouveau Traité de l\'Accompagnement du Clavecin. Paris, 1707. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'When playing a figured bass, it is important to observe a few rules for the movement of the two hands. The hands must always move in contrary motion. In other words, when the bass rises, the accompaniment [in the right hand] must descend, and vice versa. This will prevent any voice from forming consecutive octaves or fifths with the bass, which is strictly prohibited.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 18.',
                        'lang'   => 'en',
                        'text'   => 'two consecutive doubled sixth chords would produce parallel fifths and parallel octaves at once',
                    ],
                    [
                        'author'         => 'Gasparini, Francesco',
                        'ref'            => 'L\'Armonico Pratico al Cimbalo. Quarta impressione. Bologna: Giuseppe Antonio Silvani, 1722, 13.',
                        'lang'           => 'it',
                        'text'           => 'Ne si considerino per adesso le Ottave, o Quinte, che si proibiscono una appresso l\'altra per l\'istesso moto, cioè due Quinte, e due Ottave, che a suo luogo si dirà il modo di fugir, o salvar simili errori.',
                        'translation'    => 'Que l\'on ne considère pas pour l\'instant les octaves ou les quintes, qui sont interdites l\'une après l\'autre par le même mouvement, c\'est-à-dire deux quintes et deux octaves ; on dira en son lieu la manière de fuir ou de sauver de semblables fautes.',
                        'translation_by' => 'traduction non éditoriale',
                    ],
                    [
                        'author' => 'Delair, Denis',
                        'ref'    => 'Traité d\'accompagnement pour le théorbe et le clavessin. Paris, 1690, 59 (fac-similé Minkoff, Genève, 1972). Transcription diplomatique ; u/v et i/j normalisés.',
                        'lang'   => 'fr',
                        'text'   => 'Si la basse monte en sorte que l\'on soit obligé de monter aussi les accords, il faut faire en sorte de dégager la main droite sur une note où l\'on doit faire la sexte, d\'autant que pour lors se passant d\'octave, et ne faisant que la tierce, et la sexte, doublées tant que l\'on voudra, on evitera les deux quintes et les deux octaves.',
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
                'source'   => 'St. Lambert [1707]; Christensen 2002, 18, 36',
                'definition' => 'The leading tone must resolve upward to the tonic.',
                'translation' => 'Whatever voice holds a leading tone (major 7th of the scale) must move upward — to the tonic — in the following chord. A voice that stayed on or descended from the leading tone incurs a penalty.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 36.',
                        'lang'   => 'en',
                        'text'   => 'Since the #5 assumes the function of a leading tone, it should resolve upwards when played in the top voice, as shown in Dandrieu\'s example at the bottom of page 35.',
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
                'source'   => 'St. Lambert [1707]; Heinichen [1728]; Christensen 2002, 28, 78',
                'definition' => 'The dissonant seventh invariably resolves one step downward.',
                'translation' => 'Whatever the case, the dissonant seventh invariably resolves one step downward. A voice that held a seventh above the previous bass must move down in the current chord.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 77.',
                        'lang'   => 'en',
                        'text'   => 'When the 7 appears in isolation above a [bass] note, that is, without the 6, it is not resolved until the next bass note, where it descends a half-step or a whole step. In such cases, the seventh is combined with the third and the fifth.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 78.',
                        'lang'   => 'en',
                        'text'   => 'Instead of being tied over from the preceding chord, the seventh can also be introduced stepwise from above or below, and occasionally even by a small leap. Whatever the case, it invariably resolves one step downwards.',
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
                'source'   => 'St. Lambert [1707]; Dandrieu [1719]; Heinichen [1728]; Christensen 2002, 22–23, 71',
                'definition' => 'The suspended fourth is always resolved downward by step to the third (4–3).',
                'translation' => 'A suspended fourth occurring in any upper voice is always sustained and resolved downward by step to the third. A voice that held a fourth (5 semitones) above the previous bass must move down.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 71.',
                        'lang'   => 'en',
                        'text'   => 'Pay special attention to the rule that the fourth occurring in the upper or middle voice of the preceding chord is always sustained in the same voice and resolved downward to the neighboring third (4-3). In this case, voice exchange is prohibited. The fourth is usually combined with the fifth and the octave.',
                    ],
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 71.',
                        'lang'   => 'en',
                        'text'   => 'If only the 4 is written on a note [i.e., if the bass continues without waiting for the resolution], it is resolved on the next bass note in accordance with the rule and without voice exchange – that is, one step downward.',
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
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 81.',
                        'lang'   => 'en',
                        'text'   => 'The ninth (9) is prepared in the preceding chord and resolved to the next octave (8). If the 8 is omitted from the figure, the ninth resolves stepwise downward on the next [bass] note. The other notes in the chord are usually the third and the fifth.',
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
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 84.',
                        'lang'   => 'en',
                        'text'   => 'in cases where it has been prepared in the preceding chord and resolves upward to the sixth. In this case, the augmented fifth is usually combined with the third and the octave. However, it is frequently joined by the fourth, seventh, and ninth, the entire preceding chord being tied over into the next.',
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
                'citations' => [],
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
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 71.',
                        'lang'   => 'en',
                        'text'   => 'Pay special attention to the rule that the fourth occurring in the upper or middle voice of the preceding chord is always sustained in the same voice and resolved downward to the neighboring third (4-3). In this case, voice exchange is prohibited.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 77.',
                        'lang'   => 'en',
                        'text'   => 'This makes it perfectly clear that Heinichen, unlike Dandrieu and, with a certain caveat, St. Lambert (Chapter I, sec. 10), does not condone free voice-exchange in the middle voices.',
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
                        'author' => 'Saint-Lambert, Michel de',
                        'ref'    => 'Nouveau Traité de l\'Accompagnement du Clavecin. Paris, 1707. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 12.',
                        'lang'   => 'en',
                        'text'   => 'to play all adjacent chords as close to each other as possible. Always check to see whether some of the notes in the previous chord may be retained in the next one. If so, leave them unchanged.',
                    ],
                    [
                        'author'         => 'Gasparini, Francesco',
                        'ref'            => 'L\'Armonico Pratico al Cimbalo. Quarta impressione. Bologna: Giuseppe Antonio Silvani, 1722, 12.',
                        'lang'           => 'it',
                        'text'           => 'Movendosi da una nota all\'altra si deve osservare di scomodar la mano destra meno, che sia possibile; mentre non si dà movimento di Basso, dove trà gli accompagnamenti alcun tasto non possa restar fermo, ed altri partir solamente di grado, o salendo, o descendendo.',
                        'translation'    => 'En passant d\'une note à l\'autre, on doit veiller à déranger la main droite le moins possible ; car il n\'est pas de mouvement de basse où, parmi les accompagnements, quelque touche ne puisse rester en place, les autres ne se déplaçant que par degré, en montant ou en descendant.',
                        'translation_by' => 'traduction non éditoriale',
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
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 71.',
                        'lang'   => 'en',
                        'text'   => 'The fourth is usually combined with the fifth and the octave.',
                    ],
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 71.',
                        'lang'   => 'en',
                        'text'   => 'The fourth may also be combined with the sixth instead of the fifth. In this case, it is not necessarily tied over from the previous chord, but it is resolved downward as usual.',
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
                        'author'         => 'Gasparini, Francesco',
                        'ref'            => 'L\'Armonico Pratico al Cimbalo. Quarta impressione. Bologna: Giuseppe Antonio Silvani, 1722, 12.',
                        'lang'           => 'it',
                        'text'           => 'Movendosi da una nota all\'altra si deve osservare di scomodar la mano destra meno, che sia possibile; mentre non si dà movimento di Basso, dove trà gli accompagnamenti alcun tasto non possa restar fermo, ed altri partir solamente di grado, o salendo, o descendendo.',
                        'translation'    => 'En passant d\'une note à l\'autre, on doit veiller à déranger la main droite le moins possible ; car il n\'est pas de mouvement de basse où, parmi les accompagnements, quelque touche ne puisse rester en place, les autres ne se déplaçant que par degré, en montant ou en descendant.',
                        'translation_by' => 'traduction non éditoriale',
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
                'source'   => 'St. Lambert [1707]; Christensen 2002, 28',
                'definition' => 'With a seventh chord, it is better to play the third and fifth than the third and octave.',
                'translation' => 'When realizing a seventh chord, it is better to include the fifth rather than the octave of the bass. If the chord contains a seventh but has an octave doubling of the bass instead of a fifth, apply a soft penalty.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 77.',
                        'lang'   => 'en',
                        'text'   => 'In some cases the fifth cannot be played without creating a poor progression or an inadmissible exchange of voices. For these situations the octave is played instead of the fifth.',
                    ],
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 77.',
                        'lang'   => 'en',
                        'text'   => 'As it is fairly difficult to judge these conditions, we recommend that beginners avoid using the fifth in the 7-6 progression.',
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
                'source'   => 'St. Lambert [1707]; Christensen 2002, 42',
                'definition' => 'The soprano and bass should move in contrary motion whenever possible.',
                'translation' => 'The Soprano and Bass should move in contrary motion whenever possible. Similar motion between outer voices is penalised.',
                'citations' => [
                    [
                        'author' => 'Saint-Lambert, Michel de',
                        'ref'    => 'Nouveau Traité de l\'Accompagnement du Clavecin. Paris, 1707. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'The hands must always move in contrary motion. In other words, when the bass rises, the accompaniment [in the right hand] must descend, and vice versa.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                        'lang'   => 'en',
                        'text'   => 'As St. Lambert\'s own example shows (see above), the principle of contrary motion cannot always be strictly applied. Nevertheless, it remains the single most important basic rule of figured bass playing.',
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
                        'author' => 'Bach, Johann Sebastian',
                        'ref'    => 'Vorschriften und Grundsätze zum vierstimmigen Spielen des General-Bass. [c. 1738]. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 77.',
                        'lang'   => 'en',
                        'text'   => 'Play the first seventh chord with the fifth or the octave. Then, if the first chord uses the fifth, play the second with the octave, and vice versa.',
                    ],
                    [
                        'author' => 'Telemann, Georg Philipp',
                        'ref'    => 'Singe-, Spiel- und General-Bass-Übungen. Hamburg, 1733–4. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 78.',
                        'lang'   => 'en',
                        'text'   => 'To play a series of consecutive seventh chords in four voices, one should alternately play a fifth in one chord and omit it in the next.',
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
            [
                'priority' => 200,
                'name'     => 'passing_notes_unharmonized',
                'enabled'  => false,
                'source'   => 'Christensen 2002, 42 (résumant St. Lambert et Delair)',
                'definition' => 'Sur une basse conjointe, seules les notes des temps forts sont harmonisées ; les notes intermédiaires sont traitées comme notes de passage — sauf si elles portent un chiffrage.',
                'translation' => 'Documentaire : le moteur harmonise aujourd\'hui chaque note de basse. Appliquer cette règle demanderait de connaître la métrique et la position de la note dans la mesure.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 42.',
                        'lang'   => 'en',
                        'text'   => 'Whenever the bass proceeds in stepwise motion, it suffices to harmonize the notes that fall on the main beats of the bar and to treat the notes between them as passing notes. If the unaccentuated notes have bass figures, they should be harmonized in the usual way, even if the bass should suddenly proceed in larger intervals instead of stepwise (St. Lambert).',
                    ],
                ],
                // Documentary rule: recorded for reference, never executed
                // (enabled = false). The stub keeps it harmless if switched on.
                'implementation' => <<<'PHP'
return 0.0;
PHP,
            ],
            [
                'priority' => 201,
                'name'     => 'one_chord_per_bar_fast_triple',
                'enabled'  => false,
                'source'   => 'Christensen 2002, 42 (St. Lambert ; Delair)',
                'definition' => 'Dans une mesure ternaire rapide, un seul accord par mesure suffit, dès lors que la basse est conjointe ou se meut à l\'intérieur de l\'harmonie.',
                'translation' => 'Documentaire : suppose la connaissance du tempo et du chiffre de mesure, absents du contexte transmis aux règles.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 42.',
                        'lang'   => 'en',
                        'text'   => 'In a quick triple meter, it even suffices to play one chord per bar, again assuming that the bass line is stepwise.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 42.',
                        'lang'   => 'en',
                        'text'   => 'It also suffices to play one chord per bar in fast triple meter when the bass intervals move inside the harmony (Delair).',
                    ],
                ],
                // Documentary rule: recorded for reference, never executed
                // (enabled = false). The stub keeps it harmless if switched on.
                'implementation' => <<<'PHP'
return 0.0;
PHP,
            ],
            [
                'priority' => 202,
                'name'     => 'triple_meter_beats_one_and_three',
                'enabled'  => false,
                'source'   => 'Christensen 2002, 43',
                'definition' => 'Dans une mesure ternaire, on ne réalise normalement que les premier et troisième temps.',
                'translation' => 'Documentaire : suppose la position métrique de la note.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 43.',
                        'lang'   => 'en',
                        'text'   => 'Normally, only the first and third beats of a triple-meter bar are realized.',
                    ],
                ],
                // Documentary rule: recorded for reference, never executed
                // (enabled = false). The stub keeps it harmless if switched on.
                'implementation' => <<<'PHP'
return 0.0;
PHP,
            ],
            [
                'priority' => 203,
                'name'     => 'no_tie_across_barline',
                'enabled'  => false,
                'source'   => 'Christensen 2002, 43',
                'definition' => 'Les accompagnateurs du XVIIIe siècle ne liaient jamais une note par-dessus la barre de mesure, d\'un temps faible vers un temps fort.',
                'translation' => 'Documentaire : le moteur ne produit pas de liaisons entre accords.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 43.',
                        'lang'   => 'en',
                        'text'   => 'It should be expressly emphasized that eighteenth-century thoroughbass players never tied notes over the bar line from an upbeat to a downbeat.',
                    ],
                ],
                // Documentary rule: recorded for reference, never executed
                // (enabled = false). The stub keeps it harmless if switched on.
                'implementation' => <<<'PHP'
return 0.0;
PHP,
            ],
            [
                'priority' => 204,
                'name'     => 'hold_chord_on_dash',
                'enabled'  => false,
                'source'   => 'Christensen 2002, 43 (d\'après Dandrieu)',
                'definition' => 'Le tiret d\'un chiffrage prolonge l\'harmonie : la main droite tient l\'accord sans le rejouer. Règle empirique, que la conduite des voix peut contredire.',
                'translation' => 'Documentaire : le moteur ne lit pas les tirets de prolongation.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 43.',
                        'lang'   => 'en',
                        'text'   => 'According to Dandrieu, the dash also meant that the right hand must hold the chord without repeating it. His remark should, however, only be regarded as a rule of thumb. Sometimes the voice leading makes it unavoidable to change the position of a chord, e.g., to avoid parallel octaves or fifths or a poor harmonic progression.',
                    ],
                ],
                // Documentary rule: recorded for reference, never executed
                // (enabled = false). The stub keeps it harmless if switched on.
                'implementation' => <<<'PHP'
return 0.0;
PHP,
            ],
            [
                'priority' => 205,
                'name'     => 'retain_common_pitches_on_weak_half_beat',
                'enabled'  => false,
                'source'   => 'Christensen 2002, 43 (Delair)',
                'definition' => 'Dans un tempo vif, sur les notes de basse tombant dans la seconde moitié d\'un temps fort, on ne frappe que les sons absents de l\'accord précédent, gardant tout ce qui convient encore.',
                'translation' => 'Documentaire : Christensen précise que seul Delair décrit cette pratique et qu\'on ne peut se prononcer sur sa validité générale.',
                'citations' => [
                    [
                        'author' => 'Delair, Denis',
                        'ref'    => 'Traité d\'accompagnement pour le théorbe et le clavessin. Paris, 1690. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 43.',
                        'lang'   => 'en',
                        'text'   => 'In pieces in a quick tempo, it is sufficient, for those [bass] notes falling on the latter half of a downbeat, to strike only those pitches not found in the harmony occurring on the downbeat, thus retaining every note in the previous chord that fits the new harmony.',
                    ],
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 43.',
                        'lang'   => 'en',
                        'text'   => 'As only Delair describes this practice, we can make no pronouncements as to its universal validity.',
                    ],
                ],
                // Documentary rule: recorded for reference, never executed
                // (enabled = false). The stub keeps it harmless if switched on.
                'implementation' => <<<'PHP'
return 0.0;
PHP,
            ],
            [
                'priority' => 206,
                'name'     => 'bass_octave_doubling',
                'enabled'  => false,
                'source'   => 'Christensen 2002, 132 (Heinichen)',
                'definition' => 'La main gauche peut doubler la basse à l\'octave d\'un bout à l\'autre, sauf si le tempo est trop rapide.',
                'translation' => 'Documentaire : le moteur ne connaît ni le tempo ni la main gauche, qu\'il écrit à une seule voix.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 132.',
                        'lang'   => 'en',
                        'text'   => 'The latter voice was, however, granted the liberty of playing the bass in octaves throughout, unless prevented from doing so by an excessively fast tempo.',
                    ],
                ],
                // Documentary rule: recorded for reference, never executed
                // (enabled = false). The stub keeps it harmless if switched on.
                'implementation' => <<<'PHP'
return 0.0;
PHP,
            ],
            [
                'priority' => 207,
                'name'     => 'texture_richer_on_harpsichord',
                'enabled'  => false,
                'source'   => 'Christensen 2002, 132 (Heinichen)',
                'definition' => 'Au clavecin, plus la texture est fournie, plus le son est harmonieux ; à l\'orgue, on évite le plein jeu dans la musique douce et hors des tutti.',
                'translation' => 'Documentaire : le moteur ne sait pas sur quel instrument la réalisation sera jouée.',
                'citations' => [
                    [
                        'author' => 'Heinichen, Johann David',
                        'ref'    => 'Der General-Bass in der Composition. Dresden, 1728. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 132.',
                        'lang'   => 'en',
                        'text'   => 'The richer the texture with which one accompanies with both hands on the harpsichord, the more harmonious the sound. On the other hand, one should not become too enamored of full-voiced realizations on the organ, least of all in soft music and outside of tutti passages.',
                    ],
                ],
                // Documentary rule: recorded for reference, never executed
                // (enabled = false). The stub keeps it harmless if switched on.
                'implementation' => <<<'PHP'
return 0.0;
PHP,
            ],
            [
                'priority' => 208,
                'name'     => 'full_doubling_licence_harpsichord',
                'enabled'  => false,
                'source'   => 'Christensen 2002, 134 (Muffat)',
                'definition' => 'Au clavecin, on double parfois toutes les notes de l\'accord pour remplir la texture, bien que cela soit contraire aux règles.',
                'translation' => 'Documentaire : licence d\'exécution, non un critère de choix de voix.',
                'citations' => [
                    [
                        'author' => 'Muffat, Georg',
                        'ref'    => 'Regulae Concentuum Partiturae. 1699. Quoted in translation in Christensen, 18th-Century Continuo Playing. Kassel: Bärenreiter, 2002, 134.',
                        'lang'   => 'en',
                        'text'   => 'At times one doubles everything on the instrument [i.e. the harpsichord] in order to fill in [the parts], although it is against the rules.',
                    ],
                ],
                // Documentary rule: recorded for reference, never executed
                // (enabled = false). The stub keeps it harmless if switched on.
                'implementation' => <<<'PHP'
return 0.0;
PHP,
            ],
            [
                'priority' => 209,
                'name'     => 'limit_consecutive_three_voice_chords',
                'enabled'  => false,
                'source'   => 'Christensen 2002, 40',
                'definition' => 'On peut réduire l\'accord à trois voix quand une basse aiguë rencontre un passage grave de la mélodie, mais jamais plus de trois ou quatre accords de suite.',
                'translation' => 'Documentaire : demanderait au moteur de compter les accords à trois voix consécutifs, ce qu\'il ne fait pas.',
                'citations' => [
                    [
                        'author' => 'Christensen, Jesper Bøje',
                        'ref'    => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 40.',
                        'lang'   => 'en',
                        'text'   => 'If some high notes in the bass happen to coincide with a low passage of the melody, reduce the number of voices in the chords to three. However, as we have already seen in Dandrieu\'s examples (sections 6 and 8), you should never play more than three or four such chords in a row.',
                    ],
                ],
                // Documentary rule: recorded for reference, never executed
                // (enabled = false). The stub keeps it harmless if switched on.
                'implementation' => <<<'PHP'
return 0.0;
PHP,
            ],
        ];
    }
}
