<?php

namespace App\Service;

use App\Entity\ImslpWork;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class ImslpService
{
    private const API_BASE = 'https://imslp.org/imslpscripts/API.ISCR.php';
    private const MW_API   = 'https://imslp.org/api.php';
    private const UA       = 'ContinuoApp/1.0 (Basso Continuo Realizer; educational use)';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {}

    // -------------------------------------------------------------------------
    // Sync: composers (type=1)
    // -------------------------------------------------------------------------

    public function syncComposers(callable $progress = null): int
    {
        $now   = (new \DateTime())->format('Y-m-d H:i:s');
        $total = 0;
        $start = 0;

        do {
            $data = $this->fetchApi(1, $start);
            if (empty($data['records'])) break;

            $rows = [];
            foreach ($data['records'] as $rec) {
                $imslpId = mb_substr($rec['id'] ?? '', 0, 512);
                if ($imslpId === '') continue;
                $name     = mb_substr(preg_replace('/^Category:\s*/u', '', $imslpId), 0, 255);
                $permlink = mb_substr($rec['permlink'] ?? '', 0, 512);
                $rows[] = ['imslp_id' => $imslpId, 'name' => $name, 'permlink' => $permlink, 'synced_at' => $now];
            }

            if (!empty($rows)) {
                $this->bulkUpsert('imslp_composer', $rows, ['name', 'permlink', 'synced_at']);
                $total += count($rows);
            }

            $lastName = $rows ? end($rows)['name'] : '';
            if ($progress && $progress($total, $lastName) === false) break;
            $start += 1000;
            if ($data['more']) usleep(300_000);

        } while ($data['more']);

        return $total;
    }

    // -------------------------------------------------------------------------
    // Sync: composer birth/death years
    // -------------------------------------------------------------------------

    public function countTotalComposers(): int
    {
        return (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT composer) FROM imslp_work WHERE composer != ''"
        );
    }

    public function countComposersWithoutDates(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT w.composer)
             FROM imslp_work w
             LEFT JOIN imslp_composer c ON c.name = w.composer
             WHERE w.composer != \'\' AND c.dates_synced_at IS NULL'
        );
    }

    /**
     * Fetch birth/death years from the composer's MediaWiki category page
     * for all composers that appear in imslp_work but have not yet been checked.
     */
    public function syncComposerDates(callable $progress = null, int $delay = 200): int
    {
        $names = $this->db->fetchFirstColumn(
            'SELECT DISTINCT w.composer
             FROM imslp_work w
             LEFT JOIN imslp_composer c ON c.name = w.composer
             WHERE w.composer != \'\' AND c.dates_synced_at IS NULL
             ORDER BY w.composer'
        );

        $now  = (new \DateTime())->format('Y-m-d H:i:s');
        $done = 0;
        foreach ($names as $name) {
            [$born, $died] = $this->fetchComposerDates($name);

            $this->db->executeStatement(
                'UPDATE imslp_composer SET born_year = ?, died_year = ?, dates_synced_at = ? WHERE name = ?',
                [$born, $died, $now, $name]
            );

            $done++;
            if ($progress && $progress($done, count($names), $name, $born, $died) === false) break;
            if ($delay > 0) usleep($delay * 1000);
        }

        return $done;
    }

    /**
     * Returns [bornYear|null, diedYear|null] for a composer name
     * by parsing the #imslpcomposer: template on their category page.
     */
    public function fetchComposerDates(string $composerName): array
    {
        $title = 'Category:' . str_replace(' ', '_', $composerName);
        $url   = self::MW_API . '?' . http_build_query([
            'action'  => 'query',
            'titles'  => $title,
            'prop'    => 'revisions',
            'rvprop'  => 'content',
            'format'  => 'json',
        ]);

        $body = $this->fetchGet($url);
        if ($body === '') return [null, null];

        $json  = json_decode($body, true);
        $pages = $json['query']['pages'] ?? [];
        $page  = reset($pages);
        if (!$page || isset($page['missing'])) return [null, null];

        $wikitext = $page['revisions'][0]['slots']['main']['*']
                 ?? $page['revisions'][0]['*']
                 ?? '';

        if ($wikitext === '') return [null, null];

        $born = null;
        $died = null;

        if (preg_match('/\|\s*Born Year\s*=\s*(-?\d+)/i', $wikitext, $m)) {
            $born = (int) $m[1];
        }
        if (preg_match('/\|\s*Died Year\s*=\s*(-?\d+)/i', $wikitext, $m)) {
            $died = (int) $m[1];
        }

        return [$born, $died];
    }

    // -------------------------------------------------------------------------
    // Sync: works (type=2)
    // -------------------------------------------------------------------------

    public function worksResumeOffset(): int
    {
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM imslp_work');
        return (int) floor($count / 1000) * 1000;
    }

    public function syncWorks(callable $progress = null, int $start = 0): int
    {
        $now   = (new \DateTime())->format('Y-m-d H:i:s');
        $total = $start;

        do {
            $data = $this->fetchApi(2, $start);
            if (empty($data['records'])) break;

            $rows = [];
            foreach ($data['records'] as $rec) {
                $imslpId = mb_substr($rec['id'] ?? '', 0, 512);
                if ($imslpId === '') continue;

                $intvals = is_array($rec['intvals']) ? $rec['intvals'] : [];
                $pageId  = (int) ($intvals['pageid'] ?? 0);
                if ($pageId === 0) continue;

                $rows[] = [
                    'imslp_id'       => $imslpId,
                    'title'          => mb_substr($intvals['worktitle'] ?? '', 0, 512),
                    'composer'       => mb_substr($intvals['composer'] ?? '', 0, 255),
                    'catalog_number' => mb_substr($intvals['icatno'] ?? '', 0, 150),
                    'page_id'        => $pageId,
                    'permlink'       => mb_substr($rec['permlink'] ?? '', 0, 512),
                    'synced_at'      => $now,
                ];
            }

            if (!empty($rows)) {
                $this->bulkUpsert('imslp_work', $rows, ['title', 'composer', 'catalog_number', 'page_id', 'permlink', 'synced_at']);
                $total += count($rows);
            }

            $lastRow = $rows ? end($rows) : [];
            $lastTitle = isset($lastRow['title'], $lastRow['composer'])
                ? $lastRow['title'] . ' — ' . $lastRow['composer']
                : '';
            if ($progress && $progress($total, $lastTitle) === false) break;
            $start += 1000;
            usleep(300_000);

        } while ($data['more'] || !empty($data['records']));

        return $total;
    }

    // -------------------------------------------------------------------------
    // Bulk upsert via INSERT ... ON DUPLICATE KEY UPDATE
    // -------------------------------------------------------------------------

    private function bulkUpsert(string $table, array $rows, array $updateCols): void
    {
        if (empty($rows)) return;

        $cols        = array_keys($rows[0]);
        $colList     = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $placeholder = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($rows), $placeholder));
        $updates     = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $updateCols));

        $sql    = "INSERT INTO `$table` ($colList) VALUES $placeholders ON DUPLICATE KEY UPDATE $updates";
        $params = [];
        foreach ($rows as $row) {
            foreach ($row as $val) $params[] = $val;
        }

        $this->db->executeStatement($sql, $params);
    }

    // -------------------------------------------------------------------------
    // Detail fetch for a single work via MediaWiki API
    // -------------------------------------------------------------------------

    public function fetchWorkDetail(ImslpWork $work): void
    {
        $url = self::MW_API . '?' . http_build_query([
            'action'  => 'query',
            'pageids' => $work->getPageId(),
            'prop'    => 'revisions',
            'rvprop'  => 'content',
            'rvslots' => 'main',
            'format'  => 'json',
        ]);

        $body = $this->fetchGet($url);
        if ($body === '') {
            throw new \RuntimeException('Empty response from IMSLP API');
        }

        $json  = json_decode($body, true);
        $pages = $json['query']['pages'] ?? [];
        $page  = reset($pages);
        if (!$page || isset($page['missing'])) {
            $this->markDetailSynced($work->getPageId());
            return;
        }

        $revisions = $page['revisions'] ?? [];
        if (empty($revisions)) {
            $this->markDetailSynced($work->getPageId());
            return;
        }

        $wikitext = $revisions[0]['slots']['main']['*']
                 ?? $revisions[0]['*']
                 ?? '';

        if ($wikitext === '') {
            $this->markDetailSynced($work->getPageId());
            return;
        }

        $parsed = $this->parseWikitext($wikitext);

        // Use DBAL directly to avoid ORM EntityManager closure on DB errors,
        // and to apply VARCHAR column limits explicitly.
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $this->db->executeStatement(
            'UPDATE imslp_work SET
                work_key         = ?,
                instrumentation  = ?,
                piece_style      = ?,
                year_composed    = ?,
                year_published   = ?,
                tags             = ?,
                page_type        = ?,
                movements        = ?,
                files_json       = ?,
                detail_synced_at = ?
             WHERE page_id = ?',
            [
                mb_substr($parsed['key'] ?: '', 0, 255) ?: null,
                $parsed['instrumentation'] ?: null,
                mb_substr($parsed['pieceStyle'] ?: '', 0, 100) ?: null,
                mb_substr($parsed['yearComposed'] ?: '', 0, 100) ?: null,
                mb_substr($parsed['yearPublished'] ?: '', 0, 100) ?: null,
                $parsed['tags'] ?: null,
                mb_substr($parsed['pageType'] ?: '', 0, 100) ?: null,
                $parsed['movements'] ?: null,
                !empty($parsed['editions']) ? json_encode($parsed['editions']) : null,
                $now,
                $work->getPageId(),
            ]
        );

        $work->setDetailSyncedAt(new \DateTime());
    }

    private function markDetailSynced(int $pageId): void
    {
        $this->db->executeStatement(
            'UPDATE imslp_work SET detail_synced_at = ? WHERE page_id = ?',
            [(new \DateTime())->format('Y-m-d H:i:s'), $pageId]
        );
    }

    // -------------------------------------------------------------------------
    // Wikitext parser
    // -------------------------------------------------------------------------

    public function parseWikitext(string $wikitext): array
    {
        $result = [
            'key'             => '',
            'instrumentation' => '',
            'pieceStyle'      => '',
            'yearComposed'    => '',
            'yearPublished'   => '',
            'tags'            => '',
            'pageType'        => '',
            'movements'       => '',
            'editions'        => [],
        ];

        // Extract WORK INFO section (between *****WORK INFO***** and next *****)
        if (preg_match('/\*{3,}WORK INFO\*{3,}(.*?)(?:\|\s*\*{3,}|\z)/s', $wikitext, $m)) {
            $fields = $this->parseWikiFields($m[1]);
            $fieldMap = [
                'Key'                          => 'key',
                'Instrumentation'              => 'instrumentation',
                'Piece Style'                  => 'pieceStyle',
                'Year/Date of Composition'     => 'yearComposed',
                'Year of First Publication'    => 'yearPublished',
                'Tags'                         => 'tags',
                'Page Type'                    => 'pageType',
                'Number of Movements/Sections' => 'movements',
            ];
            foreach ($fieldMap as $label => $key) {
                if (isset($fields[$label])) {
                    $result[$key] = $this->stripWikiMarkup($fields[$label]);
                }
            }
        }

        // Extract all #fte:imslpfile blocks — each block = one edition.
        // Use brace-counting to handle arbitrary nesting depth inside blocks
        // (templates like {{LinkEd|...}} or {{FE|...}} inside mustn't close the outer match).
        $fileBlocks = [[], []]; // mimic preg_match_all result: [0=>fullmatches, 1=>captures]
        $searchFrom = 0;
        while (($start = strpos($wikitext, '{{#fte:imslpfile', $searchFrom)) !== false) {
            $depth = 0;
            $len   = strlen($wikitext);
            $i     = $start;
            $end   = -1;
            while ($i < $len) {
                if (substr($wikitext, $i, 2) === '{{') { $depth++; $i += 2; }
                elseif (substr($wikitext, $i, 2) === '}}') {
                    $depth--;
                    if ($depth === 0) { $end = $i; break; }
                    $i += 2;
                } else { $i++; }
            }
            if ($end !== -1) {
                // Content is everything between '{{#fte:imslpfile' + whitespace and closing '}}'
                $contentStart = $start + strlen('{{#fte:imslpfile');
                $content = substr($wikitext, $contentStart, $end - $contentStart);
                $fileBlocks[0][] = substr($wikitext, $start, $end + 2 - $start);
                $fileBlocks[1][] = $content;
                $searchFrom = $end + 2;
            } else {
                break; // unclosed block, stop
            }
        }
        foreach ($fileBlocks[1] as $block) {
            $fields = $this->parseWikiFields($block);

            $edition = [
                'copyright'     => $this->stripWikiMarkup($fields['Copyright'] ?? ''),
                'publisher'     => $this->stripWikiMarkup($fields['Publisher Information'] ?? ''),
                'arranger'      => $this->stripWikiMarkup($fields['Arranger'] ?? ''),
                'editor'        => $this->stripWikiMarkup($fields['Editor'] ?? ''),
                'dateSubmitted' => $fields['Date Submitted'] ?? '',
                'files'         => [],
            ];

            for ($n = 1; $n <= 30; $n++) {
                $filename = $fields["File Name $n"] ?? '';
                if ($filename === '') break;

                $file = ['filename' => $filename];
                $desc = $this->stripWikiMarkup($fields["File Description $n"] ?? '');
                if ($desc !== '') $file['description'] = $desc;

                $edition['files'][] = $file;
            }

            if (!empty($edition['files'])) {
                $result['editions'][] = $edition;
            }
        }

        return $result;
    }

    /**
     * Parse wikitext |Field=Value lines into an associative array.
     * Handles multi-line values: a field's value continues until the next |Field= line.
     */
    private function parseWikiFields(string $text): array
    {
        $fields = [];
        // Normalize line endings
        $text  = str_replace("\r\n", "\n", $text);
        $lines = explode("\n", $text);

        $currentKey = null;
        $currentVal = [];

        foreach ($lines as $line) {
            // A new field starts with | followed by a field name, then =
            if (preg_match('/^\|\s*([^=|]+?)\s*=\s*(.*)/s', $line, $m)) {
                if ($currentKey !== null) {
                    $fields[$currentKey] = trim(implode("\n", $currentVal));
                }
                $currentKey = trim($m[1]);
                $currentVal = [$m[2]];
            } elseif ($currentKey !== null) {
                // Continuation of previous value
                $currentVal[] = $line;
            }
        }
        if ($currentKey !== null) {
            $fields[$currentKey] = trim(implode("\n", $currentVal));
        }

        return $fields;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function fetchApi(int $type, int $start): array
    {
        $url = self::API_BASE . '?account=worklist/disclaimer=accepted/sort=id/type=' . $type
             . '/start=' . $start . '/retformat=json';

        $body = $this->fetchGet($url);
        if ($body === '') return ['records' => [], 'more' => false];

        $data = json_decode($body, true);
        if (!is_array($data)) return ['records' => [], 'more' => false];

        $meta    = $data['metadata'] ?? [];
        $more    = (bool) ($meta['moreresultsavailable'] ?? false);
        $records = array_filter($data, fn($k) => $k !== 'metadata', ARRAY_FILTER_USE_KEY);

        return ['records' => array_values($records), 'more' => $more];
    }

    private function fetchGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        return is_string($body) ? $body : '';
    }

    /**
     * Replace a named wiki template (e.g. {{FE|...}}) with a fixed string,
     * using brace-counting so nested templates don't break the match.
     * Case-insensitive on the template name.
     */
    private function replaceNamedTemplate(string $text, string $name, string $replacement): string
    {
        return $this->replaceTemplateWithCallback($text, $name, fn() => $replacement);
    }

    /**
     * Extract the pipe-split parameters of a wiki template (the content between {{ and }}).
     * Returns a list of positional parameter values (trimmed).
     * E.g. "{{LinkEd|Wilhelm|Friedrich|1898|1952}}" → ['LinkEd', 'Wilhelm', 'Friedrich', '1898', '1952']
     * Works with the raw match from replaceNamedTemplate-style extraction.
     */
    private function extractTemplateParams(string $templateContent): array
    {
        // templateContent is the inner text: e.g. "LinkEd|Wilhelm|Friedrich|1898|1952"
        // Split on top-level | (not inside nested {{ }})
        $params = [];
        $current = '';
        $depth   = 0;
        $len     = strlen($templateContent);
        for ($i = 0; $i < $len; $i++) {
            if (substr($templateContent, $i, 2) === '{{') { $depth++; $current .= '{{'; $i++; }
            elseif (substr($templateContent, $i, 2) === '}}') { $depth--; $current .= '}}'; $i++; }
            elseif ($templateContent[$i] === '|' && $depth === 0) {
                $params[] = trim($current);
                $current  = '';
            } else {
                $current .= $templateContent[$i];
            }
        }
        $params[] = trim($current);
        return $params;
    }

    private function stripWikiMarkup(string $text): string
    {
        // [[Link|Display]] → Display, [[Link]] → Link
        $text = preg_replace('/\[\[(?:[^|\]]*\|)?([^\]]+)\]\]/', '$1', $text);

        // {{LinkEd|Firstname|Lastname|birthYear|deathYear}} → "Firstname Lastname"
        $text = $this->replaceTemplateWithCallback($text, 'LinkEd', function (string $inner) {
            $params = $this->extractTemplateParams($inner);
            // params[0]=template name, [1]=Firstname, [2]=Lastname
            $first = $params[1] ?? '';
            $last  = $params[2] ?? '';
            return trim("$first $last");
        });

        // {{FE|...}} → "Facsimile" (IMSLP facsimile edition template)
        $text = $this->replaceNamedTemplate($text, 'FE', 'Facsimile');

        // {{P|PublisherName|...}} → publisher name only (first positional param after name)
        $text = $this->replaceTemplateWithCallback($text, 'P', function (string $inner) {
            $params = $this->extractTemplateParams($inner);
            return trim($params[1] ?? '');
        });

        // Remove remaining {{...}} iteratively (any nesting depth, outermost first)
        $prev = null;
        while ($prev !== $text) {
            $prev = $text;
            $text = preg_replace('/\{\{[^{}]*\}\}/', '', $text);
        }
        // Remove any leftover {{ or }} fragments
        $text = str_replace(['{{', '}}'], '', $text);
        // '''bold''' and ''italic''
        $text = str_replace(["'''", "''"], '', $text);
        // HTML tags (e.g. <br>, <br/>, <ref>…</ref>)
        $text = preg_replace('/<[^>]+>/', ' ', $text);
        // Wiki list/definition markers at start of line (:, ;, #, *)
        $text = preg_replace('/^[;:#*]+\s*/m', '', $text);
        // Lines that are purely a number (artefacts from unresolved templates) → remove
        $text = preg_replace('/^\d+$/m', '', $text);
        // Collapse multiple spaces on same line
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        // Collapse blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Like replaceNamedTemplate but passes the inner content (between {{ and }})
     * to a callback and uses its return value as the replacement.
     */
    private function replaceTemplateWithCallback(string $text, string $name, callable $callback): string
    {
        $result = '';
        $offset = 0;
        $open   = '{{' . $name;
        while (($pos = stripos($text, $open, $offset)) !== false) {
            // Make sure it's followed by | or }} (not just a longer template name)
            $after = substr($text, $pos + strlen($open), 1);
            if ($after !== '|' && $after !== '}' && $after !== '') {
                $result .= substr($text, $offset, $pos - $offset + strlen($open));
                $offset  = $pos + strlen($open);
                continue;
            }
            $result .= substr($text, $offset, $pos - $offset);
            $depth = 0;
            $i     = $pos;
            $len   = strlen($text);
            $end   = -1;
            while ($i < $len) {
                if (substr($text, $i, 2) === '{{') { $depth++; $i += 2; }
                elseif (substr($text, $i, 2) === '}}') {
                    $depth--;
                    if ($depth === 0) { $end = $i + 2; break; }
                    $i += 2;
                } else { $i++; }
            }
            if ($end !== -1) {
                $inner   = substr($text, $pos + 2, $end - 2 - ($pos + 2)); // strip outer {{ }}
                $result .= $callback($inner);
                $offset  = $end;
            } else {
                $result .= substr($text, $pos, 2);
                $offset  = $pos + 2;
            }
        }
        $result .= substr($text, $offset);
        return $result;
    }
}
