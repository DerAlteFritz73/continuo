<?php

namespace App\Tests\Service;

use App\Service\StaffRelineService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the staff re-liner.
 *
 * These drive the real Python sidecar against a synthetic staff, because what
 * needs proving is the geometry it produces: after a one-line shift the ruling
 * must be the old lines 2-5 plus one new line a step below the old bottom, and
 * nothing else on the page may have moved.  They skip when the OpenCV venv is
 * not installed.
 */
class StaffRelineServiceTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/synthetic-staff.png';

    private string $projectDir;
    private string $outDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 2);
        if (!is_file($this->projectDir . '/var/reline-venv/bin/python')
            && getenv('RELINE_PYTHON_BIN') === false) {
            $this->markTestSkipped('The re-lining venv is not installed (bin/reline-requirements.txt).');
        }
        $this->outDir = sys_get_temp_dir() . '/reline-test-' . bin2hex(random_bytes(4));
        mkdir($this->outDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->outDir);
    }

    /** @test */
    public function findsTheFiveLinesOfASyntheticStaff(): void
    {
        $report = $this->service()->analyze(self::FIXTURE);

        $this->assertCount(1, $report['pages']);
        $staves = $report['pages'][0]['staves'];
        $this->assertCount(1, $staves, 'one staff on the fixture');
        $this->assertCount(5, $staves[0]['lines']);
        $this->assertEqualsWithDelta(20.0, $staves[0]['step'], 1.5);
    }

    /**
     * The whole point of the feature: the ruling moves down one line position,
     * so the clef engraved on line 1 comes to sit on line 2.
     *
     * @test
     */
    public function shiftsTheRulingDownByOneLine(): void
    {
        $service = $this->service();
        $before  = $service->analyze(self::FIXTURE)['pages'][0]['staves'][0];

        $service->reline(self::FIXTURE, $this->outDir, shift: 1);
        $relined = $this->outDir . '/page-001-relined.png';
        $this->assertFileExists($relined);

        $after = $service->analyze($relined)['pages'][0]['staves'][0];
        $this->assertCount(5, $after['lines']);

        // Lines 2-5 of the original survive unmoved...
        for ($i = 0; $i < 4; $i++) {
            $this->assertEqualsWithDelta($before['lines'][$i + 1], $after['lines'][$i], 2.0);
        }
        // ...and a fifth is ruled one step below the old bottom line.
        $this->assertEqualsWithDelta(
            $before['lines'][4] + $before['step'],
            $after['lines'][4],
            3.0,
        );
    }

    /** A negative shift moves the ruling the other way, for the tenor C clef. */
    public function testShiftsTheRulingUpForANegativeShift(): void
    {
        $service = $this->service();
        $before  = $service->analyze(self::FIXTURE)['pages'][0]['staves'][0];

        $service->reline(self::FIXTURE, $this->outDir, shift: -1);
        $after = $service->analyze($this->outDir . '/page-001-relined.png')['pages'][0]['staves'][0];

        $this->assertEqualsWithDelta(
            $before['lines'][0] - $before['step'],
            $after['lines'][0],
            3.0,
        );
        for ($i = 0; $i < 4; $i++) {
            $this->assertEqualsWithDelta($before['lines'][$i], $after['lines'][$i + 1], 2.0);
        }
    }

    /** The notes must come through the edit untouched — only the ruling moves. */
    public function testLeavesTheNoteheadsWhereTheyWere(): void
    {
        if (!function_exists('imagecreatefrompng')) {
            $this->markTestSkipped('GD is not available to read back the pixels.');
        }
        $service = $this->service();
        $service->reline(self::FIXTURE, $this->outDir, shift: 1);

        $before = imagecreatefrompng(self::FIXTURE);
        $after  = imagecreatefrompng($this->outDir . '/page-001-relined.png');
        $this->assertNotFalse($before);
        $this->assertNotFalse($after);

        // The fixture's first notehead is centred on (240, 120) with radii 12x8.
        // Sample its core, well clear of any ruling line.
        foreach ([[240, 120], [236, 118], [244, 122], [600, 140]] as [$x, $y]) {
            $this->assertSame(
                imagecolorat($before, $x, $y) & 0xFF,
                imagecolorat($after, $x, $y) & 0xFF,
                sprintf('notehead pixel (%d,%d) changed', $x, $y),
            );
        }
    }

    /** A shift wider than the staff is refused rather than silently clamped. */
    public function testRejectsAnOutOfRangeShift(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->reline(self::FIXTURE, $this->outDir, shift: 7);
    }

    /** Every preset must name a shift the sidecar will accept. */
    public function testClefPresetsMapToShiftsWithinTheStaff(): void
    {
        $service = $this->service();
        foreach (array_keys(StaffRelineService::CLEFS) as $clef) {
            $shift = $service->shiftForClef($clef);
            $this->assertNotNull($shift, $clef);
            $this->assertGreaterThanOrEqual(-4, $shift);
            $this->assertLessThanOrEqual(4, $shift);
            $this->assertNotSame(0, $shift, $clef . ' would be a no-op');
        }
        $this->assertNull($service->shiftForClef('not_a_clef'));
    }

    private function service(): StaffRelineService
    {
        return new StaffRelineService($this->projectDir, (string) (getenv('RELINE_PYTHON_BIN') ?: ''));
    }
}
