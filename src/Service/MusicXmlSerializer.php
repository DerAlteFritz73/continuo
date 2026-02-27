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
 *  - Part 1: Original bass line (unchanged, bass clef)
 *  - Part 2: Realized continuo — grand staff (2 staves):
 *      Staff 1 / treble: Voice 1 = Soprano, Voice 2 = Alto, Voice 3 = Tenor
 *      Staff 2 / bass:   Voice 4 = Bass (doubled from original)
 */
class MusicXmlSerializer
{
    public function serialize(Score $score): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('score-partwise');
        $root->setAttribute('version', '4.0');
        $dom->appendChild($root);

        // --- Work / Identification ---
        if ($score->title) {
            $work = $dom->createElement('work');
            $work->appendChild($dom->createElement('work-title', htmlspecialchars($score->title . ' (Continuo Realization)')));
            $root->appendChild($work);
        }

        $id  = $dom->createElement('identification');
        $enc = $dom->createElement('encoding');
        $enc->appendChild($dom->createElement('software', 'Continuo Realizer (Symfony)'));
        $enc->appendChild($dom->createElement('encoding-date', date('Y-m-d')));
        $id->appendChild($enc);
        $root->appendChild($id);

        // --- Part list ---
        $partList = $dom->createElement('part-list');

        $sp1 = $dom->createElement('score-part');
        $sp1->setAttribute('id', 'P1');
        $sp1->appendChild($dom->createElement('part-name', 'Bass'));
        $partList->appendChild($sp1);

        $sp2 = $dom->createElement('score-part');
        $sp2->setAttribute('id', 'P2');
        $sp2->appendChild($dom->createElement('part-name', 'Realization'));
        $instr = $dom->createElement('score-instrument');
        $instr->setAttribute('id', 'P2-I1');
        $instr->appendChild($dom->createElement('instrument-name', 'Harpsichord'));
        $sp2->appendChild($instr);
        $partList->appendChild($sp2);

        $root->appendChild($partList);

        // --- Part 1: Original bass ---
        $part1 = $dom->createElement('part');
        $part1->setAttribute('id', 'P1');
        $root->appendChild($part1);
        $isFirst = true;
        foreach ($score->measures as $measure) {
            $part1->appendChild($this->buildBassMeasure($dom, $measure, $score, $isFirst));
            $isFirst = false;
        }

        // --- Part 2: Realized continuo (grand staff) ---
        $part2 = $dom->createElement('part');
        $part2->setAttribute('id', 'P2');
        $root->appendChild($part2);
        $isFirst = true;
        foreach ($score->measures as $measure) {
            $part2->appendChild($this->buildRealizationMeasure($dom, $measure, $score, $isFirst));
            $isFirst = false;
        }

