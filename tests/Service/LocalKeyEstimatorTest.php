<?php

namespace App\Tests\Service;

use App\Model\Note;
use App\Service\LocalKeyEstimator;
use PHPUnit\Framework\TestCase;

class LocalKeyEstimatorTest extends TestCase
{
    private LocalKeyEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new LocalKeyEstimator();
    }

    /** @test */
    public function detects_c_major_from_histogram(): void
    {
        // C-heavy diatonic emphasis (tonic + dominant + mediant + leading tone).
        $hist = [4, 0, 1, 0, 2, 1, 0, 3, 0, 1, 0, 1];

        $result = $this->estimator->estimateFromHistogram($hist);

        $this->assertSame(0, $result['fifths']);
        $this->assertSame('major', $result['mode']);
        $this->assertSame('high', $result['confidence']);
    }

    /** @test */
    public function distinguishes_a_minor_from_its_relative_major(): void
    {
        // A minor: A/E/C weighted, with a raised G# leading tone.
        $hist = [2, 0, 1, 0, 1, 1, 0, 2, 1, 3, 0, 0];

        $result = $this->estimator->estimateFromHistogram($hist);

        // Same signature as C major (0 fifths) but the mode must be minor.
        $this->assertSame(0, $result['fifths']);
        $this->assertSame('minor', $result['mode']);
    }

    /** @test */
    public function detects_g_major_with_one_sharp(): void
    {
        // G-heavy, with D, B and an F# scale degree.
        $hist = [1, 0, 2, 0, 1, 1, 1, 3, 0, 1, 0, 2];

        $result = $this->estimator->estimateFromHistogram($hist);

        $this->assertSame(1, $result['fifths']);
        $this->assertSame('major', $result['mode']);
    }

    /** @test */
    public function empty_histogram_returns_low_confidence_default(): void
    {
        $result = $this->estimator->estimateFromHistogram(array_fill(0, 12, 0.0));

        $this->assertSame(0, $result['fifths']);
        $this->assertSame('major', $result['mode']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame(0.0, $result['correlation']);
    }

    /** @test */
    public function correlation_is_bounded_and_alternatives_are_returned(): void
    {
        $hist = [4, 0, 1, 0, 2, 1, 0, 3, 0, 1, 0, 1];

        $result = $this->estimator->estimateFromHistogram($hist);

        $this->assertGreaterThan(0.0, $result['correlation']);
        $this->assertLessThanOrEqual(1.0, $result['correlation']);
        $this->assertCount(3, $result['alternatives']);
        // The best correlation must dominate the runner-up.
        $this->assertGreaterThanOrEqual($result['alternatives'][0]['correlation'], $result['correlation']);
    }

    /** @test */
    public function estimate_from_notes_weights_by_duration_and_ignores_rests(): void
    {
        $notes = [
            new Note('C', 4, 4.0),                       // long tonic
            new Note('E', 4, 2.0),
            new Note('G', 4, 2.0),
            new Note('B', 4, 1.0),
            new Note('R', 0, 4.0, 0, 'whole', true),     // rest must be ignored
        ];

        $result = $this->estimator->estimateFromNotes($notes);

        $this->assertSame(0, $result['fifths']);
        $this->assertSame('major', $result['mode']);
    }

    /** @test */
    public function histogram_from_notes_accumulates_duration_per_pitch_class(): void
    {
        $notes = [
            new Note('C', 4, 3.0),
            new Note('C', 5, 1.0),  // same pitch class, different octave
            new Note('G', 4, 2.0),
            new Note('R', 0, 5.0, 0, 'whole', true),
        ];

        $hist = $this->estimator->histogramFromNotes($notes);

        $this->assertEqualsWithDelta(4.0, $hist[0], 1e-9); // C: 3 + 1
        $this->assertEqualsWithDelta(2.0, $hist[7], 1e-9); // G
        $this->assertEqualsWithDelta(0.0, array_sum($hist) - 6.0, 1e-9);
    }
}
