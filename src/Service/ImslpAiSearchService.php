<?php

namespace App\Service;

class ImslpAiSearchService
{
    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    // llama-3.3-70b-versatile was retired from Groq's catalog; swapped for a model
    // that's still served and supports tool calling.
    private const MODEL   = 'openai/gpt-oss-20b';
    private const SYSTEM  = 'You are a music library search assistant for IMSLP (International Music Score Library Project). '
        . 'Extract structured search parameters from natural language queries about classical music works. '
        . 'Call set_search_filters with only the fields you are confident about — omit fields that are not mentioned or implied.';

    /** Field names that parseField() accepts — the per-field inline AI assist targets. */
    public const ASSISTABLE_FIELDS = [
        'instrumentation', 'style', 'genre', 'part_count', 'voice_registers', 'key', 'year_from', 'year_to',
    ];

    public function __construct(private readonly string $apiKey) {}

    /**
     * Parse a natural language query into IMSLP search filter values.
     * Returns an array with any subset of:
     *   instrumentation, style, genre, key, year_from, year_to, composer, title
     */
    public function parseQuery(string $query): array
    {
        if ($this->apiKey === '') {
            return ['error' => 'GROQ_API_KEY not configured'];
        }

        return $this->callTool(
            self::SYSTEM,
            $query,
            'set_search_filters',
            'Set structured IMSLP search filters from a natural language query. Only include fields that are clearly mentioned or strongly implied — leave others absent.',
            self::fieldSchema(),
            [],
            300
        );
    }

    /**
     * Interpret free text typed into a single advanced-search field (e.g. the user
     * types "flute duet" into the Instrumentation field and gets "2fl" back).
     * Unlike parseQuery(), this is scoped to exactly one field — the model isn't
     * guessing which field a phrase belongs to, the UI already told it.
     * Returns ['value' => ...] or ['error' => ...].
     */
    public function parseField(string $field, string $text): array
    {
        if (!in_array($field, self::ASSISTABLE_FIELDS, true)) {
            return ['error' => 'Unknown field'];
        }
        if ($this->apiKey === '') {
            return ['error' => 'GROQ_API_KEY not configured'];
        }
        if (trim($text) === '') {
            return ['error' => 'Empty input'];
        }

        $schema = self::fieldSchema();
        $result = $this->callTool(
            'You normalize free text typed into a single IMSLP search field. '
                . "The text below was typed into the \"$field\" field specifically — interpret it as that field's value. "
                . 'Call set_value with your best interpretation. If the text truly implies nothing for this field, '
                . 'call set_value with an empty string.',
            $text,
            'set_value',
            "Set the normalized $field value.",
            ['value' => $schema[$field]],
            ['value'],
            160
        );

        if (isset($result['error'])) {
            return $result;
        }
        $value = $result['value'] ?? null;
        return ($value !== null && $value !== '') ? ['value' => $value] : ['error' => 'No value extracted'];
    }

