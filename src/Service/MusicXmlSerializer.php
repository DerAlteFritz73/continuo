<?php

namespace App\Service;

use App\Model\Chord;
use App\Model\Measure;
use App\Model\Note;
use App\Model\Score;

/**
 * Serializes a realized Score back to MusicXML format.
 *
 * Output structure (MusicXML 4.0) — single grand-staff part:
 *  Staff 1 / treble: Voice 1 — soprano + any other upper voice whose consolidated
 *                    duration matches soprano at the same beat (shared stem chord).
 *                    Voice 2 — unplaced alto entries (consolidated duration differed
 *                    from soprano), written with <forward> gaps.
 *                    Voice 3 — unplaced tenor entries, same treatment.
 *  Staff 2 / bass:   Voice 4 — original bass note with figured bass markings.
 *
 * Per-voice consolidation (consolidateVoicePart) runs independently for soprano,
 * alto, and tenor.  At each beat position the durations are compared:
 *  • same duration → chord member in voice 1 (single stem)
 *  • different duration → deferred to voice 2 (alto) or voice 3 (tenor)
 *
 * Keeping alto and tenor in separate MusicXML voices avoids the time-overlap
 * problem that arises when consolidated alto and tenor entries span different
 * beat ranges: a single-voice stream is always non-overlapping, so a simple
 * forward-only cursor is sufficient.
 *
 * Measure layout:
 *  Pass 1: voice 1 (soprano + chord-grouped alto/tenor) → (cursor at measureDur)
 *  Pass 2: backup → voice 2 (unplaced alto, <forward> gaps) → (cursor at v2End)
 *           (omitted when all alto notes are chord-grouped)
 *  Pass 3: backup → voice 3 (unplaced tenor, <forward> gaps) → (cursor at v3End)
 *           (omitted when all tenor notes are chord-grouped)
 *  Pass 4: backup → bass voice 4 (no trailing backup)
 *
 * Click-tracking xml:ids:
 *  Soprano notes carry xml:id="chord-{N}" (N = global chord-store index).
 *  Bass notes carry xml:id="bass-{N}".
 *  These are used by the JS chord inspector instead of counting .chord elements.
 *
 * Figure coloring:
 *  - Figures from the input score ("file"): default black
 *  - Figures computed by the decision tree: COMPUTED_FIGURE_COLOR (muted indigo)
 *
 * Beaming:
 *  - Notes shorter than the beat unit are beamed in groups per beat.
 *  - Compound meters (6/8, 9/8, 12/8): beam in dotted-quarter groups (3 eighths).
 *  - Simple meters: beam within each beat.
 *  - Rests break beam groups.
 */
class MusicXmlSerializer
{
    /** Part id used for the preserved melody (flute) part in the output. */
    private const MELODY_PART_ID = 'PMEL';

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

        // --- Melody part (optional): the original flute/violin line, rendered as
        //     a top staff above the realization. Prepared first so it can be
        //     listed and placed ABOVE the realization part. ---
        $melodyPart = null;
        if ($score->melodyPartXml !== null) {
            $melodyPart = $this->buildMelodyPart($dom, $score->melodyPartXml);
        }

        // --- Part list ---
        $partList = $dom->createElement('part-list');

        if ($melodyPart !== null) {
            $spM = $dom->createElement('score-part');
            $spM->setAttribute('id', self::MELODY_PART_ID);
            $spM->appendChild($dom->createElement('part-name', $score->melodyPartName ?: 'Melody'));
            $partList->appendChild($spM);
        }

        $sp1 = $dom->createElement('score-part');
        $sp1->setAttribute('id', 'P1');
        $sp1->appendChild($dom->createElement('part-name', 'Realization'));
        $instr = $dom->createElement('score-instrument');
        $instr->setAttribute('id', 'P1-I1');
        $instr->appendChild($dom->createElement('instrument-name', 'Harpsichord'));
        $sp1->appendChild($instr);
        $partList->appendChild($sp1);

        $root->appendChild($partList);

        // Melody part goes into the body BEFORE the realization so it renders on top.
        if ($melodyPart !== null) {
            $root->appendChild($melodyPart);
        }

        // --- Single part: realized continuo (grand staff, bass + upper voices) ---
        $part1 = $dom->createElement('part');
        $part1->setAttribute('id', 'P1');
        $root->appendChild($part1);

        $isFirst          = true;
        $currentKeyFifths = $score->keyFifths;
        $currentBeats     = $score->beats;
        $currentBeatType  = $score->beatType;
        $globalIdx        = 0; // threaded global chord-store index

