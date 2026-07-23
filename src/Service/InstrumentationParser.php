<?php

namespace App\Service;

/**
 * PROTOTYPE — parses the free-text IMSLP `instrumentation` field into an
 * abstract ensemble descriptor: a part count (min/max) and the set of vocal
 * registers involved.
 *
 * Rationale: for Renaissance ("for voices or instruments") repertoire, works
 * are described abstractly — "4 voices", "5 instruments", "SAATB", "3-5 voices"
 * — rather than by named instruments. This lets users search "5 instruments" or
 * "1 dessus et basse" instead of naming timbres.
 *
 * The parser is deliberately conservative: it only counts GENERIC parts
 * ("voices", "instruments", "viols", "parts", "vv.") and register codes/words.
 * Named instruments ("2 oboes", "piano") are intentionally NOT counted — those
 * stay with the existing named-instrument FULLTEXT search.
 *
 * Returns:
 *   [
 *     'part_count_min'  => ?int,   // smallest scoring encountered (null if none)
 *     'part_count_max'  => ?int,   // largest scoring encountered
 *     'voice_registers' => ?string // canonical SATB set, e.g. "SATB" (null if none)
 *   ]
 */
final class InstrumentationParser
{
    /** Generic part nouns (NOT named instruments). */
    private const PART_NOUN = '(?:voices?|voci|stimmen|instruments?|viols?|parts?|vv\.?)';

    /** Optional qualifiers that may sit between the number and the noun
     *  ("4 equal voices", "2 treble voices", "3 solo instruments"). */
    private const ADJ = '(?:(?:equal|treble|mixed|solo|high|low|male|female|independent|real|different|unequal|upper|lower|obbligato)\s+){0,2}';

    /** Spelled-out register words → SATB letter. FR terms included (dessus/taille/basse…). */
    private const REGISTER_WORDS = [
        'soprano'      => 'S',
        'sopranos'     => 'S',
        'treble'       => 'S',
        'dessus'       => 'S',
        'cantus'       => 'S',
        'superius'     => 'S',
        'soprani'      => 'S',   // IT pl.
        'canto'        => 'S',   // IT
        'canti'        => 'S',
        'sopran'       => 'S',   // DE
        'soprane'      => 'S',   // DE pl.
        'alto'         => 'A',
        'altos'        => 'A',
        'alti'         => 'A',   // IT pl.
        'mezzo'        => 'A',
        'contralto'    => 'A',
        'contralti'    => 'A',   // IT pl.
        'haute-contre' => 'A',
        'altus'        => 'A',
        'alt'          => 'A',   // DE
        'tenor'        => 'T',
        'tenors'       => 'T',
        'tenori'       => 'T',   // IT pl.
        'tenöre'       => 'T',   // DE pl.
        'taille'       => 'T',
        'tenore'       => 'T',
        'bass'         => 'B',
        'basses'       => 'B',
        'bassi'        => 'B',   // IT pl.
        'bässe'        => 'B',   // DE pl.
        'basso'        => 'B',
        'baritone'     => 'B',
        'basse'        => 'B',
        'bassus'       => 'B',
    ];

