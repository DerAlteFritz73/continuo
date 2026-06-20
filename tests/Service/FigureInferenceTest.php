<?php

namespace App\Tests\Service;

use App\Model\Note;
use App\Service\FigureInference;
use PHPUnit\Framework\TestCase;

class FigureInferenceTest extends TestCase
{
    private FigureInference $inference;

    protected function setUp(): void
    {
        $this->inference = new FigureInference();
    }

    /**
     * Regression: a chromatic melody note that cannot be placed in the scale
     * makes getGenericInterval() return 0. Combined with a bass on the tonic
     * (scale position 0) this produced a -1 array index in inferAlter()
     * ("Undefined array key -1"). It must no longer throw.
     *
     * @test
     */
    public function chromatic_note_over_tonic_does_not_throw(): void
    {
        $bass   = new Note('C', 3, 1.0);            // tonic of C major → scale index 0
        $melody = new Note('C', 4, 1.0, 1);          // C# — outside the C-major scale

        $figures = $this->inference->inferFromMelody($bass, [$melody], 0, 'major');

        // No exception; the unresolvable interval falls back to a root-position chord.
        $this->assertIsArray($figures);
        $this->assertSame([['number' => 5, 'alter' => 0]], $figures);
    }

    /** @test */
    public function empty_melody_returns_no_figures(): void
    {
        $bass = new Note('C', 3, 1.0);

        $this->assertSame([], $this->inference->inferFromMelody($bass, [], 0, 'major'));
    }

    /** @test */
    public function sixth_above_bass_is_inferred_as_first_inversion(): void
    {
        $bass   = new Note('C', 3, 1.0);
        $melody = new Note('A', 4, 1.0);  // a sixth above C in C major

        $figures = $this->inference->inferFromMelody($bass, [$melody], 0, 'major');

        $this->assertSame(6, $figures[0]['number']);
    }
}
