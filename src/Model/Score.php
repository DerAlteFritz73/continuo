<?php

namespace App\Model;

class Score
{
    /** @var Measure[] */
    public array $measures = [];

    /** @var array<array{start_measure: int, end_measure: int, key: array, confidence: string}> */
    public array $passages = [];

    public ?string $title = null;
    public ?string $composer = null;

    /** Global key: number of fifths (-7..7) */
    public int $keyFifths = 0;

    /** 'major' or 'minor' */
    public string $keyMode = 'major';

    /** Beats per measure */
    public int $beats = 4;

    /** Beat type (denominator) */
    public int $beatType = 4;

    /** Divisions per quarter note */
    public int $divisions = 1;

    /**
     * Raw MusicXML of the original melody part (e.g. the flute in a sonata),
     * preserved verbatim from the source so the florid rhythms render exactly.
     * Rendered as a top staff above the continuo realization. Null when the
     * source has no separate melody part.
     */
    public ?string $melodyPartXml = null;

    /** Display name of the melody part (e.g. "Flöte."), for the part-list. */
    public ?string $melodyPartName = null;

    public function tonic(): string
    {
        // Major keys by fifths
        $majorTonics = [
            -7 => 'C', // Cb major
            -6 => 'G',
            -5 => 'D',
            -4 => 'A',
            -3 => 'E',
            -2 => 'B',
            -1 => 'F',
             0 => 'C',
             1 => 'G',
             2 => 'D',
             3 => 'A',
             4 => 'E',
             5 => 'B',
             6 => 'F', // F# major
             7 => 'C', // C# major
        ];

        // Minor keys by fifths
        $minorTonics = [
            -7 => 'A',
            -6 => 'E',
            -5 => 'B',
            -4 => 'F',
            -3 => 'C',
            -2 => 'G',
            -1 => 'D',
             0 => 'A',
             1 => 'E',
             2 => 'B',
             3 => 'F',
             4 => 'C',
             5 => 'G',
             6 => 'D',
             7 => 'A',
        ];

        $map = $this->keyMode === 'minor' ? $minorTonics : $majorTonics;
        return $map[$this->keyFifths] ?? 'C';
    }
}
