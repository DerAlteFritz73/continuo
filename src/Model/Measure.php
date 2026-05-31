<?php

namespace App\Model;

class Measure
{
    /** @var Note[] */
    public array $bassNotes = [];

    /** @var Chord[] Realized chords, parallel to bassNotes */
    public array $realizedChords = [];

    /**
     * Melody notes (from the highest non-bass part) active in this measure.
     * Each entry: ['offset' => float (quarter-note units from measure start),
     *              'duration' => float, 'pc' => int (0–11)]
     * Empty when no separate melody part is present.
     *
     * @var array<array{offset:float,duration:float,pc:int}>
     */
    public array $melodyNotes = [];

    public ?array $keySignature  = null;   // ['fifths' => int, 'mode' => string]
    public ?array $timeSignature = null;   // ['beats' => int, 'beatType' => int]

    public function __construct(
        public readonly int $number,
    ) {}
}
