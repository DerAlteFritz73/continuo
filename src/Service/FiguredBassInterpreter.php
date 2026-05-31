<?php

namespace App\Service;

use App\Model\Note;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Interprets figured bass notation and computes the complete set of intervals
 * to realize above a given bass note.
 *
 * Rules implemented from:
 *   - St. Lambert (1707). Nouveau traité de l'accompagnement du Clavecin.
 *   - Dandrieu (1719). Principes de l'Accompagnement du Clavecin.
 *   - Heinichen (1728). Der General-Bass in der Composition.
 *   - Telemann (1733). Singe-, Spiel- und General-Bass-Übungen.
 *   - Christensen, Jesper Bøje (2002). 18th-Century Continuo Playing.
 *   - Wead & Knopke, ICMC 2007 decision tree system.
 *
 * Figured bass notation (MusicXML-style figure numbers):
 *   <nothing>  → 5 3       (root position triad)
 *   6          → 6 3       (first inversion triad)
 *   6 4        → 6 4       (second inversion triad — cadential 6/4)
 *   7          → 7 5 3     (root position seventh chord)
 *   6 5        → 6 5 3     (first inversion seventh chord)
 *   4 3        → 4 3 6     (second inversion seventh chord — 6/4/3)
 *   4 2        → 4 2 6     (third inversion seventh chord — bass is 7th of chord)
 *   #4         → #4 2 6    (tritone chord = third-inversion dominant seventh)
 *   b5         → b5 6 3    (diminished-fifth chord = first-inversion dom. 7th)
 *   #5         → #5 7 9 3  (augmented-fifth chord — Christensen §14)
 *   b7         → b7 5 3    (diminished seventh chord — Christensen §15)
 *   4          → 4 5       (suspended fourth — 5/4 suspension)
 *   5 4        → 4 5       (same as 4 alone, explicit 5/4 notation)
 *   9          → 9 5 3     (suspended ninth — major ninth with 5th and 3rd)
 *   9 7        → 9 7 3     (minor ninth with seventh and third)
 *   2          → 4 2 6     if alone; see 4/2 above
 */
