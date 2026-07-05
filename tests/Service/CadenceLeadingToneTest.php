<?php

namespace App\Tests\Service;

use App\Model\Chord;
use App\Model\Measure;
use App\Model\Note;
use App\Model\Score;
use App\Service\CadenceDetector;
use App\Service\HarmonyAnalyzer;
use App\Service\PitchHelper;
use PHPUnit\Framework\TestCase;

class CadenceLeadingToneTest extends TestCase
{
    private CadenceDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new CadenceDetector(new HarmonyAnalyzer(new PitchHelper()));
    }

    private function note(string $step, int $octave, float $dur = 1.0): Note
    {
        return new Note($step, $octave, $dur, 0, 'quarter');
    }

    /**
     * Build a V → I authentic cadence in C major spanning two measures, with a
     * realized leading tone (B3) in the penultimate chord that resolves up a
     * semitone to the tonic (C4) in the arrival chord.
     */
    private function authenticCadenceScore(bool $withResolution): Score
    {
        $score = new Score();
        $score->divisions = 1;

        $m1 = new Measure(1);
        $m1->bassNotes[] = $this->note('G', 2, 4.0);          // dominant, held
        $g = new Chord($m1->bassNotes[0], [5, 3]);
        $g->addUpperVoice($this->note('B', 3));               // leading tone
        $g->addUpperVoice($this->note('D', 4));
        $g->addUpperVoice($this->note('G', 4));
        $m1->realizedChords[0] = $g;

        $m2 = new Measure(2);
        $m2->bassNotes[] = $this->note('C', 3, 4.0);          // tonic arrival, downbeat, held
        $c = new Chord($m2->bassNotes[0], [5, 3]);
        // Soprano either resolves the leading tone up to C4 (60) or holds G4.
        $c->addUpperVoice($withResolution ? $this->note('C', 4) : $this->note('G', 4));
        $c->addUpperVoice($this->note('E', 4));
        $c->addUpperVoice($this->note('G', 4));
        $m2->realizedChords[0] = $c;

        $score->measures = [$m1, $m2];

        return $score;
    }

    public function testLeadingToneConfirmedWhenItResolvesUp(): void
    {
        $score    = $this->authenticCadenceScore(withResolution: true);
        $cadences = $this->detector->detect($score, 0, 'major', useRealized: true);

        $this->assertCount(1, $cadences);
        $this->assertSame(CadenceDetector::AUTHENTIC, $cadences[0]['type']);
        $this->assertTrue($cadences[0]['leadingTone']);
    }

    public function testLeadingToneNotConfirmedWhenVoiceDoesNotResolve(): void
    {
        $score    = $this->authenticCadenceScore(withResolution: false);
        $cadences = $this->detector->detect($score, 0, 'major', useRealized: true);

        $this->assertCount(1, $cadences);
        $this->assertFalse($cadences[0]['leadingTone']);
    }

    public function testLeadingToneNeverFlaggedWithoutRealizedMode(): void
    {
        // Same resolving voices, but the pre-realization pass must ignore them.
        $score    = $this->authenticCadenceScore(withResolution: true);
        $cadences = $this->detector->detect($score, 0, 'major');   // useRealized defaults to false

        $this->assertCount(1, $cadences);
        $this->assertFalse($cadences[0]['leadingTone']);
    }

    public function testResolutionBonusRaisesTheScore(): void
    {
        $with    = $this->detector->detect($this->authenticCadenceScore(true), 0, 'major', useRealized: true)[0];
        $without = $this->detector->detect($this->authenticCadenceScore(false), 0, 'major', useRealized: true)[0];

        $this->assertGreaterThan($without['score'], $with['score']);
    }

    /**
     * The keyboard voices do NOT resolve the leading tone (the inner ^7 falls to
     * the fifth), but the soloist melody carries B4 → C5. Pooling the melody must
     * catch it.
     */
    public function testLeadingToneCarriedByMelodyIsCaught(): void
    {
        $score = new Score();
        $score->divisions = 1;

        $m1 = new Measure(1);
        $m1->bassNotes[] = $this->note('G', 2, 4.0);
        $g = new Chord($m1->bassNotes[0], [5, 3]);
        $g->addUpperVoice($this->note('D', 4));   // deliberately no B (leading tone) in the keyboard
        $g->addUpperVoice($this->note('G', 4));
        $m1->realizedChords[0] = $g;
        $m1->melodyNotes[] = $this->melody('B', 4, 0.0, 4.0);   // soloist leading tone

        $m2 = new Measure(2);
        $m2->bassNotes[] = $this->note('C', 3, 4.0);
        $c = new Chord($m2->bassNotes[0], [5, 3]);
        $c->addUpperVoice($this->note('E', 4));
        $c->addUpperVoice($this->note('G', 4));
        $m2->realizedChords[0] = $c;
        $m2->melodyNotes[] = $this->melody('C', 5, 0.0, 4.0);   // resolves up a semitone (71 → 72)

        $score->measures = [$m1, $m2];

        $cadences = $this->detector->detect($score, 0, 'major', useRealized: true);
        $this->assertCount(1, $cadences);
        $this->assertTrue($cadences[0]['leadingTone'], 'melody B4→C5 should confirm the leading tone');
    }

    private function melody(string $step, int $octave, float $offset, float $dur): array
    {
        $n = $this->note($step, $octave, $dur);
        return ['offset' => $offset, 'duration' => $dur, 'pc' => $n->pitchClass(),
                'octave' => $octave, 'midi' => $n->midiPitch(), 'note' => $n];
    }
}
