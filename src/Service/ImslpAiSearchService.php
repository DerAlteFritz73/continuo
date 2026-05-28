<?php

namespace App\Service;

class ImslpAiSearchService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL   = 'claude-haiku-4-5-20251001';

    public function __construct(private readonly string $apiKey) {}

    /**
     * Parse a natural language query into IMSLP search filter values.
     * Returns an array with any subset of:
     *   instrumentation, style, genre, key, year_from, year_to, composer, title
     */
    public function parseQuery(string $query): array
    {
        if ($this->apiKey === '') {
            return ['error' => 'ANTHROPIC_API_KEY not configured'];
        }

        $tool = [
            'name'        => 'set_search_filters',
            'description' => 'Set structured IMSLP search filters from a natural language query. Only include fields that are clearly mentioned or strongly implied — leave others absent.',
            'input_schema' => [
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
        ];

        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 512,
            'system'     => 'You are a music library search assistant for IMSLP (International Music Score Library Project). '
                . 'Extract structured search parameters from natural language queries about classical music works. '
                . 'Call set_search_filters with only the fields you are confident about — omit fields that are not mentioned or implied.',
            'messages'    => [['role' => 'user', 'content' => $query]],
            'tools'       => [$tool],
            'tool_choice' => ['type' => 'any'],
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['error' => 'API request failed (HTTP ' . $httpCode . ')'];
        }

        $data    = json_decode($response, true);
        $content = $data['content'] ?? [];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use' && $block['name'] === 'set_search_filters') {
                return $block['input'] ?? [];
            }
        }

        return ['error' => 'No filters extracted'];
    }
}