    public function parse(string $raw): array
    {
        $text = trim($raw);
        if ($text === '') {
            return ['part_count_min' => null, 'part_count_max' => null, 'voice_registers' => null];
        }

        $counts    = [];   // every part-count candidate we find
        $registers = [];   // set of SATB letters

        // --- 1. Numeric ranges: "3-5 voices", "4–6 instruments", "1 to 3 voices"
        //     Consume them first so their numbers aren't re-counted as singletons.
        $work = $text;
        $rangeRe = '/(\d+)\s*(?:-|–|to)\s*(\d+)\s*' . self::ADJ . self::PART_NOUN . '/i';
        if (preg_match_all($rangeRe, $work, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $counts[] = (int) $r[1];
                $counts[] = (int) $r[2];
            }
            $work = preg_replace($rangeRe, ' ', $work);
        }

        // --- 2. Comma-lists sharing one noun: "4, 5, 6, 8 voices" → 4,5,6,8
        if (preg_match_all(
            '/((?:\d+\s*,\s*)+\d+)\s*' . self::PART_NOUN . '/i',
            $work, $m, PREG_SET_ORDER
        )) {
            foreach ($m as $r) {
                foreach (preg_split('/\s*,\s*/', $r[1]) as $n) {
                    if ($n !== '') $counts[] = (int) $n;
                }
            }
            $work = preg_replace('/((?:\d+\s*,\s*)+\d+)\s*' . self::PART_NOUN . '/i', ' ', $work);
        }

        // --- 3. "N-part" / "N part" (e.g. "2-part children's chorus", "4-part")
        if (preg_match_all('/(\d+)\s*-?\s*part\b/i', $work, $m)) {
            foreach ($m[1] as $n) $counts[] = (int) $n;
            $work = preg_replace('/(\d+)\s*-?\s*part\b/i', ' ', $work);
        }

        // --- 4. Simple "N <noun>": "4 voices", "5 instruments", "2 treble voices"
        if (preg_match_all('/(\d+)\s*' . self::ADJ . self::PART_NOUN . '/i', $work, $m)) {
            foreach ($m[1] as $n) $counts[] = (int) $n;
        }

        // --- 4b. FR/IT "à N" / "a N" scoring idiom: "à 4", "a 5".
        if (preg_match_all('/\bà\s*(\d+)\b/u', $work, $m)) {
            foreach ($m[1] as $n) $counts[] = (int) $n;
        }

        // --- 5. SATB-style register codes: "SATB", "SAATB", "TTBB", "SSATTB",
        //     including slash-separated multi-choir "SATB/SATB" and parenthesised.
        //     A code is 2-8 letters drawn only from S/A/T/B.
        if (preg_match_all('/\b([SATB]{2,8})\b/', $text, $m)) {
            foreach ($m[1] as $code) {
                $counts[] = strlen($code);
                $local = [];
                foreach (str_split($code) as $ch) $local[$ch] = ($local[$ch] ?? 0) + 1;
                $this->mergeRegisters($registers, $local);
            }
        }

        // --- 6. Single-letter enumeration: "S,A,T,B" or "S, A, T, B"
        if (preg_match_all('/\b([SATB])(?:\s*,\s*[SATB])+\b/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $letters = preg_split('/\s*,\s*/', $r[0]);
                $counts[] = count($letters);
                $local = [];
                foreach ($letters as $ch) {
                    $ch = strtoupper($ch);
                    $local[$ch] = ($local[$ch] ?? 0) + 1;
                }
                $this->mergeRegisters($registers, $local);
            }
        }

        // --- 7. Quantified spelled-out register enumeration (any language).
        //     Scans a run of "(count)? registerword" tokens, e.g.
        //       "2 Soprani 1 Taille 1 basso" → 4 parts (S S T B)
        //       "2 dessus"                   → 2 parts (S)
        //       "soprano, alto, tenor, bass" → 4 parts (S A T B)
        //     A leading count sums into the part total; a bare word counts as one
        //     part. To avoid a lone "basso continuo" scoring as a part, an
        //     UNquantified single register word contributes its register but no
        //     part count — a part total is emitted only when the run holds ≥2
        //     register words OR any word carries an explicit count.
        // \b boundaries + backtracking make alternation order irrelevant
        // (e.g. "sopranos" still matches even though "soprano" is listed first).
        $regAlt = implode('|', array_map(
            fn($w) => preg_quote($w, '/'),
            array_keys(self::REGISTER_WORDS)
        ));
        $tokenRe = '/(\d+)?\s*\b(' . $regAlt . ')\b/iu';
        if (preg_match_all($tokenRe, $text, $m, PREG_SET_ORDER)) {
            $total = 0;
            $quantified = false;
            $local = [];
            foreach ($m as $r) {
                $n = ($r[1] !== '') ? (int) $r[1] : null;
                $total += $n ?? 1;
                if ($n !== null) $quantified = true;
                $letter = self::REGISTER_WORDS[strtolower($r[2])];
                $local[$letter] = ($local[$letter] ?? 0) + ($n ?? 1);
            }
            if (count($m) >= 2 || $quantified) {
                $counts[] = $total;
            }
            $this->mergeRegisters($registers, $local);
        }

        // --- Assemble result -------------------------------------------------
        $min = $counts ? min($counts) : null;
        $max = $counts ? max($counts) : null;

        // Canonical MULTISET: registers in S→A→T→B order with multiplicity
        // repeated, e.g. [S=2,A=1,T=1,B=1] → "SSATB" (distinct from "SATB").
        // Grouping identical letters contiguously lets the repository test
        // "≥k of X" with a simple LIKE '%XX…%'. Capped at the column width.
        $canonical = '';
        foreach (['S', 'A', 'T', 'B'] as $ch) {
            if (!empty($registers[$ch])) $canonical .= str_repeat($ch, $registers[$ch]);
        }
        if (strlen($canonical) > self::MAX_REGISTER_LEN) {
            $canonical = substr($canonical, 0, self::MAX_REGISTER_LEN);
        }

        return [
            'part_count_min'  => $min,
            'part_count_max'  => $max,
            'voice_registers' => $canonical !== '' ? $canonical : null,
        ];
    }

    /** Column width of imslp_work.voice_registers — canonical multiset is capped here. */
    public const MAX_REGISTER_LEN = 16;

    /**
     * Merge a pass's per-letter counts into the running descriptor, keeping the
     * LARGER multiplicity per register. Each pass independently describes (what
     * is presumably) the same ensemble, so the richer reading wins — e.g. a
     * "SSATB" code and a bare "SATB" mention resolve to SSATB, not SSSAATTBB.
     *
     * @param array<string,int> $registers running max-count map (mutated)
     * @param array<string,int> $local     this pass's per-letter counts
     */
    private function mergeRegisters(array &$registers, array $local): void
    {
        foreach ($local as $ch => $n) {
            $registers[$ch] = max($registers[$ch] ?? 0, $n);
        }
    }
}
