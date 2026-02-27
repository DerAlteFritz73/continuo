<?php

namespace App\Model;

/**
 * Represents a single note with pitch, octave, duration, and accidental.
 */
class Note
{
    public function __construct(
        public readonly string $step,        // C, D, E, F, G, A, B
        public readonly int    $octave,
        public readonly float  $duration,    // quarter note = 1.0
        public readonly int    $alter = 0,   // -1=flat, 0=natural, 1=sharp
        public readonly string $type = 'quarter',
        public readonly bool   $isRest = false,
        public readonly ?int   $staff = null,
        public readonly ?int   $voice = null,
        public readonly array  $figuredBass = [],  // figures on this note e.g. [6, 3]
    ) {}

    /**
     * Return MIDI pitch (middle C = 60).
     */
    public function midiPitch(): int
    {
        if ($this->isRest) {
            return -1;
        }
        $map = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11];
        return ($this->octave + 1) * 12 + $map[$this->step] + $this->alter;
    }

    /**
     * Pitch class 0-11 (C=0).
     */
    public function pitchClass(): int
    {
        return $this->midiPitch() % 12;
    }

    public function isRest(): bool
    {
        return $this->isRest;
    }

    public function withOctave(int $octave): self
    {
        return new self(
            $this->step, $octave, $this->duration, $this->alter,
            $this->type, $this->isRest, $this->staff, $this->voice, $this->figuredBass
        );
    }

    public function withFiguredBass(array $figures): self
    {
        return new self(
            $this->step, $this->octave, $this->duration, $this->alter,
            $this->type, $this->isRest, $this->staff, $this->voice, $figures
        );
    }

    public function __toString(): string
    {
        if ($this->isRest) {
            return 'R';
        }
        $acc = match($this->alter) {
            1  => '#',
            -1 => 'b',
            default => '',
        };
        return $this->step . $acc . $this->octave;
    }
}
