<?php

namespace App\Model;

class Measure
{
    /** @var Note[] */
    public array $bassNotes = [];

    /** @var Chord[] Realized chords, parallel to bassNotes */
    public array $realizedChords = [];

    /**
     * Melody notes pooled from every non-bass part (all voices/staves) active in
     * this measure — so multi-soloist textures are all represented.
     * Each entry: ['offset' => float (quarter-note units from measure start),
     *              'duration' => float, 'pc' => int (0–11), 'octave' => int,
     *              'midi' => int (absolute MIDI pitch), 'note' => Note].
     * Empty when the source has no separate melody/treble line.
     *
     * @var array<array{offset:float,duration:float,pc:int,octave:int,midi:int,note:Note}>
     */
    public array $melodyNotes = [];

    public ?array $keySignature  = null;   // ['fifths' => int, 'mode' => string] — from the source, drives the output armature
    public ?array $timeSignature = null;   // ['beats' => int, 'beatType' => int]

    /**
     * Auto-detected local key for this measure's phrase. Used as a harmonic
     * context for realization but deliberately NOT serialized to the output
     * armature (it would rewrite the key signature mid-piece). Display-only +
     * realization hint. ['fifths' => int, 'mode' => string]
     */
    public ?array $detectedKey = null;

    public function __construct(
        public readonly int $number,
    ) {}
}
