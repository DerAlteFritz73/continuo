<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Bridge to the staff re-lining sidecar (bin/staff_reline.py).
 *
 * French baroque prints put clefs on lines a modern player does not expect: the
 * G clef on the first line, the F clef on the third, C clefs anywhere from the
 * first to the fourth.  Re-ruling the staff -- dropping the top line and adding
 * one below the bottom -- leaves the clef a line higher without touching a
 * single note, which is the conversion this service performs on a facsimile.
 *
 * The image work (staff detection, line erasure, re-ruling) lives in Python
 * because PHP has no practical equivalent of OpenCV; we invoke it via Symfony
 * Process exactly as {@see AudioChromagramExtractor} does for librosa.
 */
class StaffRelineService
{
    /** Shift presets, keyed by the clef the source is written in. */
    public const CLEFS = [
        'french_violin' => 1,
        'baritone_f'    => 1,
        'soprano_c'     => 2,
        'mezzo_c'       => 1,
        'tenor_c'       => -1,
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(string:default::RELINE_PYTHON_BIN)%')]
        private readonly string $pythonBin = '',
    ) {
    }

    /**
     * Detect the staves on a facsimile without changing anything.
     *
     * @return array{input:string, dpi:int, shift:int, pages:list<array<string,mixed>>}
     */
    public function analyze(string $path, int $dpi = 300, ?string $pages = null, ?string $outDir = null): array
    {
        $args = ['--mode', 'analyze', '--dpi', (string) $dpi];
        if ($pages !== null) {
            $args[] = '--pages';
            $args[] = $pages;
        }
        if ($outDir !== null) {
            $args[] = '--out';
            $args[] = $outDir;
        }
        return $this->run($path, $args);
    }

    /**
     * Re-rule the staves so the engraved clef reads as its modern equivalent.
     *
     * @param int    $shift  line positions to move the ruling; positive moves it
     *                       down the page, which reads the clef that many lines
     *                       higher.  Use {@see self::CLEFS} to get one.
     * @param bool   $ledgers rule ledger lines for notes the shift pushed out of
     *                       the staff.  Off by default: distinguishing a
     *                       notehead from an ornament on an old plate is
     *                       unreliable enough that a wrong ledger line is a real
     *                       risk, and the note reads without one.
     *
     * @return array{input:string, dpi:int, shift:int, pages:list<array<string,mixed>>}
     */
    public function reline(
        string $path,
        string $outDir,
        int $shift,
        int $dpi = 300,
        ?string $pages = null,
        bool $ledgers = false,
        bool $pdf = false,
    ): array {
        if ($shift < -4 || $shift > 4) {
            throw new \InvalidArgumentException(sprintf('Shift %d is outside the five lines of a staff.', $shift));
        }

        $args = ['--mode', 'apply', '--shift', (string) $shift, '--dpi', (string) $dpi, '--out', $outDir];
        if ($pages !== null) {
            $args[] = '--pages';
            $args[] = $pages;
        }
        if ($ledgers) {
            $args[] = '--ledgers';
        }
        if ($pdf) {
            $args[] = '--pdf';
        }
        return $this->run($path, $args);
    }

    /** Shift for a named source clef, or null if the name is not one we handle. */
    public function shiftForClef(string $clef): ?int
    {
        return self::CLEFS[$clef] ?? null;
    }

    /**
     * @param list<string> $args
     *
     * @return array{input:string, dpi:int, shift:int, pages:list<array<string,mixed>>}
     */
    private function run(string $path, array $args): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Facsimile not found: %s', $path));
        }

        $process = new Process(array_merge(
            [$this->resolvePythonBin(), $this->projectDir . '/bin/staff_reline.py', $path],
            $args,
        ));
        // A full facsimile at 300 dpi is a few seconds a page on modest hardware.
        $process->setTimeout(900.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Staff re-lining failed: %s',
                trim($process->getErrorOutput()) ?: 'no error output',
            ));
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (!is_array($decoded) || !isset($decoded['pages']) || !is_array($decoded['pages'])) {
            throw new \RuntimeException('Staff re-lining returned unexpected output.');
        }

        return $decoded;
    }

    private function resolvePythonBin(): string
    {
        if ($this->pythonBin !== '') {
            return $this->pythonBin;
        }
        $local = $this->projectDir . '/var/reline-venv/bin/python';
        return is_file($local) ? $local : 'python3';
    }
}
