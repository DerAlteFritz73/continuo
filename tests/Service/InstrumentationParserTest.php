<?php

namespace App\Tests\Service;

use App\Service\InstrumentationParser;
use PHPUnit\Framework\TestCase;

class InstrumentationParserTest extends TestCase
{
    private InstrumentationParser $p;

    protected function setUp(): void
    {
        $this->p = new InstrumentationParser();
    }

    private function assertParse(string $input, ?int $min, ?int $max, ?string $reg): void
    {
        $r = $this->p->parse($input);
        $this->assertSame($min, $r['part_count_min'], "min for: $input");
        $this->assertSame($max, $r['part_count_max'], "max for: $input");
        $this->assertSame($reg, $r['voice_registers'], "registers for: $input");
    }

    /** @test */
    public function simple_part_counts(): void
    {
        $this->assertParse('4 voices', 4, 4, null);
        $this->assertParse('5 instruments', 5, 5, null);
        $this->assertParse('6 instruments (viols)', 6, 6, null);
        $this->assertParse('2 voices (or instruments)', 2, 2, null);
    }

    /** @test */
    public function ranges_and_lists_give_min_max(): void
    {
        $this->assertParse('3-5 voices', 3, 5, null);
        $this->assertParse('4 voices; 7 voices', 4, 7, null);
        $this->assertParse('4, 5, 6, 8 voices', 4, 8, null);
        $this->assertParse('4-8 voices; 10 voices', 4, 10, null);
    }

    /** @test */
    public function satb_codes_give_count_and_registers(): void
    {
        $this->assertParse('4 voices (SATB)', 4, 4, 'SATB');
        // Multiplicity is preserved: SSATB stays distinct from SATB.
        $this->assertParse('5 voices (SSATB)', 5, 5, 'SSATB');
        $this->assertParse('SAATB a cappella', 5, 5, 'SAATB');
        $this->assertParse('male choir (TTBB)', 4, 4, 'TTBB');
    }

    /** @test */
    public function adjectives_between_number_and_noun(): void
    {
        // "2 treble voices" = two soprano-range parts → SS
        $this->assertParse('2 treble voices, piano', 2, 2, 'SS');
        // Alternative voicings (TTB, STB) merge by MAX per register → STTB.
        $r = $this->p->parse('3 voices (TTB, STB); 4 equal voices; 3 equal voices');
        $this->assertSame(3, $r['part_count_min']);
        $this->assertSame(4, $r['part_count_max']);
        $this->assertSame('STTB', $r['voice_registers']);
    }

    /** @test */
    public function spelled_out_register_enumeration(): void
    {
        $this->assertParse('soprano, alto, tenor, bass', 4, 4, 'SATB');
        // French query form the feature is meant to serve
        $this->assertParse('1 dessus et basse', 2, 2, 'SB');
    }

    /** @test */
    public function fr_it_scoring_idiom(): void
    {
        $this->assertParse('à 4', 4, 4, null);
    }

    /** @test */
    public function quantified_register_runs_sum_to_part_count(): void
    {
        // The flagship Renaissance case: counts in front of register words,
        // space-separated, mixed languages (IT "Soprani/basso", FR "Taille").
        // Multiplicity is kept: 2 sopranos → SS, so the multiset is SSTB.
        $this->assertParse('2 Soprani 1 Taille 1 basso', 4, 4, 'SSTB');
        $this->assertParse('2 Soprani, 1 Taille, 1 Basso', 4, 4, 'SSTB');
        $this->assertParse('2 soprano 1 tenor 1 bass', 4, 4, 'SSTB');
        // A single quantified register word is itself a part spec.
        $this->assertParse('2 dessus', 2, 2, 'SS');
        // Mixed quantified + bare words in one run.
        $this->assertParse('2 Dessus, Haute-contre, Taille, Basse', 5, 5, 'SSATB');
    }

    /** @test */
    public function multiplicity_distinguishes_ensembles(): void
    {
        // The core of this feature: SSATB must NOT collapse to SATB.
        $this->assertParse('5 voices (SSATB)', 5, 5, 'SSATB');
        $this->assertParse('4 voices (SATB)', 4, 4, 'SATB');
        // Double choir sums to 8 voices, two of each register.
        $this->assertParse('double choir SSAATTBB', 8, 8, 'SSAATTBB');
    }

    /** @test */
    public function lone_register_word_scores_register_but_not_a_part(): void
    {
        // "basso continuo" must not be mistaken for a 1-part work.
        $this->assertParse('basso continuo', null, null, 'B');
        $this->assertParse('Soprano, piano', null, null, 'S');
    }

    /** @test */
    public function named_instruments_are_not_counted(): void
    {
        // Solo/keyboard works have no abstract part count
        $this->assertParse('organ', null, null, null);
        $this->assertParse('lute', null, null, null);
        $this->assertParse('Harpsichord or organ', null, null, null);
        // "6 course renaissance lute" — "6 course" describes the lute, not parts
        $this->assertParse('6 course renaissance lute', null, null, null);
        // named instruments with a count are ignored; only the SATB chorus scores
        $this->assertParse('voices, SATB chorus, 2 oboes, bassoon', 4, 4, 'SATB');
    }

    /** @test */
    public function empty_input(): void
    {
        $this->assertParse('', null, null, null);
    }
}