    /**
     * Shared field-name → JSON-schema-property definitions, used both by the
     * full multi-field extraction (parseQuery) and the single-field assist
     * (parseField), so the two paths can never describe a field differently.
     */
    private static function fieldSchema(): array
    {
        return [
            'instrumentation' => [
                'type'        => 'string',
                'description' => 'Space-separated IMSLP instrument abbreviations. '
                    . 'fl=flute, rec=recorder (treble/soprano), ob=oboe, cl=clarinet, bn=bassoon, '
                    . 'hn=horn, tr=trumpet, tb=trombone, vn=violin, va=viola, vc=cello, '
                    . 'viol=viola da gamba, db=double bass, str=strings, '
                    . 'hpd=harpsichord, org=organ, pf=piano/fortepiano, lute=lute/theorbo, '
                    . 'bc=basso continuo (also "continuo"), '
                    . 'sop=soprano, mez=mezzo-soprano, alt=alto, ten=tenor, bass=bass voice, '
                    . 'v=voice (any), vv=voices, ch=choir, mch=male choir, orch=orchestra. '
                    . 'Prefix numbers for multiples: 2fl, 3vn, 2rec. '
                    . '"string quartet"→vn vn va vc. "strings"→str.',
            ],
            'style' => [
                'type' => 'string',
                'enum' => ['Ancient', 'Baroque', 'Classical', 'Medieval', 'Modern', 'Renaissance', 'Romantic', 'Traditional'],
                'description' => 'Musical period. "baroque" or pre-1750→Baroque; "classical" or late-18th-c→Classical; "romantic" or 19th-c→Romantic; "modern"/"contemporary"→Modern; "renaissance" or 16th-c→Renaissance.',
            ],
            'genre' => [
                'type'        => 'string',
                'description' => 'Lowercase genre/form as used in IMSLP tags: sonatas, concertos, cantatas, motets, masses, fugues, suites, trios, quartets, quintets, operas, songs, dances, variations, preludes, fantasias, études, symphonies, overtures, etc.',
            ],
            'part_count' => [
                'type'        => 'integer',
                'description' => 'Number of independent parts/voices/instruments when the query specifies an abstract ensemble size rather than named instruments — common for Renaissance "for voices or instruments" repertoire. '
                    . 'e.g. "5 instruments"→5, "a 4 voci"→4, "1 dessus et basse" / "one treble and bass"→2, "trio"→3.',
            ],
            'voice_registers' => [
                'type'        => 'string',
                'description' => 'Vocal registers the work must contain, using the letters S(oprano/dessus) A(lto/haute-contre) T(enor/taille) B(ass/basse), no separators, ordered S→A→T→B. '
                    . 'REPEAT a letter to require multiplicity: "two sopranos, alto, tenor, bass"→"SSATB", "2 dessus 1 taille 1 basse"→"SSTB", "double SATB choir"→"SSAATTBB". '
                    . 'Use ONLY when the query names registers rather than instruments. e.g. "dessus et basse" / "soprano and bass"→"SB", "SATB choir"→"SATB", "for tenor and bass"→"TB".',
            ],
            'key' => [
                'type'        => 'string',
                'description' => 'Musical key exactly as written in scores, e.g. "D minor", "G major", "B-flat major", "F-sharp minor".',
            ],
            'year_from' => [
                'type'        => 'integer',
                'description' => 'Earliest year of composition (4-digit year).',
            ],
            'year_to' => [
                'type'        => 'integer',
                'description' => 'Latest year of composition (4-digit year).',
            ],
            'composer' => [
                'type'        => 'string',
                'description' => 'Composer surname or full name as it appears in IMSLP (e.g. "Bach, Johann Sebastian", "Telemann", "Handel").',
            ],
            'title' => [
                'type'        => 'string',
                'description' => 'Work title keywords.',
            ],
        ];
    }

    /**
     * Shared Groq tool-call request/response plumbing for both parseQuery() and parseField().
     */
    private function callTool(
        string $systemPrompt,
        string $userContent,
        string $toolName,
        string $toolDescription,
        array $properties,
        array $required,
        int $maxTokens
    ): array {
        $parameters = [
            'type'       => 'object',
            'properties' => $properties,
        ];
        if (!empty($required)) {
            $parameters['required'] = $required;
        }

        $payload = json_encode([
            'model'    => self::MODEL,
            // gpt-oss models default to heavy hidden reasoning that can eat the whole
            // token budget before ever emitting the tool call — these are short,
            // low-ambiguity extractions that don't need much of it.
            'reasoning_effort' => 'low',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userContent],
            ],
            'tools' => [[
                'type'     => 'function',
                'function' => [
                    'name'        => $toolName,
                    'description' => $toolDescription,
                    'parameters'  => $parameters,
                ],
            ]],
            'tool_choice' => 'required',
            'max_tokens'  => $maxTokens,
        ]);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ];

        // Retry once on 400 (intermittent model JSON generation failure)
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $ch = curl_init(self::API_URL);
            curl_setopt_array($ch, $opts);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) break;

            if ($httpCode !== 400 || $attempt === 1) {
                $detail = $response ? (json_decode($response, true)['error']['message'] ?? $response) : 'no response';
                return ['error' => 'Groq API error ' . $httpCode . ': ' . $detail];
            }
        }

        $data = json_decode($response, true);

        $toolCalls = $data['choices'][0]['message']['tool_calls'] ?? [];
        foreach ($toolCalls as $call) {
            if (($call['function']['name'] ?? '') === $toolName) {
                $args = json_decode($call['function']['arguments'], true);
                return $args ?? [];
            }
        }

        return ['error' => 'No filters extracted'];
    }
}
