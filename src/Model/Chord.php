<?php

namespace App\Model;

/**
 * Represents a realized chord (bass + up to 3 upper voices: tenor, alto, soprano).
 * Voice 1 = soprano (highest), Voice 2 = alto, Voice 3 = tenor, Voice 4 = bass.
 */
class Chord
{
    /** @var Note[] Upper voice notes (soprano, alto, tenor) */
    public array $upperVoices = [];

    /** Decision trace: context + rule steps used to select figures for this chord */
    public array $decisionTrace = [];

    public function __construct(
        public readonly Note  $bass,
        public readonly array $figures,       // raw figured bass numbers e.g. [7, 5, 3]
        public readonly string $chordSymbol = '', // e.g. "I", "V7", "IV6"
    ) {}

    public function addUpperVoice(Note $note): void
    {
        $this->upperVoices[] = $note;
    }

    /** All notes from bass up */
    public function allNotes(): array
    {
        return array_merge([$this->bass], $this->upperVoices);
    }

    /** Pitch classes present in this chord */
    public function pitchClasses(): array
    {
        return array_unique(array_map(fn(Note $n) => $n->pitchClass(), $this->allNotes()));
    }
}