        return $dom->saveXML();
    }

    // -------------------------------------------------------------------------
    // Part 1: Bass line (single staff, bass clef)
    // -------------------------------------------------------------------------

    private function buildBassMeasure(\DOMDocument $dom, Measure $measure, Score $score, bool $isFirst): \DOMElement
    {
        $el = $dom->createElement('measure');
        $el->setAttribute('number', (string) $measure->number);

        if ($isFirst) {
            $el->appendChild($this->buildBassAttributes($dom, $score));
        } elseif ($measure->keySignature !== null) {
            // Mid-score key change
            $el->appendChild($this->buildKeyChangeAttributes($dom, $measure));
        }

        foreach ($measure->bassNotes as $note) {
            $el->appendChild($this->noteElement($dom, $note, 1, $score->divisions, 1));
            if (!empty($note->figuredBass)) {
                $el->appendChild($this->figuredBassElement($dom, $note->figuredBass));
            }
        }

        return $el;
    }

    // -------------------------------------------------------------------------
    // Part 2: Grand staff realization (2 staves)
    // -------------------------------------------------------------------------

    private function buildRealizationMeasure(\DOMDocument $dom, Measure $measure, Score $score, bool $isFirst): \DOMElement
    {
        $el = $dom->createElement('measure');
        $el->setAttribute('number', (string) $measure->number);

        if ($isFirst) {
            $el->appendChild($this->buildGrandStaffAttributes($dom, $score));
        } elseif ($measure->keySignature !== null) {
            $el->appendChild($this->buildKeyChangeAttributes($dom, $measure));
        }

        foreach ($measure->bassNotes as $i => $bassNote) {
            $chord    = $measure->realizedChords[$i] ?? null;
            $totalDur = $this->durationTicks($bassNote->duration, $score->divisions);

            if ($chord === null || $bassNote->isRest()) {
                // Rest in all voices
                $this->appendRest($el, $dom, $bassNote, 1, 1, $score->divisions, $totalDur, false);
                $this->appendRest($el, $dom, $bassNote, 4, 2, $score->divisions, $totalDur, true);
                continue;
            }

            // Upper voices (soprano→alto→tenor), sorted highest first
            $upperVoices = array_reverse($chord->upperVoices);

            // ── Staff 1 (treble): voices 1, 2, 3 ──────────────────────────
            foreach ($upperVoices as $vi => $upperNote) {
                $voiceNum = $vi + 1;        // 1=soprano, 2=alto, 3=tenor
                if ($vi > 0) {
                    $this->appendBackup($el, $dom, $totalDur);
                }
                $el->appendChild($this->noteElement($dom, $upperNote, $voiceNum, $score->divisions, 1));
            }

            // ── Staff 2 (bass): voice 4 ────────────────────────────────────
            $this->appendBackup($el, $dom, $totalDur);
            $el->appendChild($this->noteElement($dom, $bassNote, 4, $score->divisions, 2));
            if (!empty($bassNote->figuredBass)) {
                $el->appendChild($this->figuredBassElement($dom, $bassNote->figuredBass));
            }
        }

        return $el;
    }

    // -------------------------------------------------------------------------
    // Attribute builders
    // -------------------------------------------------------------------------

    private function buildBassAttributes(\DOMDocument $dom, Score $score): \DOMElement
    {
        $attrs = $dom->createElement('attributes');
        $attrs->appendChild($dom->createElement('divisions', (string) $score->divisions));
        $attrs->appendChild($this->keyElement($dom, $score->keyFifths, $score->keyMode));
        $attrs->appendChild($this->timeElement($dom, $score->beats, $score->beatType));
        $clef = $dom->createElement('clef');
        $clef->appendChild($dom->createElement('sign', 'F'));
        $clef->appendChild($dom->createElement('line', '4'));
        $attrs->appendChild($clef);
        return $attrs;
    }

    private function buildGrandStaffAttributes(\DOMDocument $dom, Score $score): \DOMElement
    {
        $attrs = $dom->createElement('attributes');
        $attrs->appendChild($dom->createElement('divisions', (string) $score->divisions));
        $attrs->appendChild($this->keyElement($dom, $score->keyFifths, $score->keyMode));
        $attrs->appendChild($this->timeElement($dom, $score->beats, $score->beatType));
        $attrs->appendChild($dom->createElement('staves', '2'));

        // Treble clef — staff 1 (soprano / alto / tenor)
        $clef1 = $dom->createElement('clef');
        $clef1->setAttribute('number', '1');
        $clef1->appendChild($dom->createElement('sign', 'G'));
        $clef1->appendChild($dom->createElement('line', '2'));
        $attrs->appendChild($clef1);

        // Bass clef — staff 2 (bass voice)
        $clef2 = $dom->createElement('clef');
        $clef2->setAttribute('number', '2');
        $clef2->appendChild($dom->createElement('sign', 'F'));
        $clef2->appendChild($dom->createElement('line', '4'));
        $attrs->appendChild($clef2);

        return $attrs;
    }

    private function buildKeyChangeAttributes(\DOMDocument $dom, Measure $measure): \DOMElement
    {
        $attrs = $dom->createElement('attributes');
        $fifths = $measure->keySignature['fifths'] ?? 0;
        $mode   = $measure->keySignature['mode']   ?? 'major';
        $attrs->appendChild($this->keyElement($dom, $fifths, $mode));
        return $attrs;
    }

    // -------------------------------------------------------------------------
    // Element helpers
    // -------------------------------------------------------------------------

    private function keyElement(\DOMDocument $dom, int $fifths, string $mode): \DOMElement
    {
        $key = $dom->createElement('key');
        $key->appendChild($dom->createElement('fifths', (string) $fifths));
        $key->appendChild($dom->createElement('mode', $mode));
        return $key;
    }

    private function timeElement(\DOMDocument $dom, int $beats, int $beatType): \DOMElement
    {
        $time = $dom->createElement('time');
        $time->appendChild($dom->createElement('beats', (string) $beats));
        $time->appendChild($dom->createElement('beat-type', (string) $beatType));
        return $time;
    }

    /**
     * Build a <note> element with an explicit <staff> number (for grand staff).
     */
    private function noteElement(\DOMDocument $dom, Note $note, int $voice, int $divisions, int $staff = 1): \DOMElement
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

        $el->appendChild($dom->createElement('duration', (string) $this->durationTicks($note->duration, $divisions)));
        $el->appendChild($dom->createElement('voice', (string) $voice));
        $el->appendChild($dom->createElement('type', $note->type ?: 'quarter'));

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

        $el->appendChild($dom->createElement('staff', (string) $staff));

        return $el;
    }

    /**
     * Build a <figured-bass> element from an array of ['number'=>int, 'alter'=>int] figures.
     * In MusicXML, <figured-bass> is a sibling of <note> placed immediately after it.
     */
    private function figuredBassElement(\DOMDocument $dom, array $figures): \DOMElement
    {
        $fb = $dom->createElement('figured-bass');
        foreach ($figures as $fig) {
            $num   = $fig['number'] ?? 0;
            $alter = $fig['alter']  ?? 0;
            if ($num <= 0) {
                continue;
            }
            $figEl = $dom->createElement('figure');
            if ($alter !== 0) {
                $acc = $alter > 0 ? 'sharp' : 'flat';
                $figEl->appendChild($dom->createElement('prefix', $acc));
            }
            $figEl->appendChild($dom->createElement('figure-number', (string) $num));
            $fb->appendChild($figEl);
        }
        return $fb;
    }

    private function appendBackup(\DOMElement $parent, \DOMDocument $dom, int $ticks): void
    {
        $backup = $dom->createElement('backup');
        $backup->appendChild($dom->createElement('duration', (string) $ticks));
        $parent->appendChild($backup);
    }

    private function appendRest(
        \DOMElement  $parent,
        \DOMDocument $dom,
        Note         $template,
        int          $voice,
        int          $staff,
        int          $divisions,
        int          $totalDur,
        bool         $needsBackup
    ): void {
        if ($needsBackup) {
            $this->appendBackup($parent, $dom, $totalDur);
        }
        $rest = new Note('C', 4, $template->duration, 0, $template->type, true, null, $voice);
        $parent->appendChild($this->noteElement($dom, $rest, $voice, $divisions, $staff));
    }

    private function durationTicks(float $durationInQuarters, int $divisions): int
    {
        return (int) round($durationInQuarters * $divisions);
    }
}
