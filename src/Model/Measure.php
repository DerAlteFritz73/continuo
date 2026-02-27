<?php

namespace App\Model;

class Measure
{
    /** @var Note[] */
    public array $bassNotes = [];

    /** @var Chord[] Realized chords, parallel to bassNotes */
    public array $realizedChords = [];

    public ?array $keySignature  = null;   // ['fifths' => int, 'mode' => string]
    public ?array $timeSignature = null;   // ['beats' => int, 'beatType' => int]

    public function __construct(
        public readonly int $number,
    ) {}
}
