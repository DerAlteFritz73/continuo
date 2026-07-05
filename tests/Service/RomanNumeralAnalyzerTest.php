<?php

namespace App\Tests\Service;

use App\Model\Measure;
use App\Model\Note;
use App\Service\HarmonyAnalyzer;
use App\Service\PitchHelper;
use App\Service\RomanNumeralAnalyzer;
use App\Service\SequenceDetector;
use App\Service\SuspensionDetector;
use PHPUnit\Framework\TestCase;

class RomanNumeralAnalyzerTest extends TestCase
{
    private RomanNumeralAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new RomanNumeralAnalyzer(new HarmonyAnalyzer(new PitchHelper()));
    }

    /** @param array{0:string,1:int,2:array} ...$notes step, octave, figures */
    private function measure(int $number, array ...$notes): Measure
    {
        $m = new Measure($number);
        foreach ($notes as [$step, $octave, $figures]) {
            $m->bassNotes[] = new Note($step, $octave, 1.0, 0, 'quarter', false, null, null, $figures);
        }
        return $m;
    }

    private function fig(int $number, int $alter = 0): array
    {
        return ['number' => $number, 'alter' => $alter];
    }

    public function testDiatonicInversionsInCMajor(): void
    {
        // C (root, unfigured) → I ; B with 6 → V6 ; G with 7 → V7 ; C → I
        $measures = [
            $this->measure(1,
                ['C', 3, []],
                ['B', 2, [$this->fig(6)]],
                ['G', 2, [$this->fig(7)]],
                ['C', 3, []],
            ),
        ];

        $roman = array_column($this->analyzer->analyze($measures, 0, 'major'), 'roman');

        $this->assertSame(['I', 'V⁶', 'V⁷', 'I'], $roman);
    }

    public function testSixFourIsSecondInversion(): void
    {
        // D in the bass under 6/4 in C major = second-inversion G triad = V⁶₄
        $measures = [$this->measure(1, ['D', 3, [$this->fig(6), $this->fig(4)]])];

        $roman = array_column($this->analyzer->analyze($measures, 0, 'major'), 'roman');

        $this->assertSame('V⁶₄', $roman[0]);
    }

    public function testSupertonicIsMinorInMajor(): void
    {
        // D root triad in C major → ii
        $measures = [$this->measure(1, ['D', 3, []])];
        $roman    = array_column($this->analyzer->analyze($measures, 0, 'major'), 'roman');
        $this->assertSame('ii', $roman[0]);
    }

    public function testAppliedDominantOfDominant(): void
    {
        // D major (raised third via a standalone sharp) falling to G = V/V, then V
        $measures = [
            $this->measure(1,
                ['D', 3, [$this->fig(3, 1)]],   // # on the third → F# → D major
                ['G', 2, []],
            ),
        ];

        $roman = array_column($this->analyzer->analyze($measures, 0, 'major'), 'roman');

        $this->assertSame('V/V', $roman[0]);
        $this->assertSame('V', $roman[1]);
    }

    public function testCircleOfFifthsSequenceDetected(): void
    {
        $seq = new SequenceDetector();

        // Bass falling by fifths / rising by fourths: A2 D3 G2 C3 F2 → 4 moves
        $measures = [
            $this->measure(1, ['A', 2, []], ['D', 3, []]),
            $this->measure(2, ['G', 2, []], ['C', 3, []]),
            $this->measure(3, ['F', 2, []]),
        ];

        $patterns = $seq->detect($measures);

        $this->assertNotEmpty($patterns);
        $this->assertSame(SequenceDetector::CIRCLE_OF_FIFTHS, $patterns[0]['type']);
    }

    public function testDescendingStepSequenceDetected(): void
    {
        $seq = new SequenceDetector();
        // Falling stepwise bass: C4 B3 A3 G3 F3
        $measures = [
            $this->measure(1, ['C', 4, []], ['B', 3, []]),
            $this->measure(2, ['A', 3, []], ['G', 3, []]),
            $this->measure(3, ['F', 3, []]),
        ];

        $patterns = $seq->detect($measures);

        $this->assertNotEmpty($patterns);
        $this->assertSame(SequenceDetector::DESCENDING_STEPS, $patterns[0]['type']);
    }

    public function testFourThreeReadsAsSuspensionNotSeventhChord(): void
    {
        // Cadential 4–3 over the dominant: root-position V with a suspension,
        // NOT a 4/3 second-inversion seventh chord.
        $measures = [$this->measure(1, ['G', 2, [$this->fig(4), $this->fig(3)]])];

        $chord = $this->analyzer->analyze($measures, 0, 'major')[0];

        $this->assertSame('V ⁴⁻³', $chord['roman']);
        $this->assertSame('4–3', $chord['suspension']);
    }

    public function testPetiteSixteStaysAChord(): void
    {
        // 6/4/3 is the petite sixte — a real chord, not a suspension.
        $measures = [$this->measure(1, ['D', 3, [$this->fig(6), $this->fig(4), $this->fig(3)]])];

        $chord = $this->analyzer->analyze($measures, 0, 'major')[0];

        $this->assertNull($chord['suspension']);
        $this->assertTrue($chord['seventh']);
    }

    public function testSevenSixSuspensionResolvesToSixChord(): void
    {
        // E in the bass with 7–6 in C major: the 6 leaves a first-inversion I⁶,
        // the 7 suspends above it.
        $measures = [$this->measure(1, ['E', 3, [$this->fig(7), $this->fig(6)]])];

        $chord = $this->analyzer->analyze($measures, 0, 'major')[0];

        $this->assertSame('7–6', $chord['suspension']);
        $this->assertStringContainsString('⁶', $chord['roman']);
    }

    public function testSuspensionDetectorFindsSingleNoteAndHeldBass(): void
    {
        $sus = new SuspensionDetector();

        $measures = [
            // m1: single-note 4–3 stack ; m2: petite sixte (must be ignored)
            $this->measure(1, ['G', 2, [$this->fig(4), $this->fig(3)]]),
            $this->measure(2, ['D', 3, [$this->fig(6), $this->fig(4), $this->fig(3)]]),
            // m3: held bass A2 with 4 then 3 across two notes → held 4–3
            $this->measure(3, ['A', 2, [$this->fig(4)]], ['A', 2, [$this->fig(3)]]),
        ];

        $found = $sus->detect($measures);
        $byMeasure = [];
        foreach ($found as $f) {
            $byMeasure[$f['measure']] = $f;
        }

        $this->assertArrayHasKey(1, $byMeasure);
        $this->assertSame('4–3', $byMeasure[1]['type']);
        $this->assertFalse($byMeasure[1]['held']);

        $this->assertArrayNotHasKey(2, $byMeasure, 'petite sixte is not a suspension');

        $this->assertArrayHasKey(3, $byMeasure);
        $this->assertSame('4–3', $byMeasure[3]['type']);
        $this->assertTrue($byMeasure[3]['held']);
    }

    public function testMelodicSuspensionOverBassChange(): void
    {
        // C major, one measure. Bass A2 → G2 under a held C5 melody note that
        // resolves down to B4: C5 is a consonant 3rd over A, a dissonant 4th
        // over G (4–3 suspension), resolving to B4.
        $sus = new SuspensionDetector();

        $m = new Measure(1);
        $m->bassNotes[] = new Note('A', 2, 2.0, 0, 'quarter');   // divisions=1 → 2 quarter-beats
        $m->bassNotes[] = new Note('G', 2, 2.0, 0, 'quarter');
        $m->melodyNotes[] = ['offset' => 0.0, 'duration' => 3.0, 'pc' => 0,
                             'octave' => 5, 'midi' => 72, 'note' => new Note('C', 5, 3.0)];
        $m->melodyNotes[] = ['offset' => 3.0, 'duration' => 1.0, 'pc' => 11,
                             'octave' => 4, 'midi' => 71, 'note' => new Note('B', 4, 1.0)];

        $found = $sus->detect([$m], 1);

        $this->assertCount(1, $found);
        $this->assertSame('4–3', $found[0]['type']);
        $this->assertSame('melody', $found[0]['source']);
    }
}
