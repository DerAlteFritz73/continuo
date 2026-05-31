<?php

namespace App\Service;

use App\Model\Measure;
use App\Model\Note;
use App\Model\Score;
use SimpleXMLElement;

/**
 * Parses a MusicXML file and returns a Score with bass notes and any figured bass annotations.
 *
 * Supports:
 *  - Single-part scores (takes the part or last part as bass)
 *  - Multi-part scores (uses lowest pitched part as bass)
 *  - Figured bass encoded as <figured-bass> elements (MusicXML 3.x/4.x standard)
 *  - Figured bass encoded as <lyric> elements using the Figurato font (Finale exports)
 *  - Grand-staff keyboard parts: reads bass staff (staff 2) notes, not treble (staff 1)
 *  - Key and time signatures
 */
class MusicXmlParser
{
    public function parse(string $xmlContent): Score
    {
        $xml = new SimpleXMLElement($xmlContent);
        $score = new Score();

        // --- Metadata ---
        if ($xml->{'work'}->{'work-title'}) {
            $score->title = (string) $xml->{'work'}->{'work-title'};
        }
        if ($xml->{'identification'}->{'creator'}) {
            $score->composer = (string) $xml->{'identification'}->{'creator'};
        }

        // --- Detect Figurato lyric font (Finale-style figured bass via lyrics) ---
        $isFigurato = false;
        if (isset($xml->defaults)) {
            foreach ($xml->defaults->children() as $defaultChild) {
                if ($defaultChild->getName() === 'lyric-font') {
                    $family = strtolower((string) ($defaultChild['font-family'] ?? ''));
                    if (str_contains($family, 'figurato')) {
                        $isFigurato = true;
                        break;
                    }
                }
            }
        }

        // --- Determine which part to use as bass (lowest part) ---
        $parts = $xml->xpath('//part');
        if (empty($parts)) {
            throw new \InvalidArgumentException('No parts found in MusicXML file.');
        }

        // If multiple parts, choose the one with the lowest average pitch
        $bassPart = $this->selectBassPart($parts);

        // Identify the melody part: highest-pitched part other than the bass.
        // Returns null when there is only one part (no separate melody line).
        $melodyPart = $this->selectMelodyPart($parts, $bassPart);

        // --- Detect number of staves for the selected bass part ---
        // Grand-staff keyboard parts have staves=2; the bass staff is staff 2.
        $numStaves = $this->detectNumStaves($bassPart);

        // --- Global defaults ---
        $currentKey    = ['fifths' => 0, 'mode' => 'major'];
        $currentTime   = ['beats' => 4, 'beatType' => 4];
        $currentDivisions = 1;

        $score->keyFifths = 0;
        $score->keyMode   = 'major';
        $score->beats     = 4;
        $score->beatType  = 4;
        $score->divisions = 1;

        foreach ($bassPart->{'measure'} as $measureXml) {
            $measureNum = (int) $measureXml['number'];
            $measure    = new Measure($measureNum);

            // --- Attributes ---
            foreach ($measureXml->{'attributes'} as $attr) {
                if ($attr->{'divisions'}) {
                    $currentDivisions    = (int) $attr->{'divisions'};
                    $score->divisions    = $currentDivisions;
                }
                if ($attr->{'staves'}) {
                    // Update staves count if it changes mid-score (unusual but handle it)
                    $numStaves = (int) $attr->{'staves'};
                }
                if ($attr->{'key'}) {
                    $fifths = (int) $attr->{'key'}->{'fifths'};
                    $modeRaw = trim((string) ($attr->{'key'}->{'mode'} ?? ''));
                    $mode    = ($modeRaw !== '') ? $modeRaw : 'major';
                    $currentKey = ['fifths' => $fifths, 'mode' => $mode];
                    $score->keyFifths = $fifths;
                    $score->keyMode   = $mode;
                    // Record key on the measure only when explicitly present in the XML
                    $measure->keySignature = $currentKey;
                }
                if ($attr->{'time'}) {
                    $beats    = (int) $attr->{'time'}->{'beats'};
                    $beatType = (int) $attr->{'time'}->{'beat-type'};
                    $currentTime = ['beats' => $beats, 'beatType' => $beatType];
                    $score->beats    = $beats;
                    $score->beatType = $beatType;
                }
            }

            // --- Notes and figured bass (single pass, position-aware) ---
            //
            // For single-staff parts: accept voice 1 notes only (standard behaviour).
            // For grand-staff (numStaves > 1): accept notes from the bass staff
            //   (highest-numbered staff, typically staff 2), regardless of voice number.
            //
            // Figured bass may be encoded as:
            //   (a) <figured-bass> elements appearing after the note (MusicXML standard)
            //   (b) <lyric> text using the Figurato font encoding (Finale exports)

            $lastNoteIdx = null;

            foreach ($measureXml->children() as $child) {
                $name = $child->getName();

                if ($name === 'note') {
                    // --- Staff / voice filtering ---
                    $isChord = isset($child->{'chord'});
                    if ($isChord) {
                        // Chord (simultaneous) notes: skip for single-staff; for grand-staff,
                        // still skip — the primary note per beat is what we need.
                        continue;
                    }

                    if ($numStaves > 1) {
                        // Grand-staff: only read from the bass staff (highest staff number)
                        $staffNum = (int) ($child->{'staff'} ?? 1);
                        if ($staffNum !== $numStaves) {
                            continue;
                        }
                    } else {
                        // Single-staff: voice 1 only
                        $voice = (int) ($child->{'voice'} ?? 1);
                        if ($voice !== 1) {
                            continue;
                        }
                    }

                    $isRest = isset($child->{'rest'});
                    $dur    = (int) ($child->{'duration'} ?? 0);
                    $type   = (string) ($child->{'type'} ?? 'quarter');

                    $durationInQuarters = $currentDivisions > 0
                        ? round($dur / $currentDivisions, 6)
                        : 1.0;

                    if ($isRest) {
                        $note = new Note(
                            step: 'C', octave: 4,
                            duration: $durationInQuarters,
                            isRest: true,
                            type: $type,
                            voice: (int) ($child->{'voice'} ?? 1),
                        );
                    } else {
                        $pitch = $child->{'pitch'};
                        $step  = (string) ($pitch->{'step'} ?? 'C');
                        $oct   = (int) ($pitch->{'octave'} ?? 4);
                        $alter = (int) round((float) ($pitch->{'alter'} ?? 0));

                        $note = new Note(
                            step: $step,
                            octave: $oct,
                            duration: $durationInQuarters,
                            alter: $alter,
                            type: $type,
                            isRest: false,
                            voice: (int) ($child->{'voice'} ?? 1),
                        );
                    }

                    // --- Figurato lyric figured bass (Finale exports) ---
                    // When the file uses the Figurato font, <lyric> elements on bass
                    // notes carry the figured bass in Figurato encoding.
                    if ($isFigurato && !$isRest) {
                        $lyricText = $this->extractLyricText($child);
                        if ($lyricText !== '') {
                            $figures = $this->parseFiguratoString($lyricText);
                            if (!empty($figures)) {
                                $note = $note->withFiguredBass($figures);
                            }
                        }
                    }

                    $lastNoteIdx = count($measure->bassNotes);
                    $measure->bassNotes[] = $note;

                } elseif ($name === 'figured-bass' && $lastNoteIdx !== null) {
                    // MusicXML standard <figured-bass> element — parse figures and attach
                    $figures = [];
                    foreach ($child->{'figure'} as $fig) {
                        $prefix = (string) ($fig->{'prefix'} ?? '');
                        $num    = (int) ($fig->{'figure-number'} ?? 0);
                        $suffix = (string) ($fig->{'suffix'} ?? '');

                        $alter = 0;
                        if ($prefix === 'sharp' || $suffix === 'sharp') {
                            $alter = 1;
                        } elseif ($prefix === 'flat' || $suffix === 'flat') {
                            $alter = -1;
                        } elseif ($prefix === 'natural') {
                            $alter = 0;
                        }

                        if ($num > 0) {
                            $figures[] = ['number' => $num, 'alter' => $alter];
                        }
                    }

                    if (!empty($figures)) {
                        $measure->bassNotes[$lastNoteIdx] =
                            $measure->bassNotes[$lastNoteIdx]->withFiguredBass($figures);
                    }
                }
            }

            if (!empty($measure->bassNotes)) {
                $score->measures[] = $measure;
            }
        }

        // --- Second pass: annotate measures with melody notes (if available) ---
        if ($melodyPart !== null && !empty($score->measures)) {
            $this->parseMelodyIntoMeasures($melodyPart, $score->measures);
        }

        // --- Mode-detection pass ---
        // Finale (and some other editors) export minor-key pieces with mode="major"
        // and the relative major key signature (e.g. E minor → 1 sharp, mode=major).
        // Detect this by checking whether the last non-rest bass note lands on the
        // relative-minor tonic: if so, the piece is in minor.
        if ($score->keyMode === 'major' && !empty($score->measures)) {
            $lastNote = null;
            foreach (array_reverse($score->measures) as $measure) {
                foreach (array_reverse($measure->bassNotes) as $note) {
                    if (!$note->isRest()) { $lastNote = $note; break 2; }
                }
            }
            if ($lastNote !== null) {
                $majorScale      = PitchHelper::buildScale($score->keyFifths, 'major');
                $relMinorTonicPc = $majorScale[5]; // degree 6 of major = relative-minor tonic
                if ($lastNote->pitchClass() === $relMinorTonicPc) {
                    $score->keyMode = 'minor';
                    foreach ($score->measures as $measure) {
                        if (($measure->keySignature['mode'] ?? '') === 'major') {
                            $measure->keySignature['mode'] = 'minor';
                        }
                    }
                }
            }
        }

        return $score;
    }

