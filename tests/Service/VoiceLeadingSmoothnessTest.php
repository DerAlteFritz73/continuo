<?php

namespace App\Tests\Service;

use App\Model\Chord;
use App\Model\Note;
use App\Repository\VoiceLeadingRuleRepository;
use App\Service\PitchHelper;
use App\Service\VoiceLeadingEngine;
use PHPUnit\Framework\TestCase;

class VoiceLeadingSmoothnessTest extends TestCase
{
    private VoiceLeadingEngine $engine;

    protected function setUp(): void
    {
        // Empty rule repository → the engine uses its hard-coded fallback costs,
        // making the search deterministic.
        $repo = $this->createMock(VoiceLeadingRuleRepository::class);
        $repo->method('findActiveOrderedByPriority')->willReturn([]);

        $this->engine = new VoiceLeadingEngine(new PitchHelper(), $repo);
    }

    private function bonus(array $curr, array $prev, int $bassCurr, ?int $prevBass): float
    {
        $m = new \ReflectionMethod(VoiceLeadingEngine::class, 'parallelTenthBonus');
        $m->setAccessible(true);
        return (float) $m->invoke($this->engine, $curr, $prev, $bassCurr, $prevBass);
    }

    // ── Parallel 3rds/10ths reward ────────────────────────────────────────

    public function testParallelTenthOverAStepwiseBassIsRewarded(): void
    {
        // Bass C3→D3 (step up); an upper voice E4→F4 keeps a 10th above the bass
        // and moves the same direction → rewarded, with the passing-bass bonus.
        $b = $this->bonus([65], [64], 50, 48);   // F4 over D3, from E4 over C3
        $this->assertGreaterThanOrEqual(9.0, $b);
    }

    public function testNonThirdIntervalIsNotRewarded(): void
    {
        // A4 over D3 is a fifth (7 semitones), not a 3rd/10th → no reward.
        $this->assertSame(0.0, $this->bonus([57], [55], 50, 48));
    }

    public function testContraryMotionIsNotRewarded(): void
    {
        // Upper voice moves down while the bass moves up → not a parallel.
        $this->assertSame(0.0, $this->bonus([63], [65], 50, 48));
    }

    public function testStaticBassGivesNoParallelReward(): void
    {
        $this->assertSame(0.0, $this->bonus([65], [64], 48, 48));
    }

    // ── Fifth omission is restricted to PURE fifths ───────────────────────

    public function testDiminishedFifthIsNeverOmitted(): void
    {
        // B diminished triad in C major (B–D–F): the fifth F is a DIMINISHED
        // fifth (bass+6, not bass+7), so it is a tendency tone and must be kept.
        $bass  = new Note('B', 2, 1.0);                  // MIDI 47
        $chord = new Chord($bass, [], '');
        $intervals = [
            ['interval' => 3, 'alter' => 0, 'explicit' => false],
            ['interval' => 5, 'alter' => 0, 'explicit' => false],
        ];

        $realized = $this->engine->realize($chord, $intervals, null, 0, 'major');

        $pcs = array_map(fn(Note $n) => $n->pitchClass(), $realized->upperVoices);
        $this->assertContains(5, $pcs, 'the diminished fifth (F) must be present');
    }

    // ── Lighter texture: 4-voice triads become 3 notes ────────────────────

    public function testFourVoiceTriadIsRealisedAsThreeNotes(): void
    {
        // A plain C-major triad in 4-voice mode: no doubling → 2 upper voices
        // (bass + third + fifth = three distinct notes).
        $chord = new Chord(new Note('C', 3, 1.0), [], '');
        $intervals = [
            ['interval' => 3, 'alter' => 0, 'explicit' => false],
            ['interval' => 5, 'alter' => 0, 'explicit' => false],
        ];

        $realized = $this->engine->realize($chord, $intervals, null, 0, 'major', false, null, 4);

        $this->assertCount(2, $realized->upperVoices, 'a triad lightens to three notes');
    }

    public function testCadentialTonicInBassIsDoubled(): void
    {
        // V (with leading tone B) → I with the tonic C in the bass: the tonic is
        // doubled, so four voices are kept.
        $prev = new Chord(new Note('G', 2, 1.0), [], '');   // G major, leading tone B in an upper voice
        $prev->addUpperVoice(new Note('B', 3, 1.0));
        $prev->addUpperVoice(new Note('D', 4, 1.0));
        $prev->addUpperVoice(new Note('G', 4, 1.0));

        $chord = new Chord(new Note('C', 3, 1.0), [], '');
        $intervals = [
            ['interval' => 3, 'alter' => 0, 'explicit' => false],
            ['interval' => 5, 'alter' => 0, 'explicit' => false],
        ];

        $realized = $this->engine->realize($chord, $intervals, $prev, 0, 'major', false, null, 4);

        $this->assertCount(3, $realized->upperVoices, 'cadential tonic keeps four voices');

        $pcs = array_merge(
            [$realized->bass->pitchClass()],
            array_map(fn(Note $n) => $n->pitchClass(), $realized->upperVoices)
        );
        $tonicCount = count(array_filter($pcs, fn(int $pc): bool => $pc === 0));
        $this->assertGreaterThanOrEqual(2, $tonicCount, 'the tonic C is doubled');
    }
}
