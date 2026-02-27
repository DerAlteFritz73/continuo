<?php

namespace App\Service;

use App\Model\Chord;
use App\Model\Measure;
use App\Model\Note;
use App\Model\Score;

/**
 * Serializes a realized Score back to MusicXML format.
 *
 * Output structure (MusicXML 4.0):
 *  - Part 1: Original bass line (unchanged)
 *  - Part 2: Realized continuo (3 upper voices + bass doubled)
 *    Voice 1 = Soprano
 *    Voice 2 = Alto
 *    Voice 3 = Tenor
 *    Voice 4 = Bass (doubled from original)
 */
class MusicXmlSerializer
{
    public function serialize(Score $score): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // DOCTYPE
        $dom->appendChild(
            $dom->createProcessingInstruction(
                'xml-model',
                'href="musicxml.xsd" type="application/xml" schematypens="http://www.w3.org/2001/XMLSchema"'
            )
        );

        $root = $dom->createElement('score-partwise');
        $root->setAttribute('version', '4.0');
        $dom->appendChild($root);

        // --- Work / Identification ---
        if ($score->title) {
            $work = $dom->createElement('work');
            $work->appendChild($dom->createElement('work-title', htmlspecialchars($score->title . ' (Continuo Realization)')));
            $root->appendChild($work);
        }

        $id = $dom->createElement('identification');
        $enc = $dom->createElement('encoding');
        $enc->appendChild($dom->createElement('software', 'Continuo Realizer (Symfony)'));
        $enc->appendChild($dom->createElement('encoding-date', date('Y-m-d')));
        $id->appendChild($enc);
        $root->appendChild($id);

        // --- Part list ---
        $partList = $dom->createElement('part-list');

        $scorePart1 = $dom->createElement('score-part');
        $scorePart1->setAttribute('id', 'P1');
        $pn1 = $dom->createElement('part-name', 'Bass');
        $scorePart1->appendChild($pn1);
        $partList->appendChild($scorePart1);

        $scorePart2 = $dom->createElement('score-part');
        $scorePart2->setAttribute('id', 'P2');
        $pn2 = $dom->createElement('part-name', 'Realization');
        $scorePart2->appendChild($pn2);

        // Instrument details for Part 2
        $instr = $dom->createElement('score-instrument');
        $instr->setAttribute('id', 'P2-I1');
        $instr->appendChild($dom->createElement('instrument-name', 'Harpsichord'));
        $scorePart2->appendChild($instr);
        $partList->appendChild($scorePart2);

        $root->appendChild($partList);

        // --- Part 1: Original bass ---
        $part1 = $dom->createElement('part');
        $part1->setAttribute('id', 'P1');
        $root->appendChild($part1);

        $isFirstMeasure = true;
        foreach ($score->measures as $measure) {
            $measureEl = $this->buildBassMeasure($dom, $measure, $score, $isFirstMeasure);
            $part1->appendChild($measureEl);
            $isFirstMeasure = false;
        }

        // --- Part 2: Realized continuo (multi-voice) ---
        $part2 = $dom->createElement('part');
        $part2->setAttribute('id', 'P2');
        $root->appendChild($part2);

        $isFirstMeasure = true;
        foreach ($score->measures as $measure) {
            $measureEl = $this->buildRealizationMeasure($dom, $measure, $score, $isFirstMeasure);
            $part2->appendChild($measureEl);
            $isFirstMeasure = false;
        }

