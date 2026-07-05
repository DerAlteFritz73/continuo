<?php

namespace App\Tests\Service;

use App\Service\AudioChromagramExtractor;
use App\Service\AudioKeyDetector;
use App\Service\LocalKeyEstimator;
use PHPUnit\Framework\TestCase;

/**
 * The chromagram extractor (Python/librosa sidecar) is mocked, so these tests
 * exercise the audio detector's wiring and its reuse of the Krumhansl-Schmuckler
 * core without needing audio decoding installed.
 */
class AudioKeyDetectorTest extends TestCase
{
    /**
     * A C-major-weighted chromagram must resolve to C major, and every segment
     * must surface in the timeline with its own key estimate.
     *
     * @test
     */
    public function detectsKeyFromChromagramAndBuildsTimeline(): void
    {
        // A clean C-major profile: tonic/dominant/mediant heavy, diatonic > chromatic.
        $cMajor = [1.0, 0.05, 0.4, 0.05, 0.7, 0.45, 0.05, 0.85, 0.05, 0.45, 0.05, 0.35];
        // A G-major profile is the same shape rotated so G (pc 7) is the tonic.
        $gMajor = [];
        for ($i = 0; $i < 12; $i++) {
            $gMajor[$i] = $cMajor[($i - 7 + 12) % 12];
        }

        $extractor = $this->createMock(AudioChromagramExtractor::class);
        $extractor->method('extract')->willReturn([
            'sr'       => 11025,
            'duration' => 8.0,
            'global'   => $cMajor,
            'segments' => [
                ['start' => 0.0, 'end' => 4.0, 'chroma' => $cMajor],
                ['start' => 4.0, 'end' => 8.0, 'chroma' => $gMajor],
            ],
        ]);

        $detector = new AudioKeyDetector($extractor, new LocalKeyEstimator());
        $result   = $detector->detect('/irrelevant.wav');

        self::assertSame(8.0, $result['duration']);
        self::assertSame(0, $result['global']['tonicPc'], 'global key should be C');
        self::assertSame('major', $result['global']['mode']);

        self::assertCount(2, $result['timeline']);
        self::assertSame(0, $result['timeline'][0]['key']['tonicPc'], 'segment 1 should be C');
        self::assertSame(7, $result['timeline'][1]['key']['tonicPc'], 'segment 2 should be G');
        self::assertSame(0.0, $result['timeline'][0]['start']);
        self::assertSame(8.0, $result['timeline'][1]['end']);
    }

    /**
     * Extraction failures must propagate so callers can report them.
     *
     * @test
     */
    public function propagatesExtractionFailure(): void
    {
        $extractor = $this->createMock(AudioChromagramExtractor::class);
        $extractor->method('extract')->willThrowException(new \RuntimeException('boom'));

        $detector = new AudioKeyDetector($extractor, new LocalKeyEstimator());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $detector->detect('/irrelevant.wav');
    }
}
