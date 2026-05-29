<?php

namespace App\Service;

class ImslpAiSearchService
{
    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const MODEL   = 'llama-3.3-70b-versatile';
    private const SYSTEM  = 'You are a music library search assistant for IMSLP (International Music Score Library Project). '
        . 'Extract structured search parameters from natural language queries about classical music works. '
        . 'Call set_search_filters with only the fields you are confident about — omit fields that are not mentioned or implied.';

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

        $payload = json_encode([
            'model'    => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM],
                ['role' => 'user',   'content' => $query],
            ],
            'tools' => [[
                'type'     => 'function',
                'function' => [
                    'name'        => 'set_search_filters',
                    'description' => 'Set structured IMSLP search filters from a natural language query. Only include fields that are clearly mentioned or strongly implied — leave others absent.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
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
                        ],
                    ],
                ],
            ]],
            'tool_choice'     => 'required',
            'max_tokens'      => 256,
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $detail = $response ? (json_decode($response, true)['error']['message'] ?? $response) : 'no response';
            return ['error' => 'Groq API error ' . $httpCode . ': ' . $detail];
        }

        $data = json_decode($response, true);

        $toolCalls = $data['choices'][0]['message']['tool_calls'] ?? [];
        foreach ($toolCalls as $call) {
            if (($call['function']['name'] ?? '') === 'set_search_filters') {
                $args = json_decode($call['function']['arguments'], true);
                return $args ?? [];
            }
        }

        return ['error' => 'No filters extracted'];
    }
}