        foreach ($score->measures as $measure) {
            if ($measure->keySignature !== null) {
                $currentKeyFifths = $measure->keySignature['fifths'];
            }
            if ($measure->timeSignature !== null) {
                $currentBeats    = $measure->timeSignature['beats'];
                $currentBeatType = $measure->timeSignature['beatType'];
            }
            $part1->appendChild($this->buildRealizationMeasure(
                $dom, $measure, $score, $isFirst,
                $currentKeyFifths, $currentBeats, $currentBeatType,
                $globalIdx
            ));
            $isFirst = false;
        }

        return $dom->saveXML();
    }

    // -------------------------------------------------------------------------
    // Melody (flute) part — preserved verbatim from the source
    // -------------------------------------------------------------------------

    /**
     * Import the preserved melody-part MusicXML into the output document as a
     * <part>, re-id'd to MELODY_PART_ID and tagged with per-note xml:ids
     * ("flute-{measureNumber}-{index}") so the viewer can tint each note by the
     * phrase it belongs to. Returns null if the fragment can't be parsed.
     */
    private function buildMelodyPart(\DOMDocument $dom, string $melodyXml): ?\DOMElement
    {
        $src = new \DOMDocument();
        // The fragment is trusted (produced by our own parser from the upload),
        // but suppress libxml warnings on any stray entities.
        if (!@$src->loadXML($melodyXml)) {
            return null;
        }
        $srcPart = $src->documentElement;
        if ($srcPart === null || $srcPart->nodeName !== 'part') {
            return null;
        }

        /** @var \DOMElement $part */
        $part = $dom->importNode($srcPart, true);
        $part->setAttribute('id', self::MELODY_PART_ID);

        // Tag each sounding note so the front-end can colour it per phrase.
        // Rests get no id (nothing to tint).
        foreach ($part->getElementsByTagName('measure') as $measureEl) {
            $mnum = $measureEl->getAttribute('number');
            // Tag the measure itself so the viewer can draw a per-phrase
            // background band using its rendered bounding box.
            $measureEl->setAttribute('id', 'meas-' . $mnum);
            $i    = 0;
            foreach (iterator_to_array($measureEl->getElementsByTagName('note')) as $noteEl) {
                /** @var \DOMElement $noteEl */
                $isRest = $noteEl->getElementsByTagName('rest')->length > 0;
                if (!$isRest) {
                    // MusicXML notes carry a native `id` (xs:ID) which Verovio
                    // preserves onto the rendered SVG element — unlike `xml:id`.
                    $noteEl->setAttribute('id', 'flute-' . $mnum . '-' . $i);
                }
                $i++;
            }
        }

        return $part;
    }

    // -------------------------------------------------------------------------
    // Public: clean bass-line MusicXML (single staff, with correct accidentals)
    // Used as the "input" display sent to Verovio so that chromatic alterations
    // absent from the source file's <accidental> elements are shown correctly.
    // -------------------------------------------------------------------------

    public function serializeBassLine(Score $score): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('score-partwise');
        $root->setAttribute('version', '4.0');
        $dom->appendChild($root);

        if ($score->title) {
            $work = $dom->createElement('work');
            $work->appendChild($dom->createElement('work-title', htmlspecialchars($score->title)));
            $root->appendChild($work);
        }

        $partList = $dom->createElement('part-list');
        $sp = $dom->createElement('score-part');
        $sp->setAttribute('id', 'P1');
        $sp->appendChild($dom->createElement('part-name', 'Bass'));
        $partList->appendChild($sp);
        $root->appendChild($partList);

        $part = $dom->createElement('part');
        $part->setAttribute('id', 'P1');
        $root->appendChild($part);

        $isFirst          = true;
        $currentKeyFifths = $score->keyFifths;
        $currentKeyMode   = $score->keyMode;
        $currentBeats     = $score->beats;
        $currentBeatType  = $score->beatType;

        foreach ($score->measures as $measure) {
            if ($measure->keySignature !== null) {
                $currentKeyFifths = $measure->keySignature['fifths'];
                $currentKeyMode   = $measure->keySignature['mode'] ?? $currentKeyMode;
            }
            if ($measure->timeSignature !== null) {
                $currentBeats    = $measure->timeSignature['beats'];
                $currentBeatType = $measure->timeSignature['beatType'];
            }
            $part->appendChild($this->buildBassMeasureClean(
                $dom, $measure, $score, $isFirst,
                $currentKeyFifths, $currentKeyMode,
                $currentBeats, $currentBeatType
            ));
            $isFirst = false;
        }

        return $dom->saveXML();
    }

    // -------------------------------------------------------------------------
    // Part 1: Bass line (single staff, bass clef) — for original display
    // -------------------------------------------------------------------------

    private function buildBassMeasureClean(
        \DOMDocument $dom,
        Measure      $measure,
        Score        $score,
        bool         $isFirst,
        int          $keyFifths,
        string       $keyMode,
        int          $beats,
        int          $beatType
    ): \DOMElement {
        $el = $dom->createElement('measure');
        $el->setAttribute('number', (string) $measure->number);

        if ($isFirst) {
            $attrs = $dom->createElement('attributes');
            $attrs->appendChild($dom->createElement('divisions', (string) $score->divisions));
            $attrs->appendChild($this->keyElement($dom, $keyFifths, $keyMode));
            $attrs->appendChild($this->timeElement($dom, $score->beats, $score->beatType));
            $clef = $dom->createElement('clef');
            $clef->appendChild($dom->createElement('sign', 'F'));
            $clef->appendChild($dom->createElement('line', '4'));
            $attrs->appendChild($clef);
            $el->appendChild($attrs);
        } elseif ($measure->keySignature !== null) {
            $el->appendChild($this->buildKeyChangeAttributes($dom, $measure));
        }

        $acc           = [];
        $bassBeamItems = [];

        foreach ($measure->bassNotes as $note) {
            $accidental = $this->resolveAccidental($note, $acc, $keyFifths);
            $dur        = $this->durationTicks($note->duration, $score->divisions);
            $noteEl = $this->noteElement($dom, $note, 1, $score->divisions, 1, false, $accidental);
            $el->appendChild($noteEl);
            // Figured-bass must appear AFTER its note so that on re-submission the
            // parser's $lastNoteIdx approach (which attaches figures to the preceding note)
            // correctly picks them up.  modifyFiguredBassInXml() also expects this order.
            if (!empty($note->figuredBass)) {
                $el->appendChild($this->figuredBassElement($dom, $note->figuredBass));
            }

            $bassBeamItems[] = ['el' => $noteEl, 'dur' => $dur, 'isRest' => $note->isRest()];
        }

        $this->applyBeams($bassBeamItems, $beats, $beatType, $score->divisions);

        return $el;
    }

    // -------------------------------------------------------------------------
    // Part 2: Grand staff realization (2 staves, treble + bass)
    // -------------------------------------------------------------------------

    private function buildRealizationMeasure(
        \DOMDocument $dom,
        Measure      $measure,
        Score        $score,
        bool         $isFirst,
        int          $currentKeyFifths,
        int          $beats,
        int          $beatType,
        int          &$globalIdx,   // threaded global chord-store index (incremented here)
    ): \DOMElement {
        $el = $dom->createElement('measure');
        $el->setAttribute('number', (string) $measure->number);

        if ($isFirst) {
            $el->appendChild($this->buildGrandStaffAttributes($dom, $score));
        } elseif ($measure->keySignature !== null) {
            $el->appendChild($this->buildKeyChangeAttributes($dom, $measure));
        }

        $keyFifths = $currentKeyFifths;

        // Build a map: local beat index → global chord-store index.
        // Rests and unrealized beats have no global index (null).
        $origGlobalIdx = [];
        foreach ($measure->bassNotes as $i => $bassNote) {
            if (!$bassNote->isRest() && isset($measure->realizedChords[$i])) {
                $origGlobalIdx[$i] = $globalIdx++;
            } else {
                $origGlobalIdx[$i] = null;
            }
        }

        $measureDur = (int) array_sum(array_map(
            fn(Note $n) => $this->durationTicks($n->duration, $score->divisions),
            $measure->bassNotes
        ));

        // Build per-voice consolidated streams
        $sStream = $this->consolidateVoicePart($measure, 0, $score->divisions);
        $aStream = $this->consolidateVoicePart($measure, 1, $score->divisions);
        $tStream = $this->consolidateVoicePart($measure, 2, $score->divisions);

        // Index alto and tenor by their first covered origIdx so we can look them
        // up when writing soprano and decide whether to group them as a chord.
        // 'placed' = true once the entry is written in voice 1.
        $aByIdx = [];
        foreach ($aStream as $entry) {
            $aByIdx[$entry['origIdxs'][0]] = ['entry' => $entry, 'placed' => false];
        }
        $tByIdx = [];
        foreach ($tStream as $entry) {
            $tByIdx[$entry['origIdxs'][0]] = ['entry' => $entry, 'placed' => false];
        }

        // Cumulative bass-note positions (quarter-note units from measure start),
        // keyed by original bass-note index — needed to position voice-2 entries.
        $bassPos = [];
        $cumPosQ = 0.0;
        foreach ($measure->bassNotes as $i => $bn) {
            $bassPos[$i] = $cumPosQ;
            $cumPosQ    += $bn->duration;
        }

        // ── Pass 1: voice 1 (soprano primary + chord-grouped alto/tenor) ────
        //
        // Alto or tenor is added as a <chord> member when it starts at the same
        // beat as the current soprano entry AND has the same consolidated duration.
        // Entries that don't qualify are collected for the voice-2 pass below.
        $accV1        = [];
        $v1BeamItems  = [];

        foreach ($sStream as $sEntry) {
            $firstIdx = $sEntry['origIdxs'][0];
            $sDq      = $sEntry['dq'];
            $dur      = $this->durationTicks($sDq, $score->divisions);

            if ($sEntry['isRest']) {
                // Write soprano rest; also mark alto/tenor rests at this beat as placed.
                $rest   = new Note('C', 4, $sDq, 0, $sEntry['type'], true);
                $restEl = $this->noteElement($dom, $rest, 1, $score->divisions, 1,
                                             false, null, $sDq, $sEntry['type'], $sEntry['dot']);
                $el->appendChild($restEl);
                $v1BeamItems[] = ['el' => $restEl, 'dur' => $dur, 'isRest' => true];

                // Suppress alto/tenor rests from appearing in the voice-2 pass.
                if (isset($aByIdx[$firstIdx]) && $aByIdx[$firstIdx]['entry']['isRest']) {
                    $aByIdx[$firstIdx]['placed'] = true;
                }
                if (isset($tByIdx[$firstIdx]) && $tByIdx[$firstIdx]['entry']['isRest']) {
                    $tByIdx[$firstIdx]['placed'] = true;
                }
            } else {
                // Write soprano note
                $acc    = $this->resolveAccidental($sEntry['note'], $accV1, $keyFifths);
                $noteEl = $this->noteElement($dom, $sEntry['note'], 1, $score->divisions, 1,
                                             false, $acc, $sDq, $sEntry['type'], $sEntry['dot']);
                $gIdx = $origGlobalIdx[$firstIdx] ?? null;
                if ($gIdx !== null) {
                    $noteEl->setAttribute('xml:id', 'chord-' . $gIdx);
                }
                $el->appendChild($noteEl);
                $v1BeamItems[] = ['el' => $noteEl, 'dur' => $dur, 'isRest' => false];

                // Alto: chord-group if same start position and same duration
                if (isset($aByIdx[$firstIdx]) && !$aByIdx[$firstIdx]['placed']
                    && !$aByIdx[$firstIdx]['entry']['isRest']
                    && abs($aByIdx[$firstIdx]['entry']['dq'] - $sDq) < 0.001) {
                    $aEntry = $aByIdx[$firstIdx]['entry'];
                    $aAcc   = $this->resolveAccidental($aEntry['note'], $accV1, $keyFifths);
                    $el->appendChild($this->noteElement(
                        $dom, $aEntry['note'], 1, $score->divisions, 1,
                        true /* chord */, $aAcc, $sDq, $sEntry['type'], $sEntry['dot']
                    ));
                    $aByIdx[$firstIdx]['placed'] = true;
                }

                // Tenor: chord-group if same start position and same duration
                if (isset($tByIdx[$firstIdx]) && !$tByIdx[$firstIdx]['placed']
                    && !$tByIdx[$firstIdx]['entry']['isRest']
                    && abs($tByIdx[$firstIdx]['entry']['dq'] - $sDq) < 0.001) {
                    $tEntry = $tByIdx[$firstIdx]['entry'];
                    $tAcc   = $this->resolveAccidental($tEntry['note'], $accV1, $keyFifths);
                    $el->appendChild($this->noteElement(
                        $dom, $tEntry['note'], 1, $score->divisions, 1,
                        true /* chord */, $tAcc, $sDq, $sEntry['type'], $sEntry['dot']
                    ));
                    $tByIdx[$firstIdx]['placed'] = true;
                }
            }
        }
        $this->applyBeams($v1BeamItems, $beats, $beatType, $score->divisions);

        // ── Passes 2 & 3: unplaced alto (voice 2) and tenor (voice 3) ────────
        //
        // Keeping alto and tenor in separate MusicXML voices avoids time-overlap:
        // each single-voice stream is monotonically non-overlapping, so a simple
        // forward-only cursor advancement is always sufficient.

        // Helper closure: write one stream of unplaced entries as a single voice.
        // Each entry may carry a 'chordMembers' array of additional notes to write
        // as <chord/> elements immediately after the primary note.
        // Returns the cursor tick position at the end of the last note written.
        $writeVoiceStream = function(
            array $stream, int $voiceNum, array &$beamItems, ?string $stem = null
        ) use ($el, $dom, $score, $keyFifths): int {
            $accState = [];
            $posTicks = 0;
            foreach ($stream as $u) {
                $entry       = $u['entry'];
                $targetTicks = $this->durationTicks($u['pos'], $score->divisions);
                if ($targetTicks > $posTicks) {
                    $fwd = $dom->createElement('forward');
                    $fwd->appendChild($dom->createElement('duration', (string) ($targetTicks - $posTicks)));
                    $el->appendChild($fwd);
                    $posTicks = $targetTicks;
                }
                $acc    = $this->resolveAccidental($entry['note'], $accState, $keyFifths);
                $noteEl = $this->noteElement(
                    $dom, $entry['note'], $voiceNum, $score->divisions, 1,
                    false, $acc, $entry['dq'], $entry['type'], $entry['dot'], $stem
                );
                $el->appendChild($noteEl);
                $dur        = $this->durationTicks($entry['dq'], $score->divisions);
                $beamItems[] = ['el' => $noteEl, 'dur' => $dur, 'isRest' => false];
                $posTicks   += $dur;

                // Coincident chord members (same position, same duration, different voice source)
                foreach ($u['chordMembers'] ?? [] as $cm) {
                    $cmAcc = $this->resolveAccidental($cm['note'], $accState, $keyFifths);
                    $el->appendChild($this->noteElement(
                        $dom, $cm['note'], $voiceNum, $score->divisions, 1,
                        true /* chord */, $cmAcc, $cm['dq'], $cm['type'], $cm['dot']
                    ));
                }
            }
            return $posTicks;
        };

        // Collect unplaced alto and tenor separately (sorted by measure position).
        $collectUnplaced = function(array $byIdx) use ($bassPos): array {
            $list = [];
            foreach ($byIdx as $firstIdx => $info) {
                if (!$info['placed'] && !$info['entry']['isRest']) {
                    $list[] = ['pos' => $bassPos[$firstIdx], 'entry' => $info['entry'],
                               'chordMembers' => []];
                }
            }
            usort($list, fn($a, $b) => $a['pos'] <=> $b['pos']);
            return $list;
        };

        $unplacedAlto  = $collectUnplaced($aByIdx);
        $unplacedTenor = $collectUnplaced($tByIdx);

        // Merge tenor entries into voice 2 (alto) as chord members when they are
        // at the same position with the same consolidated duration.  Such entries
        // don't need a separate voice and combining them makes the notation cleaner.
        // Tenor entries that don't match any alto entry remain in $unplacedTenor.
        $altoByPos = [];
        foreach ($unplacedAlto as $k => $u) {
            $altoByPos[number_format($u['pos'], 6)] = $k;
        }
        $remainingTenor = [];
        foreach ($unplacedTenor as $tu) {
            $key = number_format($tu['pos'], 6);
            if (isset($altoByPos[$key])) {
                $ak = $altoByPos[$key];
                if (abs($unplacedAlto[$ak]['entry']['dq'] - $tu['entry']['dq']) < 0.001) {
                    $unplacedAlto[$ak]['chordMembers'][] = $tu['entry'];
                    continue; // absorbed into voice 2
                }
            }
            $remainingTenor[] = $tu;
        }
        $unplacedTenor = $remainingTenor;

        // $lastVoiceEnd tracks where the cursor sits after each pass so we can
        // issue the correct backup before the next pass.
        $lastVoiceEnd = $measureDur; // voice 1 ends at measureDur

        // Pass 2 — voice 2 (unplaced alto)
        if (!empty($unplacedAlto)) {
            $this->appendBackup($el, $dom, $lastVoiceEnd);
            $v2BeamItems  = [];
            $lastVoiceEnd = $writeVoiceStream($unplacedAlto, 2, $v2BeamItems);
            $this->applyBeams($v2BeamItems, $beats, $beatType, $score->divisions);
        }

        // Pass 3 — voice 3 (unplaced tenor); stems explicitly down per convention
        if (!empty($unplacedTenor)) {
            $this->appendBackup($el, $dom, $lastVoiceEnd);
            $v3BeamItems  = [];
            $lastVoiceEnd = $writeVoiceStream($unplacedTenor, 3, $v3BeamItems, 'down');
            $this->applyBeams($v3BeamItems, $beats, $beatType, $score->divisions);
        }

        // Final backup to measure start for the bass pass.
        $this->appendBackup($el, $dom, $lastVoiceEnd);

        // ── Pass 4: bass (voice 4, staff 2) ─────────────────────────────────
        // Bass notes carry xml:id="bass-{N}" for hover-rect pairing in the JS.
        $accB          = [];
        $bassBeamItems = [];

        foreach ($measure->bassNotes as $i => $bassNote) {
            $chord = $measure->realizedChords[$i] ?? null;
            $dur   = $this->durationTicks($bassNote->duration, $score->divisions);

            if ($chord === null || $bassNote->isRest()) {
                $rest   = new Note('C', 4, $bassNote->duration, 0, $bassNote->type, true);
                $restEl = $this->noteElement($dom, $rest, 4, $score->divisions, 2);
                $el->appendChild($restEl);
                $bassBeamItems[] = ['el' => $restEl, 'dur' => $dur, 'isRest' => true];
            } else {
                // <figured-bass> precedes its note (MusicXML spec: positioned at next note).
                if (!empty($chord->figures)) {
                    $el->appendChild($this->figuredBassElement($dom, $chord->figures, true));
                }
                $acc    = $this->resolveAccidental($bassNote, $accB, $keyFifths);
                $noteEl = $this->noteElement($dom, $bassNote, 4, $score->divisions, 2, false, $acc);
                $gIdx   = $origGlobalIdx[$i] ?? null;
                if ($gIdx !== null) {
                    $noteEl->setAttribute('xml:id', 'bass-' . $gIdx);
                }
                $el->appendChild($noteEl);
                $bassBeamItems[] = ['el' => $noteEl, 'dur' => $dur, 'isRest' => false];
            }
        }
        $this->applyBeams($bassBeamItems, $beats, $beatType, $score->divisions);

        return $el;
    }

    // -------------------------------------------------------------------------
    // Note consolidation (repeated pitches → longer values)
    // -------------------------------------------------------------------------

    /**
     * Map a duration in quarter-note units to a MusicXML note type + dot flag.
     * Returns null when the duration cannot be represented as a simple or dotted value.
     *
     * @return array{type:string,dot:bool}|null
     */
    private function consolidatedType(float $dur): ?array
    {
        $dur = round($dur, 6);
        return match($dur) {
            6.0   => ['type' => 'whole',   'dot' => true],
            4.0   => ['type' => 'whole',   'dot' => false],
            3.0   => ['type' => 'half',    'dot' => true],
            2.0   => ['type' => 'half',    'dot' => false],
            1.5   => ['type' => 'quarter', 'dot' => true],
            1.0   => ['type' => 'quarter', 'dot' => false],
            0.75  => ['type' => 'eighth',  'dot' => true],
            0.5   => ['type' => 'eighth',  'dot' => false],
            0.375 => ['type' => '16th',    'dot' => true],
            0.25  => ['type' => '16th',    'dot' => false],
            0.125 => ['type' => '32nd',    'dot' => false],
            default => null,
        };
    }

    /**
     * Build and consolidate a single upper-voice stream for the treble staff.
     *
     * $voiceIndex: 0=soprano, 1=alto, 2=tenor
     *   (index into array_reverse($chord->upperVoices), so 0 = highest pitch)
     *
     * Consecutive slots with the same MIDI pitch are merged into one longer
     * note provided the combined duration maps to a representable type.
     * Rests and missing-chord slots are emitted individually.
     *
     * @return array<array{note:?Note,dq:float,type:string,dot:bool,origIdxs:int[],isRest:bool}>
     */
    private function consolidateVoicePart(Measure $measure, int $voiceIndex, int $divisions): array
    {
        // Build raw entries: one per bass note
        $raw = [];
        foreach ($measure->bassNotes as $i => $bassNote) {
            $chord = $measure->realizedChords[$i] ?? null;
            if ($chord === null || $bassNote->isRest()) {
                $raw[] = ['note' => null, 'midi' => null, 'dq' => $bassNote->duration,
                          'type' => $bassNote->type, 'origIdx' => $i];
            } else {
                // upperVoices reversed → [soprano=0, alto=1, tenor=2]
                $reversed = array_reverse($chord->upperVoices);
                $note     = $reversed[$voiceIndex] ?? null;
                $raw[]    = ['note' => $note,
                             'midi' => $note ? $note->midiPitch() : null,
                             'dq'   => $bassNote->duration,
                             'type' => $bassNote->type,
                             'origIdx' => $i];
            }
        }

        $result = [];
        $n      = count($raw);
        $i      = 0;

        while ($i < $n) {
            $entry = $raw[$i];

            // Rests / missing notes pass through unchanged
            if ($entry['midi'] === null) {
                $ti       = $this->consolidatedType($entry['dq']) ?? ['type' => $entry['type'], 'dot' => false];
                $result[] = ['note' => $entry['note'], 'dq' => $entry['dq'],
                             'type' => $ti['type'], 'dot' => $ti['dot'],
                             'origIdxs' => [$entry['origIdx']], 'isRest' => true];
                $i++;
                continue;
            }

            // Find the extent of the run (same MIDI pitch, no rest interruption)
            $runEnd = $i + 1;
            while ($runEnd < $n
                   && $raw[$runEnd]['midi'] !== null
                   && $raw[$runEnd]['midi'] === $entry['midi']) {
                $runEnd++;
            }

            if ($runEnd === $i + 1) {
                // Single note — no run to merge
                $ti       = $this->consolidatedType($entry['dq']) ?? ['type' => $entry['type'], 'dot' => false];
                $result[] = ['note' => $entry['note'], 'dq' => $entry['dq'],
                             'type' => $ti['type'], 'dot' => $ti['dot'],
                             'origIdxs' => [$entry['origIdx']], 'isRest' => false];
                $i++;
                continue;
            }

            // Multiple notes in run — try to consolidate
            $totalDq  = 0.0;
            $origIdxs = [];
            for ($k = $i; $k < $runEnd; $k++) {
                $totalDq   += $raw[$k]['dq'];
                $origIdxs[] = $raw[$k]['origIdx'];
            }

            $ti = $this->consolidatedType($totalDq);
            if ($ti !== null) {
                // Merge the whole run into one note
                $result[] = ['note' => $entry['note'], 'dq' => $totalDq,
                             'type' => $ti['type'], 'dot' => $ti['dot'],
                             'origIdxs' => $origIdxs, 'isRest' => false];
                $i = $runEnd;
            } else {
                // Cannot represent total — emit individually
                for ($k = $i; $k < $runEnd; $k++) {
                    $rk  = $raw[$k];
                    $tiK = $this->consolidatedType($rk['dq']) ?? ['type' => $rk['type'], 'dot' => false];
                    $result[] = ['note' => $rk['note'], 'dq' => $rk['dq'],
                                 'type' => $tiK['type'], 'dot' => $tiK['dot'],
                                 'origIdxs' => [$rk['origIdx']], 'isRest' => false];
                }
                $i = $runEnd;
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Beaming
    // -------------------------------------------------------------------------

    /**
     * Add <beam number="1"> begin/continue/end elements to beamable note sequences.
     *
     * A note is beamable when its duration in ticks is strictly less than one
     * quarter note (i.e. eighth notes, sixteenth notes, etc.).
     *
     * Beam grouping rules:
     *  - Compound meters (6/8, 9/8, 12/8): group = dotted quarter (3 eighth notes)
     *  - All other meters: group = one beat (quarter × 4/beatType ticks)
     *  - Rests and quarter-or-longer notes break the beam.
     *
     * @param list<array{el: \DOMElement|null, dur: int, isRest: bool}> $items
     */
    private function applyBeams(array $items, int $beats, int $beatType, int $divisions): void
    {
        if (count($items) < 2) {
            return;
        }

        $ticksPerQuarter = $divisions;

        // Compound time: beatType=8 with an even number of beats >= 6 divisible by 3
        $isCompound = ($beatType === 8 && $beats >= 6 && ($beats % 3) === 0);

        // Ticks per beam group
        $groupTicks = $isCompound
            ? (int) round($ticksPerQuarter * 1.5)                        // dotted quarter
            : (int) round($ticksPerQuarter * 4.0 / max(1, $beatType));   // one beat

        // Walk through items: tag each with its group index and beamability
        $pos       = 0;
        $annotated = [];
        foreach ($items as $item) {
            $annotated[] = [
                'el'       => $item['el'],
                'dur'      => $item['dur'],
                'isRest'   => $item['isRest'],
                'groupIdx' => $groupTicks > 0 ? (int) floor($pos / $groupTicks) : 0,
                'beamable' => !$item['isRest']
                              && $item['el'] !== null
                              && $item['dur'] < $ticksPerQuarter, // shorter than quarter
            ];
            $pos += $item['dur'];
        }

        // Find consecutive runs of beamable notes in the same group, then mark them
        $n = count($annotated);
        $i = 0;
        while ($i < $n) {
            if (!$annotated[$i]['beamable']) {
                $i++;
                continue;
            }

            // Extend the run while same group and still beamable
            $gIdx = $annotated[$i]['groupIdx'];
            $j    = $i;
            while ($j + 1 < $n
                && $annotated[$j + 1]['beamable']
                && $annotated[$j + 1]['groupIdx'] === $gIdx) {
                $j++;
            }

            if ($j > $i) {
                // At least two notes — add beam markings
                for ($k = $i; $k <= $j; $k++) {
                    $noteEl = $annotated[$k]['el'];
                    $value  = ($k === $i) ? 'begin' : (($k === $j) ? 'end' : 'continue');
                    $beamEl = $noteEl->ownerDocument->createElement('beam', $value);
                    $beamEl->setAttribute('number', '1');
                    $noteEl->appendChild($beamEl);
                }
            }

            $i = $j + 1;
        }
    }

    // -------------------------------------------------------------------------
    // Attribute builders
    // -------------------------------------------------------------------------

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
        $attrs  = $dom->createElement('attributes');
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
     * Build a <note> element.
     *
     * Element order follows MusicXML 4.0 spec:
     *   chord?, rest|pitch, duration, voice, type, accidental?, staff
     *   [beam elements appended later by applyBeams()]
     *
     * $chordMember = true inserts <chord> as first child (simultaneous note).
     * $explicitAccidental overrides automatic accidental logic.
     */
    private function noteElement(
        \DOMDocument $dom,
        Note         $note,
        int          $voice,
        int          $divisions,
        int          $staff              = 1,
        bool         $chordMember        = false,
        ?string      $explicitAccidental = null,
        ?float       $durationOverride   = null,  // consolidated duration (quarters)
        ?string      $typeOverride       = null,  // consolidated MusicXML type string
        bool         $dot               = false,  // whether to emit a <dot> element
        ?string      $stem              = null,   // 'up' | 'down' | null = renderer default
    ): \DOMElement {
        $el = $dom->createElement('note');

        if ($chordMember) {
            $el->appendChild($dom->createElement('chord'));
        }

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

        $effectiveDur  = $durationOverride ?? $note->duration;
        $effectiveType = $typeOverride      ?? ($note->type ?: 'quarter');

        $el->appendChild($dom->createElement('duration', (string) $this->durationTicks($effectiveDur, $divisions)));
        $el->appendChild($dom->createElement('voice', (string) $voice));
        $el->appendChild($dom->createElement('type', $effectiveType));
        if ($dot) {
            $el->appendChild($dom->createElement('dot'));
        }

        if (!$note->isRest()) {
            if ($explicitAccidental !== null) {
                $el->appendChild($dom->createElement('accidental', $explicitAccidental));
            } elseif ($note->alter !== 0) {
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
        }

        // <stem> (optional) and <staff> come before <beam> in MusicXML —
        // beam elements are appended later by applyBeams(), preserving order.
        if ($stem !== null && !$note->isRest()) {
            $el->appendChild($dom->createElement('stem', $stem));
        }
        $el->appendChild($dom->createElement('staff', (string) $staff));

        return $el;
    }

    /**
     * Determine the default alter a key signature applies to a step.
     * Returns 1 (sharp), -1 (flat), or 0 (natural).
     */
    private function keyAlterForStep(string $step, int $keyFifths): int
    {
        if ($keyFifths > 0) {
            $sharps = array_slice(['F', 'C', 'G', 'D', 'A', 'E', 'B'], 0, $keyFifths);
            return in_array($step, $sharps, true) ? 1 : 0;
        }
        if ($keyFifths < 0) {
            $flats = array_slice(['B', 'E', 'A', 'D', 'G', 'C', 'F'], 0, -$keyFifths);
            return in_array($step, $flats, true) ? -1 : 0;
        }
        return 0;
    }

    /**
     * Given a note and the current in-measure accidental tracker for its staff,
     * return the explicit accidental string to emit (or null if none needed),
     * and update the tracker.
     *
     * @param array<string,int> &$tracker  step → currently-active alter in this measure
     */
    private function resolveAccidental(Note $note, array &$tracker, int $keyFifths): ?string
    {
        if ($note->isRest()) {
            return null;
        }

        $keyAlter    = $this->keyAlterForStep($note->step, $keyFifths);
        $activeAlter = $tracker[$note->step] ?? $keyAlter;

        if ($note->alter === $activeAlter) {
            return null; // matches current state — no accidental needed
        }

        $tracker[$note->step] = $note->alter;

        return match($note->alter) {
            1  => 'sharp',
            -1 => 'flat',
            2  => 'double-sharp',
            -2 => 'flat-flat',
            0  => 'natural',
            default => null,
        };
    }

    private function figuredBassElement(\DOMDocument $dom, array $figures, bool $abbreviate = false): \DOMElement
    {
        $fb = $dom->createElement('figured-bass');
        $fb->setAttribute('placement', 'below');
        // Conventional abbreviation: "5 3" → "5" (omit the 3), only for computed figures
        if ($abbreviate) {
            $nums = array_map(fn($f) => (int)($f['number'] ?? 0), $figures);
            $alts = array_map(fn($f) => (int)($f['alter']  ?? 0), $figures);
            if ($nums === [5, 3] && $alts === [0, 0]) {
                $figures = [['number' => 5, 'alter' => 0]];
            }
        }
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

    private function durationTicks(float $durationInQuarters, int $divisions): int
    {
        return (int) round($durationInQuarters * $divisions);
    }
}