    /**
     * Extract the text of the first <lyric> element of a note.
     * Returns '' if no lyric is present.
     */
    private function extractLyricText(SimpleXMLElement $noteXml): string
    {
        foreach ($noteXml->{'lyric'} as $lyric) {
            $text = trim((string) ($lyric->{'text'} ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    /**
     * Parse a Figurato-font figured bass string into an array of figure objects.
     *
     * Figurato encoding rules:
     *   - Digits 2–9 represent intervals/figures.
     *   - Accidentals immediately FOLLOWING a digit modify that figure:
     *       b = flat (−1),  s or # = sharp (+1),  n = natural (0, explicit),
     *       / or + = raised (+1, slash-through or augmented),  x = double-sharp (+2)
     *   - A comma ',' separates independently stacked groups.
     *   - A standalone accidental (not immediately after a digit) applies to the 3rd.
     *
     * Examples:
     *   "6"     → [{6, 0}]            "6b"    → [{6, −1}]
     *   "6/"    → [{6, +1}]   "4+"    → [{4, +1}]
     *   "65"    → [{6,0},{5,0}]       "65b"   → [{6,0},{5,−1}]
     *   "6/5b"  → [{6,+1},{5,−1}]    "643"   → [{6,0},{4,0},{3,0}]
     *   "7,b"   → [{7,0},{3,−1}]     "s"     → [{3,+1}]
     *   "4+2+"  → [{4,+1},{2,+1}]    "7ns"   → [{7,0,explicit},{3,+1}]
     *
     * @return array  [['number'=>int, 'alter'=>int, 'explicit'=>bool], ...]
     */
    public function parseFiguratoString(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $figures = [];

        // Split by comma: each segment is an independently stacked figure group.
        $groups = explode(',', $text);

        foreach ($groups as $group) {
            $group = trim($group);
            $len   = strlen($group);
            $i     = 0;

            while ($i < $len) {
                $c = $group[$i];

                if (ctype_digit($c)) {
                    $num      = (int) $c;
                    $alter    = 0;
                    $explicit = false;
                    $i++;

                    // Look for an accidental suffix immediately following this digit
                    if ($i < $len) {
                        $next = $group[$i];
                        switch ($next) {
                            case 'b':
                                $alter = -1;
                                $i++;
                                break;
                            case 's':
                            case '#':
                                $alter = 1;
                                $i++;
                                break;
                            case 'n':
                                $alter    = 0;
                                $explicit = true;
                                $i++;
                                break;
                            case '/':
                            case '+':
                                $alter = 1;
                                $i++;
                                break;
                            case 'x':
                                $alter = 2;
                                $i++;
                                break;
                        }
                    }

                    // Ignore invalid figure numbers (0, 1 are not real intervals)
                    if ($num >= 2) {
                        $fig = ['number' => $num, 'alter' => $alter];
                        if ($explicit) {
                            $fig['explicit'] = true;
                        }
                        $figures[] = $fig;
                    }
                } else {
                    // Standalone accidental — applies to the 3rd by convention
                    $alter    = 0;
                    $explicit = false;
                    switch ($c) {
                        case 'b':
                            $alter = -1;
                            break;
                        case 's':
                        case '#':
                            $alter = 1;
                            break;
                        case 'n':
                            $alter    = 0;
                            $explicit = true;
                            break;
                        case '/':
                        case '+':
                            $alter = 1;
                            break;
                        case 'x':
                            $alter = 2;
                            break;
                        default:
                            $i++;
                            continue 2; // skip unknown characters
                    }
                    $fig = ['number' => 3, 'alter' => $alter];
                    if ($explicit) {
                        $fig['explicit'] = true;
                    }
                    $figures[] = $fig;
                    $i++;
                }
            }
        }

        return $figures;
    }

    /**
     * Return the highest-pitched part other than the bass part (the melody).
     * Returns null when there is only one part (no separate melody).
     * Uses the MusicXML part id attribute to exclude the bass part.
     */
    private function selectMelodyPart(array $parts, SimpleXMLElement $bassPart): ?SimpleXMLElement
    {
        if (count($parts) <= 1) {
            return null;
        }

        $bassId   = (string) ($bassPart['id'] ?? '');
        $bestPart = null;
        $bestAvg  = PHP_INT_MIN;
        $map      = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11];

        foreach ($parts as $part) {
            if ((string) ($part['id'] ?? '') === $bassId) {
                continue; // skip the bass part
            }

            $pitches = [];
            foreach ($part->xpath('.//note[not(rest) and pitch]') as $noteXml) {
                $step  = (string) ($noteXml->pitch->step   ?? 'C');
                $oct   = (int)    ($noteXml->pitch->octave ?? 4);
                $alter = (int) round((float) ($noteXml->pitch->alter ?? 0));
                $midi  = ($oct + 1) * 12 + ($map[$step] ?? 0) + $alter;
                $pitches[] = $midi;
            }

            if (!empty($pitches)) {
                $avg = array_sum($pitches) / count($pitches);
                if ($avg > $bestAvg) {
                    $bestAvg  = $avg;
                    $bestPart = $part;
                }
            }
        }

        return $bestPart;
    }

    /**
     * Parse melody notes from $melodyPart and store them in the corresponding
     * Measure objects (matched by measure number).
     *
     * Only voice-1, non-chord, non-rest notes are recorded.  Backup/forward
     * elements are handled so that the beat offset stays correct even in
     * multi-voice melody parts.
     *
     * @param Measure[] $measures
     */
    private function parseMelodyIntoMeasures(SimpleXMLElement $melodyPart, array $measures): void
    {
        // Build a lookup: measure number → Measure object
        $measureMap = [];
        foreach ($measures as $m) {
            $measureMap[$m->number] = $m;
        }

        $pcMap            = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11];
        $currentDivisions = 1;

        foreach ($melodyPart->{'measure'} as $measureXml) {
            $measureNum = (int) $measureXml['number'];
            $measure    = $measureMap[$measureNum] ?? null;

            // Update divisions from attributes
            foreach ($measureXml->{'attributes'} as $attr) {
                if ($attr->{'divisions'}) {
                    $currentDivisions = (int) $attr->{'divisions'};
                }
            }

            $beatOffset = 0.0;

            foreach ($measureXml->children() as $child) {
                $name = $child->getName();

                if ($name === 'note') {
                    $isChord = isset($child->{'chord'});
                    $dur     = (int) ($child->{'duration'} ?? 0);
                    $dq      = $currentDivisions > 0 ? round($dur / $currentDivisions, 6) : 1.0;

                    if ($isChord) {
                        continue; // chord notes don't advance the beat cursor
                    }

                    $voice = (int) ($child->{'voice'} ?? 1);

                    if ($voice === 1 && !isset($child->{'rest'}) && $measure !== null) {
                        $pitch = $child->{'pitch'};
                        $step  = (string) ($pitch->{'step'}   ?? 'C');
                        $alter = (int) round((float) ($pitch->{'alter'} ?? 0));
                        $pc    = (($pcMap[$step] ?? 0) + $alter + 12) % 12;

                        $measure->melodyNotes[] = [
                            'offset'   => $beatOffset,
                            'duration' => $dq,
                            'pc'       => $pc,
                        ];
                    }

                    $beatOffset += $dq;

                } elseif ($name === 'backup') {
                    $dur     = (int) ($child->{'duration'} ?? 0);
                    $dq      = $currentDivisions > 0 ? $dur / $currentDivisions : 0.0;
                    $beatOffset = max(0.0, $beatOffset - $dq);

                } elseif ($name === 'forward') {
                    $dur     = (int) ($child->{'duration'} ?? 0);
                    $dq      = $currentDivisions > 0 ? $dur / $currentDivisions : 0.0;
                    $beatOffset += $dq;
                }
            }
        }
    }

    /**
     * Detect how many staves a part uses (from its first <staves> attribute element).
     * Returns 1 for single-staff parts, 2 for grand-staff keyboard parts, etc.
     */
    private function detectNumStaves(SimpleXMLElement $part): int
    {
        $staves = $part->xpath('.//attributes/staves');
        if (!empty($staves)) {
            return max(1, (int) $staves[0]);
        }
        return 1;
    }

    /**
     * Choose the part with the lowest average MIDI pitch (the bass part).
     */
    private function selectBassPart(array $parts): SimpleXMLElement
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        $bestPart  = $parts[count($parts) - 1]; // default: last part
        $bestAvg   = PHP_INT_MAX;

        foreach ($parts as $part) {
            $pitches = [];
            foreach ($part->xpath('.//note[not(rest) and pitch]') as $noteXml) {
                $map = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11];
                $step  = (string) ($noteXml->pitch->step ?? 'C');
                $oct   = (int) ($noteXml->pitch->octave ?? 4);
                $alter = (int) round((float) ($noteXml->pitch->alter ?? 0));
                $midi  = ($oct + 1) * 12 + ($map[$step] ?? 0) + $alter;
                $pitches[] = $midi;
            }

            if (!empty($pitches)) {
                $avg = array_sum($pitches) / count($pitches);
                if ($avg < $bestAvg) {
                    $bestAvg  = $avg;
                    $bestPart = $part;
                }
            }
        }

        return $bestPart;
    }
}
