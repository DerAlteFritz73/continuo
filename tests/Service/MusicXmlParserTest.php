<?php

namespace App\Tests\Service;

use App\Service\MusicXmlParser;
use PHPUnit\Framework\TestCase;

class MusicXmlParserTest extends TestCase
{
    private MusicXmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MusicXmlParser();
    }

    /**
     * Build a minimal single-staff Figurato bass part. Each entry is
     * [step|'rest', figureText|null]; every note is a quarter note.
     */
    private function figuratoScore(array $notes): string
    {
        $body = '';
        foreach ($notes as [$step, $fig]) {
            $lyric = $fig !== null ? "<lyric><text>{$fig}</text></lyric>" : '';
            if ($step === 'rest') {
                $body .= "<note><rest/><duration>1</duration><type>quarter</type><voice>1</voice>{$lyric}</note>";
            } else {
                $body .= "<note><pitch><step>{$step}</step><octave>3</octave></pitch>"
                       . "<duration>1</duration><type>quarter</type><voice>1</voice>{$lyric}</note>";
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<score-partwise version="4.0">'
            . '<defaults><lyric-font font-family="Figurato" font-size="9"/></defaults>'
            . '<part-list><score-part id="P1"><part-name>Bass</part-name></score-part></part-list>'
            . '<part id="P1"><measure number="1">'
            . '<attributes><divisions>1</divisions><key><fifths>0</fifths></key>'
            . '<time><beats>4</beats><beat-type>4</beat-type></time>'
            . '<clef><sign>F</sign><line>4</line></clef></attributes>'
            . $body
            . '</measure></part></score-partwise>';
    }

    /** @test */
    public function figure_over_a_rest_attaches_to_the_following_sounding_note(): void
    {
        // rest[6]  C[-]  D[7]  E[-]
        $score = $this->parser->parse($this->figuratoScore([
            ['rest', '6'], ['C', null], ['D', '7'], ['E', null],
        ]));

        $notes = $score->measures[0]->bassNotes;

        $this->assertTrue($notes[0]->isRest());
        $this->assertSame([], $notes[0]->figuredBass, 'figure must not stay on the rest');

        // The rest's "6" moves forward onto the next sounding note (C).
        $this->assertSame([['number' => 6, 'alter' => 0]], $notes[1]->figuredBass);
        // A note with its own figure keeps it.
        $this->assertSame([['number' => 7, 'alter' => 0]], $notes[2]->figuredBass);
        $this->assertSame([], $notes[3]->figuredBass);
    }

    /** @test */
    public function figure_over_a_trailing_rest_falls_back_to_the_preceding_note(): void
    {
        // C[-]  rest[6]   (no sounding note follows the rest)
        $score = $this->parser->parse($this->figuratoScore([
            ['C', null], ['rest', '6'],
        ]));

        $notes = $score->measures[0]->bassNotes;

        $this->assertSame([['number' => 6, 'alter' => 0]], $notes[0]->figuredBass);
        $this->assertTrue($notes[1]->isRest());
        $this->assertSame([], $notes[1]->figuredBass);
    }

    /** @test */
    public function accidental_carries_across_voices_within_a_measure_and_staff(): void
    {
        // Grand staff. Bass staff (2) voice A has an explicit C#3 on beat 1; voice B has
        // a bare C3 on the last beat that must inherit the sharp (the cross-voice <alter>
        // a buggy exporter omits). The grand-staff reader takes every staff-2 note.
        $xml = '<?xml version="1.0"?><score-partwise version="4.0">'
            . '<part-list><score-part id="P1"><part-name>Kbd</part-name></score-part></part-list>'
            . '<part id="P1"><measure number="1">'
            . '<attributes><divisions>1</divisions><key><fifths>0</fifths></key>'
            . '<time><beats>4</beats><beat-type>4</beat-type></time><staves>2</staves>'
            . '<clef number="1"><sign>G</sign><line>2</line></clef>'
            . '<clef number="2"><sign>F</sign><line>4</line></clef></attributes>'
            . '<note><rest/><duration>4</duration><type>whole</type><voice>1</voice><staff>1</staff></note>'
            . '<backup><duration>4</duration></backup>'
            . '<note><pitch><step>C</step><alter>1</alter><octave>3</octave></pitch><duration>1</duration><type>quarter</type><voice>5</voice><staff>2</staff><accidental>sharp</accidental></note>'
            . '<note><pitch><step>E</step><octave>3</octave></pitch><duration>1</duration><type>quarter</type><voice>5</voice><staff>2</staff></note>'
            . '<note><pitch><step>G</step><octave>3</octave></pitch><duration>1</duration><type>quarter</type><voice>5</voice><staff>2</staff></note>'
            . '<note><pitch><step>E</step><octave>3</octave></pitch><duration>1</duration><type>quarter</type><voice>5</voice><staff>2</staff></note>'
            . '<backup><duration>4</duration></backup>'
            . '<note><rest/><duration>3</duration><type>half</type><voice>6</voice><staff>2</staff></note>'
            . '<note><pitch><step>C</step><octave>3</octave></pitch><duration>1</duration><type>quarter</type><voice>6</voice><staff>2</staff></note>'
            . '</measure></part></score-partwise>';

        $bass = $this->parser->parse($xml)->measures[0]->bassNotes;
        $last = end($bass);

        $this->assertSame('C', $last->step);
        $this->assertSame(1, $last->alter, 'voice-B C must inherit the voice-A sharp');
    }

    /** @test */
    public function key_signature_pitches_are_not_disturbed_by_accidental_carry_over(): void
    {
        // One sharp (G major / E minor). A bare F should stay F# (key signature), with no
        // explicit accidental anywhere to trigger carry-over.
        $xml = '<?xml version="1.0"?><score-partwise version="4.0">'
            . '<part-list><score-part id="P1"><part-name>Bass</part-name></score-part></part-list>'
            . '<part id="P1"><measure number="1">'
            . '<attributes><divisions>1</divisions><key><fifths>1</fifths></key>'
            . '<time><beats>2</beats><beat-type>4</beat-type></time>'
            . '<clef><sign>F</sign><line>4</line></clef></attributes>'
            . '<note><pitch><step>F</step><alter>1</alter><octave>3</octave></pitch><duration>1</duration><type>quarter</type><voice>1</voice></note>'
            . '<note><pitch><step>F</step><alter>1</alter><octave>3</octave></pitch><duration>1</duration><type>quarter</type><voice>1</voice></note>'
            . '</measure></part></score-partwise>';

        $bass = $this->parser->parse($xml)->measures[0]->bassNotes;

        $this->assertSame(1, $bass[0]->alter);
        $this->assertSame(1, $bass[1]->alter, 'key-signature F# must remain, not be reset');
    }

    /** @test */
    public function a_following_note_with_its_own_figure_is_not_overwritten(): void
    {
        // C[-]  rest[6]  D[7]  — the rest's 6 cannot displace D's own 7, so it falls
        // back to the preceding note (C), per Telemann's "preceding" case.
        $score = $this->parser->parse($this->figuratoScore([
            ['C', null], ['rest', '6'], ['D', '7'],
        ]));

        $notes = $score->measures[0]->bassNotes;

        $this->assertSame([['number' => 7, 'alter' => 0]], $notes[2]->figuredBass, 'D keeps its own 7');
        $this->assertSame([['number' => 6, 'alter' => 0]], $notes[0]->figuredBass, '6 falls back to C');
    }

    /** @test */
    public function melody_notes_carry_octave_and_midi(): void
    {
        // One flute (A4) over a continuo bass (F3).
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<score-partwise version="4.0">'
            . '<part-list>'
            . '<score-part id="P1"><part-name>Flute</part-name></score-part>'
            . '<score-part id="P2"><part-name>Bass</part-name></score-part>'
            . '</part-list>'
            . '<part id="P1"><measure number="1">'
            . '<attributes><divisions>1</divisions><key><fifths>0</fifths></key></attributes>'
            . '<note><pitch><step>A</step><octave>4</octave></pitch><duration>4</duration><voice>1</voice></note>'
            . '</measure></part>'
            . '<part id="P2"><measure number="1">'
            . '<attributes><divisions>1</divisions><clef><sign>F</sign><line>4</line></clef></attributes>'
            . '<note><pitch><step>F</step><octave>3</octave></pitch><duration>4</duration><voice>1</voice></note>'
            . '</measure></part>'
            . '</score-partwise>';

        $score  = $this->parser->parse($xml);
        $melody = $score->measures[0]->melodyNotes;

        $this->assertCount(1, $melody);
        $this->assertSame(9, $melody[0]['pc'], 'A = pitch class 9');
        $this->assertSame(4, $melody[0]['octave']);
        $this->assertSame(69, $melody[0]['midi'], 'A4 = MIDI 69');
    }

    /** @test */
    public function melody_notes_are_pooled_from_every_non_bass_part(): void
    {
        // Trio sonata texture: two upper parts (A4 and C5) over a bass (F3).
        // Both soloists must land in melodyNotes, not just the highest.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<score-partwise version="4.0">'
            . '<part-list>'
            . '<score-part id="P1"><part-name>Violin I</part-name></score-part>'
            . '<score-part id="P2"><part-name>Violin II</part-name></score-part>'
            . '<score-part id="P3"><part-name>Bass</part-name></score-part>'
            . '</part-list>'
            . '<part id="P1"><measure number="1">'
            . '<attributes><divisions>1</divisions><key><fifths>0</fifths></key></attributes>'
            . '<note><pitch><step>C</step><octave>5</octave></pitch><duration>4</duration><voice>1</voice></note>'
            . '</measure></part>'
            . '<part id="P2"><measure number="1">'
            . '<attributes><divisions>1</divisions></attributes>'
            . '<note><pitch><step>A</step><octave>4</octave></pitch><duration>4</duration><voice>1</voice></note>'
            . '</measure></part>'
            . '<part id="P3"><measure number="1">'
            . '<attributes><divisions>1</divisions><clef><sign>F</sign><line>4</line></clef></attributes>'
            . '<note><pitch><step>F</step><octave>3</octave></pitch><duration>4</duration><voice>1</voice></note>'
            . '</measure></part>'
            . '</score-partwise>';

        $score = $this->parser->parse($xml);
        $midis = array_map(fn(array $mn): int => $mn['midi'], $score->measures[0]->melodyNotes);
        sort($midis);

        $this->assertSame([69, 72], $midis, 'both Violin II (A4=69) and Violin I (C5=72) are pooled');
    }
}