        return $dom->saveXML();
    }

    // -------------------------------------------------------------------------
    // Part 1: Bass line
    // -------------------------------------------------------------------------

    private function buildBassMeasure(\DOMDocument $dom, Measure $measure, Score $score, bool $isFirst): \DOMElement
    {
        $el = $dom->createElement('measure');
        $el->setAttribute('number', (string) $measure->number);

        if ($isFirst) {
            $el->appendChild($this->buildAttributes($dom, $score, 1));
        }

        foreach ($measure->bassNotes as $note) {
            $el->appendChild($this->noteElement($dom, $note, 1, $score->divisions));
        }

        return $el;
    }

    // -------------------------------------------------------------------------
    // Part 2: Realized continuo
    // -------------------------------------------------------------------------

    private function buildRealizationMeasure(\DOMDocument $dom, Measure $measure, Score $score, bool $isFirst): \DOMElement
    {
        $el = $dom->createElement('measure');
        $el->setAttribute('number', (string) $measure->number);

        if ($isFirst) {
            $el->appendChild($this->buildAttributes($dom, $score, 2));
        }

        foreach ($measure->bassNotes as $i => $bassNote) {
            $chord = $measure->realizedChords[$i] ?? null;

            if ($chord === null || $bassNote->isRest()) {
                // No realization → rest in each voice
                for ($v = 1; $v <= 4; $v++) {
                    $rest = new Note('C', 4, $bassNote->duration, 0, $bassNote->type, true, null, $v);
                    $noteEl = $this->noteElement($dom, $rest, $v, $score->divisions);
                    if ($v > 1) {
                        $noteEl->insertBefore($dom->createElement('chord'), $noteEl->firstChild);
                    }
                    $el->appendChild($noteEl);
                }
                continue;
            }

            // Voices in order: soprano (3), alto (2), tenor (1), bass (4)
            // We write them top-down so we need to reorder for MusicXML:
            // In MusicXML, for simultaneous notes in different voices, each voice
            // is written independently with <backup> elements separating them.

            // Voice 1 = soprano (highest upper voice)
            $upperVoices = array_reverse($chord->upperVoices); // highest first
            $totalDur    = $this->durationTicks($bassNote->duration, $score->divisions);

            // Write voices 1, 2, 3 (upper) then backup and write voice 4 (bass)

            foreach ($upperVoices as $vi => $upperNote) {
                $voiceNum = $vi + 1; // 1=soprano, 2=alto, 3=tenor
                $noteEl   = $this->noteElement($dom, $upperNote, $voiceNum, $score->divisions);

                // First note in voice 1 starts normally; subsequent voices need <backup>
                if ($vi > 0) {
                    $backup  = $dom->createElement('backup');
                    $durEl   = $dom->createElement('duration', (string) $totalDur);
                    $backup->appendChild($durEl);
                    $el->appendChild($backup);
                }
                $el->appendChild($noteEl);
            }

            // Backup before bass voice
            if (!empty($upperVoices)) {
                $backup  = $dom->createElement('backup');
                $durEl   = $dom->createElement('duration', (string) $totalDur);
                $backup->appendChild($durEl);
                $el->appendChild($backup);
            }

            // Voice 4 = bass (doubled from original)
            $bassEl = $this->noteElement($dom, $bassNote, 4, $score->divisions);
            $el->appendChild($bassEl);
        }

        return $el;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildAttributes(\DOMDocument $dom, Score $score, int $staff): \DOMElement
    {
        $attrs = $dom->createElement('attributes');

        $div = $dom->createElement('divisions', (string) $score->divisions);
        $attrs->appendChild($div);

        // Key
        $key    = $dom->createElement('key');
        $fifths = $dom->createElement('fifths', (string) $score->keyFifths);
        $mode   = $dom->createElement('mode', $score->keyMode);
        $key->appendChild($fifths);
        $key->appendChild($mode);
        $attrs->appendChild($key);

        // Time
        $time  = $dom->createElement('time');
        $beats = $dom->createElement('beats', (string) $score->beats);
        $beat  = $dom->createElement('beat-type', (string) $score->beatType);
        $time->appendChild($beats);
        $time->appendChild($beat);
        $attrs->appendChild($time);

        // Clef
        $clef = $dom->createElement('clef');
        if ($staff === 1) {
            // Bass clef
            $clef->appendChild($dom->createElement('sign', 'F'));
            $clef->appendChild($dom->createElement('line', '4'));
        } else {
            // Treble clef for realization
            $clef->appendChild($dom->createElement('sign', 'G'));
            $clef->appendChild($dom->createElement('line', '2'));
            // Additional bass clef staff
        }
        $attrs->appendChild($clef);

        return $attrs;
    }

    private function noteElement(\DOMDocument $dom, Note $note, int $voice, int $divisions): \DOMElement
    {
        $el = $dom->createElement('note');

        if ($note->isRest()) {
            $el->appendChild($dom->createElement('rest'));
        } else {
            $pitch = $dom->createElement('pitch');
            $pitch->appendChild($dom->createElement('step', $note->step));
            if ($note->alter !== 0) {
                $pitch->appendChild($dom->createElement('alter', (string) $note->alter));
            }
            $pitch->appendChild($dom->createElement('octave', (string) $note->octave));
            $el->appendChild($pitch);
        }

        $dur = $this->durationTicks($note->duration, $divisions);
        $el->appendChild($dom->createElement('duration', (string) $dur));
        $el->appendChild($dom->createElement('voice', (string) $voice));
        $el->appendChild($dom->createElement('type', $note->type ?: 'quarter'));

        // Accidental element if needed
        if (!$note->isRest() && $note->alter !== 0) {
            $accidental = match($note->alter) {
                1  => 'sharp',
                -1 => 'flat',
                2  => 'double-sharp',
                -2 => 'flat-flat',
                default => null,
            };
            if ($accidental) {
                $el->appendChild($dom->createElement('accidental', $accidental));
            }
        }

        return $el;
    }

    private function durationTicks(float $durationInQuarters, int $divisions): int
    {
        return (int) round($durationInQuarters * $divisions);
    }
}