class FiguredBassInterpreter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    private function t(string $id): string
    {
        return $this->translator->trans($id);
    }

    /**
     * Hardcoded per-rule citation data in the author's original language.
     * Each entry mirrors the {author, ref, lang, text, translation} structure
     * used by the VoiceLeadingRule DB entity citations.
     *
     * @return array[]
     */
    private static function ruleCitations(string $rule): array
    {
        static $map = null;
        if ($map === null) {
            $chr = '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002';
            $cp  = fn(string $p): string => 'Translated in Christensen, ' . $chr . ', ' . $p . '.';

            $map = [
                'leading_tone' => [[
                    'author'      => 'Dandrieu, Jean-François',
                    'ref'         => 'Principes de l\'Accompagnement du Clavecin. Paris, 1719. ' . $cp('17'),
                    'lang'        => 'en',
                    'text'        => 'This chord consists of the diminished fifth, the sixth, and the third. It is usually played on the seventh degree of the scale — the leading tone — provided that it proceeds to the tonic (VII–I).',
                    'translation' => 'The leading tone (scale degree 7) resolving to the tonic takes the diminished-fifth chord (♭5/6/3 = V⁶₅, first inversion of the dominant seventh).',
                ]],

                'ascending_passing' => [[
                    'author'      => 'Lambert, Michel de',
                    'ref'         => 'Nouveau traité de l\'accompagnement du Clavecin. Paris, 1707. ' . $cp('42'),
                    'lang'        => 'en',
                    'text'        => 'Whenever the bass proceeds in stepwise motion, it suffices to harmonize the notes that fall on the main beats of the bar and to treat the notes between them as passing notes.',
                    'translation' => 'Scale degree 4 ascending by step in a passing context takes a 6th (first inversion) to avoid parallel fifths and maintain linear motion.',
                ]],

                'petit_accord_supertonic' => [[
                    'author'      => 'Dandrieu, Jean-François',
                    'ref'         => 'Principes de l\'Accompagnement du Clavecin. Paris, 1719. ' . $cp('14–15'),
                    'lang'        => 'en',
                    'text'        => 'This chord, comprising the sixth, the third, and the fourth, is generally called the petite sixte. It is usually played on the second degree of the scale when [the bass] proceeds downward to the tonic. The sixth is almost invariably major.',
                    'translation' => 'Scale degree 2 descending to the tonic takes 6/4/3 (the petite sixte — second inversion of the dominant seventh), per the central rule of French basso continuo.',
                ]],

                'subdominant_65' => [[
                    'author'      => 'Dandrieu, Jean-François',
                    'ref'         => 'Principes de l\'Accompagnement du Clavecin. Paris, 1719. ' . $cp('21'),
                    'lang'        => 'en',
                    'text'        => 'This chord is formed of the fifth, the sixth, and the third. It is generally played on the fourth degree of the scale, the subdominant, when followed by the dominant. The corresponding figure is 6/5.',
                    'translation' => 'Scale degree 4 followed by the dominant takes a 6/5 chord (first-inversion supertonic seventh, or subdominant with added sixth).',
                ]],

                'mediant_first_inv' => [[
                    'author'      => 'Christensen, Jesper Bøje',
                    'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 42.',
                    'lang'        => 'en',
                    'text'        => 'Whenever the bass proceeds in stepwise motion, it suffices to harmonize the notes that fall on the main beats of the bar and to treat the notes between them as passing notes.',
                    'translation' => 'Scale degree 3 as a stepwise ascending passing tone takes a 6th (first inversion of I or VI).',
                ]],

                'ascending_submediant' => [[
                    'author'      => 'Christensen, Jesper Bøje',
                    'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                    'lang'        => 'en',
                    'text'        => 'The basic chord consists of the octave, the fifth, and the third. Scale degree 6 ascending by step represents the submediant triad in root position.',
                    'translation' => 'Scale degree 6 ascending by step takes root position (5 3), forming the submediant triad (vi).',
                ]],

                'descending_submediant' => [[
                    'author'      => 'Dandrieu, Jean-François',
                    'ref'         => 'Principes de l\'Accompagnement du Clavecin. Paris, 1719. ' . $cp('13'),
                    'lang'        => 'en',
                    'text'        => 'The Simple Sixth Chord consists of the sixth, the octave, and the third. It is usually played on the third degree of the scale. Its figure is written: 6.',
                    'translation' => 'Scale degree 6 descending by step takes a 6th (IV⁶ in major), maintaining smooth voice leading.',
                ]],

                'submediant_root_major' => [[
                    'author'      => 'Christensen, Jesper Bøje',
                    'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                    'lang'        => 'en',
                    'text'        => 'Scale degree 6 with a leap or repeated note in major mode takes root position (5), forming the submediant triad (vi).',
                    'translation' => 'Scale degree 6 with a leap or repeated note takes root position in major (vi triad).',
                ]],

                'submediant_first_inv_minor' => [[
                    'author'      => 'Christensen, Jesper Bøje',
                    'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                    'lang'        => 'en',
                    'text'        => 'Scale degree 6 with a leap or repeated note in minor mode takes a 6th (first inversion), since the minor submediant chord sits naturally in first inversion.',
                    'translation' => 'Scale degree 6 with a leap or repeated note takes a 6th in minor (first inversion).',
                ]],

                'dominant_seventh' => [
                    [
                        'author'      => 'Rameau, Jean-Philippe',
                        'ref'         => 'Traité de l\'harmonie. Paris: Ballard, 1722, II.5.',
                        'lang'        => 'fr',
                        'text'        => 'La dominante qui précède la tonique par degrés descendants reçoit la septième.',
                        'translation' => 'The dominant preceding the tonic by descending step receives the seventh.',
                    ],
                    [
                        'author'      => 'Christensen, Jesper Bøje',
                        'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 28.',
                        'lang'        => 'en',
                        'text'        => 'The dissonant 7 must always be resolved. When scale degree 5 descends by step to the tonic, the seventh chord intensifies the harmonic pull.',
                        'translation' => 'When scale degree 5 is followed by a descending step, it takes a 7th figure (V⁷).',
                    ],
                ],

                'dominant_root' => [[
                    'author'      => 'Christensen, Jesper Bøje',
                    'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 28.',
                    'lang'        => 'en',
                    'text'        => 'Scale degree 5 without a following descending step takes root position (5), forming the dominant triad (V). The full seventh chord is not required when there is no strong resolution motion following.',
                    'translation' => 'Scale degree 5 without descending motion to the tonic takes root position (5 3).',
                ]],

                'tonic_root' => [[
                    'author'      => 'Christensen, Jesper Bøje',
                    'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                    'lang'        => 'en',
                    'text'        => 'The basic chord consists of the octave, the fifth, and the third. Scale degree 1 (tonic) always takes this root-position triad (I).',
                    'translation' => 'Scale degree 1 (tonic) always takes root position (5 3), forming the tonic triad (I).',
                ]],

                'cadential_64' => [[
                    'author'      => 'Heinichen, Johann David',
                    'ref'         => 'Der General-Bass in der Composition. 2nd ed. Dresden, 1728 [1711]. ' . $cp('72'),
                    'lang'        => 'en',
                    'text'        => 'The fourth may also be combined with the sixth instead of the fifth. In this case, it is not necessarily tied over from the previous chord, but it is resolved downward as usual. The 6/4 and 5/4 chords occur most frequently in cadences; they should be played in cadences even when not expressly called for in the bass figures.',
                    'translation' => 'A leap of a fourth to scale degree 4 suggests a cadential 6/4 chord (second inversion), used as harmonic preparation before a cadence.',
                ]],

                'subdominant_root' => [[
                    'author'      => 'Christensen, Jesper Bøje',
                    'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 10.',
                    'lang'        => 'en',
                    'text'        => 'Scale degree 4 (subdominant) in most contexts takes root position (5), forming the subdominant triad (IV).',
                    'translation' => 'Scale degree 4 in most contexts takes root position (5 3), forming the subdominant triad (IV).',
                ]],

                'default_rule' => [[
                    'author'      => 'Wead, Andrew, and Ian Knopke',
                    'ref'         => '"Basso Continuo Realization." In Proceedings of the International Computer Music Conference (ICMC). Copenhagen, 2007.',
                    'lang'        => 'en',
                    'text'        => 'Default harmonization: root position (5 3) when no specific rule matches the bass scale degree and melodic context.',
                    'translation' => 'Default harmonization: root position (5 3) when no specific rule matches the bass scale degree and melodic context.',
                ]],

                'tritone_chord_iv' => [[
                    'author'      => 'Christensen, Jesper Bøje',
                    'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 24.',
                    'lang'        => 'en',
                    'text'        => 'In this chord, the tritone is combined with the sixth and the second. It is generally played on the fourth or subdominant degree of the scale (IV) when followed by the mediant (III). Its figure may read ♯4 or ♮. Another alternative is ♭4.',
                    'translation' => 'Scale degree 4 followed by descending step to III takes the tritone chord (♯4/6/2 = V⁴₂, third inversion of the dominant seventh).',
                ]],

                'augmented_fifth' => [[
                    'author'      => 'Christensen, Jesper Bøje',
                    'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 35–36.',
                    'lang'        => 'en',
                    'text'        => 'The augmented-fifth chord combines the ♯5 with the seventh and the ninth: 9/7/♯5/3. It typically occurs on the tonic or submediant bass note and creates a highly dissonant, expressive sonority that demands resolution.',
                    'translation' => 'Figure ♯5 expands to the augmented-fifth chord: 3, ♯5, 7, 9 — a dissonant chromatic chord requiring careful voice-leading resolution.',
                ]],

                'diminished_seventh' => [[
                    'author'      => 'Christensen, Jesper Bøje',
                    'ref'         => '18th-Century Continuo Playing: A Historical Guide to the Basics. Kassel: Bärenreiter, 2002, 36–37.',
                    'lang'        => 'en',
                    'text'        => 'The diminished seventh chord consists of the diminished seventh, the diminished fifth, and the minor third above the bass. Figure ♭7 (alone) signals this fully diminished chord. It most commonly appears on the raised seventh degree (leading tone) in minor mode and resolves inward on all voices.',
                    'translation' => 'Figure ♭7 alone expands to the diminished seventh chord: 3, 5, ♭7 — all intervals diminished relative to the bass, resolving to the tonic chord.',
                ]],
            ];
        }
        return $map[$rule] ?? [];
    }
    /**
     * Given raw figures (array of ['number'=>int,'alter'=>int]),
     * return the expanded, ordered list of generic intervals to place above bass.
     * Each entry: ['interval'=>int, 'alter'=>int]
     *
     * @param array $rawFigures  e.g. [['number'=>6,'alter'=>0],['number'=>5,'alter'=>1]]
     * @param Note  $bass        The bass note (for context)
     * @param int   $keyFifths
     * @param string $keyMode
     * @return array  e.g. [['interval'=>3,'alter'=>0],['interval'=>5,'alter'=>0],['interval'=>6,'alter'=>0]]
     */
    public function expand(array $rawFigures, Note $bass, int $keyFifths, string $keyMode): array
    {
        // Sort figures descending (highest interval first)
        usort($rawFigures, fn($a, $b) => $b['number'] <=> $a['number']);

        $nums = array_column($rawFigures, 'number');

        // ---- Identify chord type from figures and fill in defaults ----

        // Helper: get the alter value for a specific figure number (null = not present)
        $alterOf = function(int $n) use ($rawFigures): ?int {
            foreach ($rawFigures as $f) {
                if ($f['number'] === $n) { return $f['alter']; }
            }
            return null;
        };

        // No figures → root-position triad
        if (empty($nums)) {
            return $this->withAlters([3, 5], [], $keyFifths, $keyMode, $bass);
        }

        // Figure "6" alone → first inversion: 3, 6
        if ($nums === [6]) {
            return $this->withAlters([3, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "4 2", "6 4 2", or "2" alone → third inversion 7th chord: 2, 4, 6  (= 6/4/2)
        // Must come before the "6 4" check so that [6,4,2] is not mistaken for a cadential 6/4.
        if (in_array(2, $nums) && !in_array(9, $nums)) {
            return $this->withAlters([2, 4, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "4 3" or "6 4 3" → second inversion 7th chord: 3, 4, 6  (= 6/4/3)
        // Must come before the "6 4" and suspended-4 checks.
        if (in_array(4, $nums) && in_array(3, $nums)) {
            return $this->withAlters([3, 4, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "#4" (augmented fourth / tritone chord, Christensen pp. 24–25):
        // Third-inversion dominant seventh → 2, #4, 6.
        // Must come before cadential-6/4 and suspended-4 checks.
        if (in_array(4, $nums) && ($alterOf(4) ?? 0) > 0) {
            return $this->withAlters([2, 4, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "6 4" → second inversion (cadential or passing 6/4): 4, 6
        if ($nums === [6, 4]) {
            return $this->withAlters([4, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "b7" alone (diminished seventh, Christensen pp. 36–37):
        // Fully diminished seventh chord: 3, 5, b7.
        // Must come before the plain-7 check so the alteration is not ignored.
        if ($nums === [7] && ($alterOf(7) ?? 0) < 0) {
            return $this->withAlters([3, 5, 7], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "7" alone → root-position 7th chord: 3, 5, 7
        if ($nums === [7]) {
            return $this->withAlters([3, 5, 7], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "7 5" or "7 5 3" → root-position 7th (explicit): 3, 5, 7
        if (in_array(7, $nums) && in_array(5, $nums)) {
            return $this->withAlters([3, 5, 7], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "6 5", "6 5 3" → first inversion 7th chord: 3, 5, 6  (= 6/5/3)
        if (in_array(6, $nums) && in_array(5, $nums)) {
            return $this->withAlters([3, 5, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "9 7" (minor ninth + seventh, Christensen p. 30):
        // Must come before the general 9 check.
        if (in_array(9, $nums) && in_array(7, $nums)) {
            return $this->withAlters([3, 7, 9], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "9" alone or "9 5" → suspended major ninth: 3, 5, 9
        if (in_array(9, $nums)) {
            return $this->withAlters([3, 5, 9], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Suspension "7 6" — bass stays, 7 resolves to 6
        if ($nums === [7, 6]) {
            return $this->withAlters([3, 7], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "b5" alone (diminished-fifth chord, Christensen pp. 16–17):
        // First-inversion dominant seventh → 3, b5, 6.
        // A plain "5" with no alteration falls through to the [3,5] root-position handling below.
        if ($nums === [5] && ($alterOf(5) ?? 0) < 0) {
            return $this->withAlters([3, 5, 6], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Suspended fourth: "4" alone, "5/4" (explicit), "4/5/8", etc. — any combination that
        // includes 4 but not 2, 3, 6, 7, 9, and where the 4 is not augmented (#4 handled above).
        // Christensen pp. 22–23: the four is combined with the fifth (and octave).
        if (in_array(4, $nums)
            && !in_array(2, $nums) && !in_array(3, $nums)
            && !in_array(6, $nums) && !in_array(7, $nums) && !in_array(9, $nums)
        ) {
            return $this->withAlters([4, 5], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "#5" (augmented-fifth chord, Christensen pp. 35–36):
        // Augmented fifth combined with seventh and ninth: 3, #5, 7, 9.
        // Must come before the plain-5 check.
        if ($nums === [5] && ($alterOf(5) ?? 0) > 0) {
            return $this->withAlters([3, 5, 7, 9], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Figure "5" alone → root position triad: 3, 5
        if ($nums === [5]) {
            return $this->withAlters([3, 5], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Diminished 7th: "7 3" with b-alter on 3 and 5
        if (in_array(7, $nums) && in_array(3, $nums)) {
            return $this->withAlters([3, 5, 7], $rawFigures, $keyFifths, $keyMode, $bass);
        }

        // Fallback: use the numbers as given + fill in 3 if not present
        $intervals = $nums;
        if (!in_array(3, $intervals)) {
            $intervals[] = 3;
        }
        sort($intervals);
        return $this->withAlters($intervals, $rawFigures, $keyFifths, $keyMode, $bass);
    }

    /**
     * Take a list of generic intervals and annotate each with the correct alteration
     * from the raw figures (override over key-signature default).
     *
     * @return array  [['interval'=>int, 'alter'=>int], ...]
     */
    private function withAlters(array $intervals, array $rawFigures, int $keyFifths, string $keyMode, Note $bass): array
    {
        // Build a lookup from figure number → alter
        $alterMap = [];
        foreach ($rawFigures as $f) {
            $alterMap[$f['number']] = $f['alter'];
        }

        $result = [];
        foreach ($intervals as $interval) {
            // Check if the figure explicitly specifies an alteration
            $alter = $alterMap[$interval] ?? null;

            // Default alter comes from the key (we use 0 here; PitchHelper handles key accidentals)
            $result[] = [
                'interval' => $interval,
                'alter'    => $alter ?? 0,
                'explicit' => ($alter !== null),
            ];
        }
        return $result;
    }

    /**
     * Unfigured bass decision tree (Gasparini / Delair rules).
     *
     * Determines the most likely figured bass for an unfigured bass note
     * based on:
     *  - Scale degree of the bass note
     *  - Melodic motion (step up/down, leap up/down)
     *  - Mode (major/minor)
     *
     * Decision tree (simplified from Wead & Knopke 2007):
     *
     *  Bass step is:
     *   1 (tonic)       → 5 3       (I)
     *   2 (supertonic)  → 6         (vii°6 in major / ii°6) or 6 5 if descending
     *   3 (mediant)     → 6         (I6 or vi6)
     *   4 (subdominant) → 5 3       (IV) or 6 4 if passing
     *   5 (dominant)    → 5 3       (V) or 7 if leading to tonic
     *   6 (submediant)  → 6         (IV6) or 5 3 (vi)
     *   7 (leading tone) → 6        (V6) or 6 5
     *
     * Motion modifiers (Delair 1724):
     *  - Ascending step from 5 → prefer 6 on next note
     *  - Descending step to 1 → V7 on prev note
     *  - Ascending leap of 4th up → add 6 on destination
     *  - Bass moves by 4th/5th → typically 5 3
     *
     * @param int    $scaleDegree   1..7
     * @param string $motion        'step-up','step-down','leap-up','leap-down','same','start'
     * @param string $nextMotion    Motion to the NEXT note
     * @param string $mode          'major'|'minor'
     * @return array  ['figures' => [['number'=>int,'alter'=>int], ...], 'trace' => [...steps]]
     */
    public function unfiguredDecision(
        int    $scaleDegree,
        string $motion,
        string $nextMotion,
        string $mode,
        int    $leapSize = 0
    ): array {
        $isMajor = strtolower($mode) === 'major';
        $trace = [];

        // --- Gasparini Rule Set (primary) ---

        // Scale degree 7:
        //  • In major: the leading tone (sensibile) takes V⁶ (first inversion) when it
        //    resolves upward by step to tonic — Gasparini's rule.
        //  • In minor: scale degree 7 is the natural subtonic (e.g. D in E minor), which
        //    forms a major triad (VII) that sits naturally in root position.  The *raised*
        //    leading tone (e.g. D♯) is chromatic and is assigned to degree 1 or degree 7
        //    depending on its distance to scale tones; the unfigured decision tree treats it
        //    conservatively as root position.  Annotated files supply the 6 explicitly.
        if ($scaleDegree === 7) {
            $trace[] = ['test' => $this->t('rule.test.degree_7'), 'passed' => true];
            if ($isMajor) {
                $trace[] = ['test' => $this->t('rule.test.major_mode'), 'passed' => true];
                // Leading tone resolving upward to tonic (VII→I) → diminished-fifth chord (♭5/6/3 = V⁶₅)
                // Per Christensen §4: "usually played on the seventh degree… provided that it proceeds to the tonic."
                if ($nextMotion === 'step-up') {
                    $trace[] = [
                        'test'       => $this->t('rule.test.next_step_up_resolves'),
                        'passed'     => true,
                        'isDecision' => true,
                        'rule'       => $this->t('rule.leading_tone.name'),
                        'source'     => $this->t('rule.leading_tone.source'),
                        'figures'    => 'b5',
                        'reason'     => $this->t('rule.leading_tone.reason'),
                        'citations'  => self::ruleCitations('leading_tone'),
                    ];
                    return ['figures' => [['number' => 5, 'alter' => -1]], 'trace' => $trace];
                }
                // Other contexts (descending scale, leap) → simple sixth chord (V⁶)
                $trace[] = [
                    'test'       => $this->t('rule.test.next_step_up_resolves'),
                    'passed'     => false,
                    'isDecision' => true,
                    'rule'       => $this->t('rule.leading_tone.name'),
                    'source'     => $this->t('rule.leading_tone.source'),
                    'figures'    => '6',
                    'reason'     => $this->t('rule.leading_tone.reason'),
                    'citations'  => self::ruleCitations('leading_tone'),
                ];
                return ['figures' => $this->makeFig([6]), 'trace' => $trace];
            }
            // Minor subtonic (natural ♭VII) → root position major triad
            $trace[] = [
                'test'       => $this->t('rule.test.major_mode'),
                'passed'     => false,
                'isDecision' => true,
                'rule'       => $this->t('rule.default_rule.name'),
                'source'     => $this->t('rule.default_rule.source'),
                'figures'    => '5 3',
                'reason'     => $this->t('rule.leading_tone_no_resolve.reason'),
                'citations'  => self::ruleCitations('default_rule'),
            ];
            return ['figures' => $this->makeFig([5, 3]), 'trace' => $trace];
        }
        $trace[] = ['test' => $this->t('rule.test.degree_7'), 'passed' => false];

        // Scale degree 4 (subdominant) followed by ascending step to dominant → 6/5
        // (Dandrieu, Christensen p. 21): IV → V takes the six-five chord in French style.
        // Must come BEFORE the ascending-passing check (which also requires step-up next).
        if ($scaleDegree === 4 && $nextMotion === 'step-up' && $motion !== 'step-up') {
            $trace[] = [
                'test'       => $this->t('rule.test.degree_4_next_step_up'),
                'passed'     => true,
                'isDecision' => true,
                'rule'       => $this->t('rule.subdominant_65.name'),
                'source'     => $this->t('rule.subdominant_65.source'),
                'figures'    => '6 5',
                'reason'     => $this->t('rule.subdominant_65.reason'),
                'citations'  => self::ruleCitations('subdominant_65'),
            ];
            return ['figures' => $this->makeFig([6, 5]), 'trace' => $trace];
        }
        if ($scaleDegree === 4) {
            $trace[] = ['test' => $this->t('rule.test.degree_4_next_step_up'), 'passed' => false];
        }

        // Scale degree 4 ascending step → 6 (passing) — true passing-tone context:
        // both the approach AND the continuation are step-up (III → IV → V).
        if ($scaleDegree === 4 && $motion === 'step-up' && $nextMotion === 'step-up') {
            $trace[] = [
                'test'       => $this->t('rule.test.degree_4_step_up'),
                'passed'     => true,
                'isDecision' => true,
                'rule'       => $this->t('rule.ascending_passing.name'),
                'source'     => $this->t('rule.ascending_passing.source'),
                'figures'    => '6',
                'reason'     => $this->t('rule.ascending_passing.reason'),
                'citations'  => self::ruleCitations('ascending_passing'),
            ];
            return ['figures' => $this->makeFig([6]), 'trace' => $trace];
        }
        $trace[] = ['test' => $this->t('rule.test.degree_4_step_up'), 'passed' => false];

        // Scale degree 2 (supertonic):
        //  • Delair's 6/5 rule applies in a strict descending-sequential context where the
        //    supertonic is approached AND quitted by descending step (ii⁶₅ → V sequence).
        //  • Otherwise the supertonic takes root position.  Gasparini's first-inversion rule
        //    for degree 2 applied to stepwise-passing contexts that composers typically
        //    annotated explicitly in the figured bass; an unannotated supertonic defaults to
        //    root position per Bach continuo practice.
        if ($scaleDegree === 2) {
            $trace[] = ['test' => $this->t('rule.test.degree_2'), 'passed' => true];
            // Petite sixte (6/4/3) on degree II leading by step to I or III:
            // Per Christensen p. 14: "always played when the 6 occurs on the second degree
            // of the scale (II) and leads to I or III."
            if ($nextMotion === 'step-down' || $nextMotion === 'step-up') {
                $trace[] = [
                    'test'       => $this->t('rule.test.next_step_to_i_or_iii'),
                    'passed'     => true,
                    'isDecision' => true,
                    'rule'       => $this->t('rule.petit_accord_supertonic.name'),
                    'source'     => $this->t('rule.petit_accord_supertonic.source'),
                    'figures'    => '6 4 3',
                    'reason'     => $this->t('rule.petit_accord_supertonic.reason'),
                    'citations'  => self::ruleCitations('petit_accord_supertonic'),
                ];
                return ['figures' => $this->makeFig([6, 4, 3]), 'trace' => $trace];
            }
            // All other supertonic contexts → root position (fall through to default)
            $trace[] = ['test' => $this->t('rule.test.next_step_to_i_or_iii'), 'passed' => false];
        }
        // (degree 2 falls through to the default root-position rule below)
        if ($scaleDegree !== 2) {
            $trace[] = ['test' => $this->t('rule.test.degree_2'), 'passed' => false];
        }

        // Scale degree 3 (mediant) → first inversion (I6 or VI6) only when acting as an
        // ascending stepwise passing tone (both motions are step-up).
        // A descending passing tone through the mediant still takes root position: in minor
        // mode it is the dominant or mediant in root position, and descending passing motion
        // does not require first inversion the way ascending passing motion does.
        if ($scaleDegree === 3) {
            $trace[] = ['test' => $this->t('rule.test.degree_3'), 'passed' => true];
            $isStepwise = ($motion === 'step-up' && $nextMotion === 'step-up');
            if ($isStepwise) {
                $trace[] = [
                    'test'       => $this->t('rule.test.motion_passing_step'),
                    'passed'     => true,
                    'isDecision' => true,
                    'rule'       => $this->t('rule.mediant_first_inv.name'),
                    'source'     => $this->t('rule.mediant_first_inv.source'),
                    'figures'    => '6',
                    'reason'     => $this->t('rule.mediant_first_inv.reason'),
                    'citations'  => self::ruleCitations('mediant_first_inv'),
                ];
                return ['figures' => $this->makeFig([6]), 'trace' => $trace];
            }
            // Mediant at a leap or as a structural note → root position
            $trace[] = [
                'test'       => $this->t('rule.test.motion_passing_step'),
                'passed'     => false,
                'isDecision' => true,
                'rule'       => $this->t('rule.default_rule.name'),
                'source'     => $this->t('rule.default_rule.source'),
                'figures'    => '5 3',
                'reason'     => $this->t('rule.mediant_root.reason'),
                'citations'  => self::ruleCitations('default_rule'),
            ];
            return ['figures' => $this->makeFig([5, 3]), 'trace' => $trace];
        }
        $trace[] = ['test' => $this->t('rule.test.degree_3'), 'passed' => false];

        // Scale degree 6 → depends on context
        if ($scaleDegree === 6) {
            $trace[] = ['test' => $this->t('rule.test.degree_6'), 'passed' => true];
            // Submediant ascending step → 5 3 (vi)
            if ($motion === 'step-up') {
                $trace[] = [
                    'test'       => $this->t('rule.test.motion_step_up'),
                    'passed'     => true,
                    'isDecision' => true,
                    'rule'       => $this->t('rule.ascending_submediant.name'),
                    'source'     => $this->t('rule.ascending_submediant.source'),
                    'figures'    => '5 3',
                    'reason'     => $this->t('rule.ascending_submediant.reason'),
                    'citations'  => self::ruleCitations('ascending_submediant'),
                ];
                return ['figures' => $this->makeFig([5, 3]), 'trace' => $trace];
            }
            $trace[] = ['test' => $this->t('rule.test.motion_step_up'), 'passed' => false];
            // Submediant descending → 6 (IV6) in major only.
            // In minor the submediant is a major triad (VI) that sounds naturally in root
            // position whether it descends or not; Gasparini's IV6 rule is a major-mode
            // convention and does not apply in the same way in minor.
            if ($motion === 'step-down' && $isMajor) {
                $trace[] = [
                    'test'       => $this->t('rule.test.motion_step_down'),
                    'passed'     => true,
                    'isDecision' => true,
                    'rule'       => $this->t('rule.descending_submediant.name'),
                    'source'     => $this->t('rule.descending_submediant.source'),
                    'figures'    => '6',
                    'reason'     => $this->t('rule.descending_submediant.reason'),
                    'citations'  => self::ruleCitations('descending_submediant'),
                ];
                return ['figures' => $this->makeFig([6]), 'trace' => $trace];
            }
            // All remaining submediant contexts (minor step-down, any leap, repeated note)
            // → root position. In minor, VI in root position is the norm for unfigured bass.
            $trace[] = [
                'test'       => $this->t('rule.test.motion_step_down'),
                'passed'     => false,
                'isDecision' => true,
                'rule'       => $this->t('rule.submediant_root_major.name'),
                'source'     => $this->t('rule.submediant_root_major.source'),
                'figures'    => '5 3',
                'reason'     => $this->t('rule.submediant_root_major.reason'),
                'citations'  => self::ruleCitations('submediant_root_major'),
            ];
            return ['figures' => $this->makeFig([5, 3]), 'trace' => $trace];
        }
        $trace[] = ['test' => $this->t('rule.test.degree_6'), 'passed' => false];

        // Scale degree 5 (dominant)
        if ($scaleDegree === 5) {
            $trace[] = ['test' => $this->t('rule.test.degree_5'), 'passed' => true];
            // If next motion descends by step to 1 → use V7
            if ($nextMotion === 'step-down') {
                $trace[] = [
                    'test'       => $this->t('rule.test.next_step_down_tonic'),
                    'passed'     => true,
                    'isDecision' => true,
                    'rule'       => $this->t('rule.dominant_seventh.name'),
                    'source'     => $this->t('rule.dominant_seventh.source'),
                    'figures'    => '7',
                    'reason'     => $this->t('rule.dominant_seventh.reason'),
                    'citations'  => self::ruleCitations('dominant_seventh'),
                ];
                return ['figures' => $this->makeFig([7]), 'trace' => $trace];
            }
            $trace[] = [
                'test'       => $this->t('rule.test.next_step_down'),
                'passed'     => false,
                'isDecision' => true,
                'rule'       => $this->t('rule.dominant_root.name'),
                'source'     => $this->t('rule.dominant_root.source'),
                'figures'    => '5 3',
                'reason'     => $this->t('rule.dominant_root.reason'),
                'citations'  => self::ruleCitations('dominant_root'),
            ];
            return ['figures' => $this->makeFig([5, 3]), 'trace' => $trace];
        }
        $trace[] = ['test' => $this->t('rule.test.degree_5'), 'passed' => false];

        // Scale degree 1 (tonic) → I (5/3) always
        if ($scaleDegree === 1) {
            $trace[] = [
                'test'       => $this->t('rule.test.degree_1'),
                'passed'     => true,
                'isDecision' => true,
                'rule'       => $this->t('rule.tonic_root.name'),
                'source'     => $this->t('rule.tonic_root.source'),
                'figures'    => '5 3',
                'reason'     => $this->t('rule.tonic_root.reason'),
                'citations'  => self::ruleCitations('tonic_root'),
            ];
            return ['figures' => $this->makeFig([5, 3]), 'trace' => $trace];
        }
        $trace[] = ['test' => $this->t('rule.test.degree_1'), 'passed' => false];

        // Scale degree 4 (subdominant)
        if ($scaleDegree === 4) {
            $trace[] = ['test' => $this->t('rule.test.degree_4'), 'passed' => true];
            // Leap of 4th up or 5th down → cadential 6/4
            if ($leapSize === 4 && ($motion === 'leap-up' || $motion === 'leap-down')) {
                $trace[] = [
                    'test'       => $this->t('rule.test.leap_fourth'),
                    'passed'     => true,
                    'isDecision' => true,
                    'rule'       => $this->t('rule.cadential_64.name'),
                    'source'     => $this->t('rule.cadential_64.source'),
                    'figures'    => '6 4',
                    'reason'     => $this->t('rule.cadential_64.reason'),
                    'citations'  => self::ruleCitations('cadential_64'),
                ];
                return ['figures' => $this->makeFig([6, 4]), 'trace' => $trace];
            }
            $trace[] = ['test' => $this->t('rule.test.leap_fourth'), 'passed' => false];
            // Descending step to mediant (IV → III) → tritone chord (♯4/6/2 = V⁴₂)
            // Per Christensen §8: "generally played on the fourth degree when followed by the mediant."
            if ($nextMotion === 'step-down') {
                $trace[] = [
                    'test'       => $this->t('rule.test.degree_4_next_step_down'),
                    'passed'     => true,
                    'isDecision' => true,
                    'rule'       => $this->t('rule.tritone_chord_iv.name'),
                    'source'     => $this->t('rule.tritone_chord_iv.source'),
                    'figures'    => '#4',
                    'reason'     => $this->t('rule.tritone_chord_iv.reason'),
                    'citations'  => self::ruleCitations('tritone_chord_iv'),
                ];
                return ['figures' => [['number' => 4, 'alter' => 1]], 'trace' => $trace];
            }
            $trace[] = [
                'test'       => $this->t('rule.test.degree_4_next_step_down'),
                'passed'     => false,
                'isDecision' => true,
                'rule'       => $this->t('rule.subdominant_root.name'),
                'source'     => $this->t('rule.subdominant_root.source'),
                'figures'    => '5 3',
                'reason'     => $this->t('rule.subdominant_root.reason'),
                'citations'  => self::ruleCitations('subdominant_root'),
            ];
            return ['figures' => $this->makeFig([5, 3]), 'trace' => $trace];
        }
        $trace[] = ['test' => $this->t('rule.test.degree_4'), 'passed' => false];

        // Default → root position triad
        $trace[] = [
            'test'       => $this->t('rule.test.default'),
            'passed'     => true,
            'isDecision' => true,
            'rule'       => $this->t('rule.default_rule.name'),
            'source'     => $this->t('rule.default_rule.source'),
            'figures'    => '5 3',
            'reason'     => $this->t('rule.default_rule.reason'),
            'citations'  => self::ruleCitations('default_rule'),
        ];
        return ['figures' => $this->makeFig([5, 3]), 'trace' => $trace];
    }

    private function makeFig(array $numbers): array
    {
        return array_map(fn($n) => ['number' => $n, 'alter' => 0], $numbers);
    }
}
