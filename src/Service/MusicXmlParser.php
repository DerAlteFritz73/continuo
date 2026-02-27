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
 *  - Figured bass encoded as <figured-bass> elements (MusicXML 3.x)
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

        // --- Determine which part to use as bass (lowest part) ---
        $parts = $xml->xpath('//part');
        if (empty($parts)) {
            throw new \InvalidArgumentException('No parts found in MusicXML file.');
        }

        // If multiple parts, choose the one with the lowest average pitch
        $bassPart = $this->selectBassPart($parts);

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

            // Collect figured bass for current tick position
            $figuredBassMap = [];
            foreach ($measureXml->{'figured-bass'} as $fb) {
                // MusicXML figured-bass doesn't have offset — we collect figures in order
                $figures = [];
                foreach ($fb->{'figure'} as $fig) {
                    $prefix = (string) ($fig->{'prefix'} ?? '');
                    $num    = (int) ($fig->{'figure-number'} ?? 0);
                    $suffix = (string) ($fig->{'suffix'} ?? '');

                    // Encode alterations: prefix '#' or 'b'
                    $alter = 0;
                    if ($prefix === 'sharp' || $suffix === 'sharp') {
                        $alter = 1;
                    } elseif ($prefix === 'flat' || $suffix === 'flat') {
                        $alter = -1;
                    } elseif ($prefix === 'natural') {
                        $alter = 0; // explicit natural
                    }

                    if ($num > 0) {
                        $figures[] = ['number' => $num, 'alter' => $alter];
                    }
                }
                $figuredBassMap[] = $figures;
            }

            // --- Notes ---
            $fbIndex = 0;
            $noteIndex = 0;

            foreach ($measureXml->children() as $child) {
                $name = $child->getName();

                if ($name === 'note') {
                    // Skip chords (simultaneous notes) — take only the first voice
                    $voiceElem = $child->{'voice'};
                    $voice = $voiceElem ? (int) $voiceElem : 1;
                    if ($voice !== 1) {
                        continue;
                    }

                    $isChord = isset($child->{'chord'});
                    if ($isChord) {
                        continue;
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
                            voice: $voice,
                        );
                    } else {
                        $pitch = $child->{'pitch'};
                        $step  = (string) ($pitch->{'step'} ?? 'C');
                        $oct   = (int) ($pitch->{'octave'} ?? 4);
                        $alter = (int) round((float) ($pitch->{'alter'} ?? 0));

                        // Grab figured bass for this note if any
                        $figures = $figuredBassMap[$fbIndex] ?? [];
                        if (!empty($figuredBassMap)) {
                            $fbIndex++;
                        }

                        $note = new Note(
                            step: $step,
                            octave: $oct,
                            duration: $durationInQuarters,
                            alter: $alter,
                            type: $type,
                            isRest: false,
                            voice: $voice,
                            figuredBass: $figures,
                        );
                    }

                    $measure->bassNotes[] = $note;
                    $noteIndex++;
                }
            }

            // timeSignature: record only when explicitly present (set inside attribute loop above)
            // keySignature is also set inside the loop, not here, to avoid emitting it on every measure

            if (!empty($measure->bassNotes)) {
                $score->measures[] = $measure;
            }
        }

        return $score;
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
