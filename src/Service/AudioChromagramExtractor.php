<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Bridge to the Python loudness-based-chromagram sidecar (bin/audio_keychroma.py).
 *
 * The script implements the loudness-based chromagram (LBC) of Ni, McVicar,
 * Santos-Rodriguez & De Bie, "An End-to-End Machine Learning System for
 * Harmonic Analysis of Music" (IEEE TASLP 2012, Sec. III): CQT -> SPL ->
 * A-weighting -> per-pitch-class loudness. We invoke it via Symfony Process and
 * parse its JSON back into PHP. The heavy DSP (FFT/CQT, A-weighting, HPSS) lives
 * in Python/librosa because PHP has no practical equivalent.
 *
 * Each returned chromagram is a 12-element pitch-class profile (C = 0), the exact
 * shape {@see LocalKeyEstimator::estimateFromHistogram()} expects as its histogram.
 */
class AudioChromagramExtractor
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        // Path to the python interpreter that has librosa installed. Empty by
        // default; falls back to the project-local venv (see resolvePythonBin).
        #[Autowire('%env(string:default::AUDIO_PYTHON_BIN)%')]
        private readonly string $pythonBin = '',
    ) {
    }

    /**
     * Run the sidecar on an audio file and return its parsed output.
     *
     * @return array{sr:int, duration:float, global:list<float>,
     *               segments:list<array{start:float, end:float, chroma:list<float>}>}
     *
     * @throws \RuntimeException if the file is missing, the sidecar fails, or the
     *                           output is not the expected JSON shape
     */
    public function extract(string $audioPath, float $windowSeconds = 4.0, float $overlap = 0.5): array
    {
        if (!is_file($audioPath)) {
            throw new \RuntimeException(sprintf('Audio file not found: %s', $audioPath));
        }

        $process = new Process([
            $this->resolvePythonBin(),
            $this->projectDir . '/bin/audio_keychroma.py',
            $audioPath,
            '--window', (string) $windowSeconds,
            '--overlap', (string) $overlap,
        ]);
        // Audio decoding + CQT over a full track can take a while on modest hardware.
        $process->setTimeout(600.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'Chromagram extraction failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()),
                0,
                new ProcessFailedException($process)
            );
        }

        $data = json_decode($process->getOutput(), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Chromagram sidecar returned invalid JSON.');
        }
        if (isset($data['error'])) {
            throw new \RuntimeException('Chromagram sidecar error: ' . $data['error']);
        }
        if (!isset($data['global'], $data['segments']) || !is_array($data['segments'])) {
            throw new \RuntimeException('Chromagram sidecar returned an unexpected payload.');
        }

        return $data;
    }

    /**
     * Pick the python interpreter: the configured one if set, otherwise the
     * project-local venv, falling back to whatever "python3" is on PATH.
     */
    private function resolvePythonBin(): string
    {
        if ($this->pythonBin !== '') {
            return $this->pythonBin;
        }

        $venv = $this->projectDir . '/var/audio-venv/bin/python';

        return is_executable($venv) ? $venv : 'python3';
    }
}
