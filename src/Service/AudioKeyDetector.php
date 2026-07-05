<?php

namespace App\Service;

/**
 * Audio-domain tonality detector — the second key/mode detector, alongside the
 * symbolic (MusicXML) path that feeds {@see LocalKeyEstimator} from parsed notes.
 *
 * It pairs the loudness-based chromagram of Ni et al. (IEEE TASLP 2012) with the
 * Krumhansl-Schmuckler correlation already used for symbolic input: the sidecar
 * yields per-window pitch-class profiles, each of which is scored against the 24
 * key profiles to give a global key plus a local-key timeline. The symbolic and
 * audio detectors thus share their key-finding core and output shape — only the
 * front end (clean notes vs. perceptual chromagram) differs.
 *
 * Motivation: far more music exists as audio than as MusicXML, so this extends
 * tonality detection to that much larger corpus.
 */
class AudioKeyDetector
{
    public function __construct(
        private readonly AudioChromagramExtractor $extractor,
        private readonly LocalKeyEstimator $keyEstimator,
    ) {
    }

    /**
     * Detect tonality from an audio file.
     *
     * @return array{
     *   duration: float,
     *   global: array<string, mixed>,
     *   timeline: list<array{start: float, end: float, key: array<string, mixed>}>
     * }
     *
     * @throws \RuntimeException on extraction failure (see AudioChromagramExtractor)
     */
    public function detect(string $audioPath, float $windowSeconds = 4.0, float $overlap = 0.5): array
    {
        $data = $this->extractor->extract($audioPath, $windowSeconds, $overlap);

        $timeline = [];
        foreach ($data['segments'] as $segment) {
            $timeline[] = [
                'start' => $segment['start'],
                'end'   => $segment['end'],
                'key'   => $this->keyEstimator->estimateFromHistogram($segment['chroma']),
            ];
        }

        return [
            'duration' => $data['duration'],
            'global'   => $this->keyEstimator->estimateFromHistogram($data['global']),
            'timeline' => $timeline,
        ];
    }
}
