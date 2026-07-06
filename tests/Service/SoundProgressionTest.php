<?php

namespace App\Tests\Service;

use App\Model\Measure;
use App\Model\Note;
use App\Service\HarmonyAnalyzer;
use App\Service\PitchHelper;
use App\Service\RuleOfTheOctave;
use App\Service\SoundProgressionDetector;
use PHPUnit\Framework\TestCase;

class SoundProgressionTest extends TestCase
{
    private RuleOfTheOctave $roto;
    private SoundProgressionDetector $detector;

    protected function setUp(): void
    {
        $this->roto     = new RuleOfTheOctave();
        $this->detector = new SoundProgressionDetector(new HarmonyAnalyzer(new PitchHelper()), $this->roto);
    }

    private function fig(int $number, int $alter = 0): array
    {
        return ['number' => $number, 'alter' => $alter];
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

    // ── Rule of the Octave ────────────────────────────────────────────────

    public function testStabilityFromFigures(): void
    {
        $this->assertSame(RuleOfTheOctave::STABLE,    $this->roto->stabilityFromFigures([]));       // 5/3
        $this->assertSame(RuleOfTheOctave::STABLE,    $this->roto->stabilityFromFigures([5, 3]));
        $this->assertSame(RuleOfTheOctave::MOBILE,    $this->roto->stabilityFromFigures([6]));       // 6/3
        $this->assertSame(RuleOfTheOctave::DISSONANT, $this->roto->stabilityFromFigures([6, 5]));
        $this->assertSame(RuleOfTheOctave::DISSONANT, $this->roto->stabilityFromFigures([7]));
        $this->assertSame(RuleOfTheOctave::DISSONANT, $this->roto->stabilityFromFigures([6, 4]));
    }

    public function testExpectedChordsFrameAndFillTheOctave(): void
    {
        // Stable 5/3 on the framing/dividing degrees, mobile 6 on the mobile ones.
        $this->assertSame('5/3', $this->roto->expected(1, 'ascending')['figures']);
        $this->assertSame(RuleOfTheOctave::STABLE, $this->roto->expected(5, 'ascending')['stability']);
        $this->assertSame(RuleOfTheOctave::MOBILE, $this->roto->expected(3, 'ascending')['stability']);
        $this->assertSame(RuleOfTheOctave::DISSONANT, $this->roto->expected(7, 'descending')['stability']); // 4/3
    }

    // ── Sound progression ─────────────────────────────────────────────────

    public function testAscendingFourthProgressionIsDetected(): void
    {
        // G-major Quartgang 1→4 in the bass: G(5/3) A(6) B(6) C(6/5), all on-beat.
        $measures = [$this->measure(1,
            ['G', 2, []],
            ['A', 2, [$this->fig(6)]],
            ['B', 2, [$this->fig(6)]],
            ['C', 3, [$this->fig(6), $this->fig(5)]],
        )];

        $sp    = $this->detector->detect($measures, 1, 'major');
        $spans = $sp['octave_rule'];

        $this->assertNotEmpty($spans);
        $this->assertSame('fourth', $spans[0]['type']);
        $this->assertSame('ascending', $spans[0]['direction']);
        $this->assertTrue($spans[0]['conforms'], 'the figures follow the Rule of the Octave');
    }

    public function testPipArcRunsStableThroughUnstableToStable(): void
    {
        // stable (5/3 on 1̂) → mobile (6) → mobile (6) → stable (5/3 on 5̂):
        // one p–i–p arc. The close is degree 5, which the Rule of the Octave
        // leaves a stable 5/3.
        $measures = [$this->measure(1,
            ['G', 2, []],
            ['A', 2, [$this->fig(6)]],
            ['B', 2, [$this->fig(6)]],
            ['D', 3, []],
        )];

        $arcs = $this->detector->detect($measures, 1, 'major')['pip_arcs'];

        $this->assertCount(1, $arcs);
        $this->assertSame(2, $arcs[0]['length'], 'two mobile chords carry the motion');
    }

    public function testFictaBassResolvingByStep(): void
    {
        // C# is chromatic in G major and rises a semitone to D → ficta.
        $measures = [
            $this->measure(1, ['C', 3, [$this->fig(6)]]),
            $this->measure(2, ['D', 3, []]),
        ];
        // Make the C a C# by altering.
        $measures[0]->bassNotes[0] = new Note('C', 3, 1.0, 1, 'quarter', false, null, null, [$this->fig(6)]);

        $ficta = $this->detector->detect($measures, 1, 'major')['ficta'];

        $this->assertCount(1, $ficta);
        $this->assertSame(1, $ficta[0]['measure']);
        $this->assertStringContainsString('C#', $ficta[0]['note']);
    }

    public function testFiveSixOverHeldBass(): void
    {
        // Held G: 5/3 then 6 → dynamisation.
        $measures = [$this->measure(1, ['G', 2, []], ['G', 2, [$this->fig(6)]])];

        $fiveSix = $this->detector->detect($measures, 1, 'major')['five_six'];

        $this->assertCount(1, $fiveSix);
        $this->assertSame(1, $fiveSix[0]['measure']);
    }
}
