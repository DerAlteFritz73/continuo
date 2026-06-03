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
            [$born, $died, $nationality, $timePeriod] = $this->fetchComposerDates($name);

            $this->db->executeStatement(
                'UPDATE imslp_composer SET born_year = ?, died_year = ?, nationality = ?, time_period = ?, dates_synced_at = ? WHERE name = ?',
                [$born, $died, $nationality, $timePeriod, $now, $name]
            );

            $done++;
            if ($progress && $progress($done, count($names), $name, $born, $died) === false) break;
            if ($delay > 0) usleep($delay * 1000);
        }

        return $done;
    }

    /**
     * Extract a RISM Online numeric source ID from free text (miscNotes, publisher info, etc.).
     * Handles opac.rism.info URLs, rism.info/sources/ paths, and plain "RISM: 1234567" strings.
     */
    public function extractRismId(string $text): ?string
    {
        if ($text === '') return null;
        // opac.rism.info URL: ?...Content=1234567890
        if (preg_match('/opac\.rism\.info[^?]*\?[^"\'>\s]*Content=(\d{7,})/i', $text, $m)) return $m[1];
        // rism.info/sources/1234567890 or rism.online/sources/...
        if (preg_match('/rism\.(?:info|online)\/sources\/(\d{7,})/i', $text, $m)) return $m[1];
        // Plain "RISM: 1234567890" or "RISM 1234567890"
        if (preg_match('/\bRISM[:\s#]*(\d{7,})\b/i', $text, $m)) return $m[1];
        return null;
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

        $born        = null;
        $died        = null;
        $nationality = null;
        $timePeriod  = null;

        if (preg_match('/\|\s*Born Year\s*=\s*(-?\d+)/i', $wikitext, $m)) {
            $born = (int) $m[1];
        }
        if (preg_match('/\|\s*Died Year\s*=\s*(-?\d+)/i', $wikitext, $m)) {
            $died = (int) $m[1];
        }
        if (preg_match('/\|\s*Born Country\s*=\s*(.+)/i', $wikitext, $m)) {
            $nationality = trim($m[1]);
            if ($nationality === '') $nationality = null;
        }
        if (preg_match('/\|\s*Time Period\s*=\s*(.+)/i', $wikitext, $m)) {
            $timePeriod = trim($m[1]);
            if ($timePeriod === '') $timePeriod = null;
        }

        return [$born, $died, $nationality, $timePeriod];
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
            'prop'    => 'revisions|categories',
            'rvprop'  => 'content',
            'rvslots' => 'main',
            'cllimit' => '500',
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

        // If the wikitext |Tags= field is empty, derive abbreviated tags from MediaWiki
        // "For ..." categories (e.g. "Category:For 2 flutes, basso continuo" → "2fl bc").
        // Some IMSLP works omit the Tags template field but are correctly categorised.
        if ($parsed['tags'] === '') {
            $categories = $page['categories'] ?? [];
            $catTags    = $this->tagsFromCategories($categories);
            if ($catTags !== '') {
                $parsed['tags'] = $catTags;
            }
        }

        $genreCats = $this->genresFromCategories($page['categories'] ?? [], $work->getComposer());

        // Parse new normalised fields
        $durationSeconds  = $this->parseDurationSeconds($parsed['averageDuration'] ?? '');
        [$firstPerfDate, $firstPerfLocation] = $this->parseFirstPerformance($parsed['firstPerformance'] ?? '');

        // Resolve composer_id
        $composerId = $this->db->fetchOne(
            'SELECT id FROM imslp_composer WHERE name = ?',
            [$work->getComposer()]
        ) ?: null;

        // Use DBAL directly to avoid ORM EntityManager closure on DB errors,
        // and to apply VARCHAR column limits explicitly.
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $this->db->executeStatement(
            'UPDATE imslp_work SET
                work_key            = ?,
                instrumentation     = ?,
                piece_style         = ?,
                year_composed       = ?,
                year_published      = ?,
                tags                = ?,
                genre_cats          = ?,
                language            = ?,
                alternative_title   = ?,
                average_duration    = ?,
                librettist          = ?,
                dedication          = ?,
                first_performance   = ?,
                page_type           = ?,
                movements           = ?,
                files_json          = ?,
                composer_id         = ?,
                duration_seconds    = ?,
                first_perf_date     = ?,
                first_perf_location = ?,
                detail_synced_at    = ?
             WHERE page_id = ?',
            [
                mb_substr($parsed['key'] ?: '', 0, 255) ?: null,
                $parsed['instrumentation'] ?: null,
                mb_substr($parsed['pieceStyle'] ?: '', 0, 100) ?: null,
                mb_substr($parsed['yearComposed'] ?: '', 0, 100) ?: null,
                mb_substr($parsed['yearPublished'] ?: '', 0, 100) ?: null,
                $parsed['tags'] ?: null,
                !empty($genreCats) ? implode(' ; ', $genreCats) : null,
                mb_substr($parsed['language'] ?: '', 0, 255) ?: null,
                mb_substr($parsed['alternativeTitle'] ?: '', 0, 512) ?: null,
                mb_substr($parsed['averageDuration'] ?: '', 0, 100) ?: null,
                $parsed['librettist'] ?: null,
                $parsed['dedication'] ?: null,
                mb_substr($parsed['firstPerformance'] ?: '', 0, 255) ?: null,
                mb_substr($parsed['pageType'] ?: '', 0, 100) ?: null,
                $parsed['movements'] ?: null,
                !empty($parsed['editions']) ? json_encode($parsed['editions']) : null,
                $composerId,
                $durationSeconds,
                mb_substr($firstPerfDate ?? '', 0, 50) ?: null,
                mb_substr($firstPerfLocation ?? '', 0, 255) ?: null,
                $now,
                $work->getPageId(),
            ]
        );

        // Upsert editions + files into normalised tables
        if (!empty($parsed['editions'])) {
            $this->upsertEditions($work->getPageId(), $parsed['editions']);
        }

        // Upsert categories into normalised tables
        if (!empty($genreCats)) {
            $this->upsertWorkCategories($work->getPageId(), $genreCats);
        }

        $work->setDetailSyncedAt(new \DateTime());
    }

    // -------------------------------------------------------------------------
    // Normalised table helpers
    // -------------------------------------------------------------------------

    /**
     * Deletes existing imslp_edition (+ cascading imslp_edition_file) rows for the given
     * work and re-inserts from the parsed editions array.
     */
    public function upsertEditions(int $pageId, array $editions): void
    {
        // Resolve work row id
        $workId = $this->db->fetchOne('SELECT id FROM imslp_work WHERE page_id = ?', [$pageId]);
        if (!$workId) return;

        // Delete existing edition rows (files cascade via app logic below)
        $existingEditionIds = $this->db->fetchFirstColumn(
            'SELECT id FROM imslp_edition WHERE work_id = ?', [(int) $workId]
        );
        if (!empty($existingEditionIds)) {
            $this->db->executeStatement(
                'DELETE FROM imslp_edition_file WHERE edition_id IN (' . implode(',', $existingEditionIds) . ')'
            );
            $this->db->executeStatement(
                'DELETE FROM imslp_edition WHERE work_id = ?', [(int) $workId]
            );
        }

        foreach ($editions as $sortOrder => $edition) {
            $this->db->executeStatement(
                'INSERT INTO imslp_edition (work_id, sort_order, copyright, publisher, arranger, editor,
                    date_submitted, image_type, uploader, scanner, plate_number, misc_notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $workId,
                    $sortOrder,
                    mb_substr($edition['copyright'] ?? '', 0, 255) ?: null,
                    ($edition['publisher'] ?? '') ?: null,
                    mb_substr($edition['arranger'] ?? '', 0, 512) ?: null,
                    mb_substr($edition['editor'] ?? '', 0, 512) ?: null,
                    mb_substr($edition['dateSubmitted'] ?? '', 0, 20) ?: null,
                    mb_substr($edition['imageType'] ?? '', 0, 100) ?: null,
                    mb_substr($edition['uploader'] ?? '', 0, 255) ?: null,
                    mb_substr($edition['scanner'] ?? '', 0, 255) ?: null,
                    mb_substr($edition['plateNumber'] ?? '', 0, 255) ?: null,
                    ($edition['miscNotes'] ?? '') ?: null,
                ]
            );
            $editionId = (int) $this->db->lastInsertId();

            foreach (($edition['files'] ?? []) as $pos => $file) {
                $this->db->executeStatement(
                    'INSERT INTO imslp_edition_file (edition_id, position, filename, description) VALUES (?, ?, ?, ?)',
                    [
                        $editionId,
                        $pos + 1,
                        mb_substr($file['filename'] ?? '', 0, 512),
                        mb_substr($file['description'] ?? '', 0, 512) ?: null,
                    ]
                );
            }
        }
    }

    /**
     * Upserts category names and inserts junction rows for a work.
     * Existing junction rows for this work are deleted first to avoid duplicates.
     */
    public function upsertWorkCategories(int $pageId, array $genreCats): void
    {
        $workId = $this->db->fetchOne('SELECT id FROM imslp_work WHERE page_id = ?', [$pageId]);
        if (!$workId) return;

        // Delete existing junctions for this work
        $this->db->executeStatement(
            'DELETE FROM imslp_work_category WHERE work_id = ?', [(int) $workId]
        );

        foreach ($genreCats as $catName) {
            $catName = trim($catName);
            if ($catName === '') continue;

            // Upsert category name
            $this->db->executeStatement(
                'INSERT INTO imslp_category (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                [$catName]
            );
            $catId = (int) $this->db->lastInsertId();

            // Insert junction (ignore duplicate if already exists)
            $this->db->executeStatement(
                'INSERT IGNORE INTO imslp_work_category (work_id, category_id) VALUES (?, ?)',
                [(int) $workId, $catId]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Duration + first performance parsers
    // -------------------------------------------------------------------------

    /**
     * Parses a human-readable duration string into total seconds.
     * Examples:
     *   "20 minutes" → 1200
     *   "1 hour 30 minutes" → 5400
     *   "2 hours" → 7200
     *   "10-15 minutes" → 750  (average)
     *   "ca. 20 minutes" → 1200
     *   "45'" → 2700
     * Returns null if nothing parseable.
     */
    public function parseDurationSeconds(string $duration): ?int
    {
        if ($duration === '') return null;

        // Strip "ca.", "c.", "about", "approximately", "approx."
        $d = preg_replace('/\b(ca\.?|c\.|about|approximately|approx\.?)\s*/i', '', $duration);
        $d = trim($d);

        // "45'" or "45 min'" style
        if (preg_match("/^(\d+)['′]/", $d, $m)) {
            return (int) $m[1] * 60;
        }

        // Range: "10-15 minutes" or "10–15 minutes"
        if (preg_match('/^(\d+)\s*[-–]\s*(\d+)\s*(hours?|hrs?|h\b|minutes?|mins?|m\b)?/i', $d, $m)) {
            $avg  = ((int) $m[1] + (int) $m[2]) / 2;
            $unit = strtolower($m[3] ?? 'min');
            return (int) round($avg * (preg_match('/^h/i', $unit) ? 3600 : 60));
        }

        $seconds = 0;
        $matched = false;

        // Hours
        if (preg_match('/(\d+)\s*(hours?|hrs?|h\b)/i', $d, $m)) {
            $seconds += (int) $m[1] * 3600;
            $matched  = true;
        }
        // Minutes
        if (preg_match('/(\d+)\s*(minutes?|mins?|m\b)/i', $d, $m)) {
            $seconds += (int) $m[1] * 60;
            $matched  = true;
        }
        // Seconds
        if (preg_match('/(\d+)\s*(seconds?|secs?|s\b)/i', $d, $m)) {
            $seconds += (int) $m[1];
            $matched  = true;
        }

        return $matched ? $seconds : null;
    }

    /**
     * Parses a first-performance string into [dateString|null, locationString|null].
     * Examples:
     *   "1725-03-25 in Leipzig"      → ['1725-03-25', 'Leipzig']
     *   "1724 in Leipzig, Germany"   → ['1724', 'Leipzig, Germany']
     *   "1890, Paris"                → ['1890', 'Paris']
     * Returns [null, null] if nothing parseable.
     */
    public function parseFirstPerformance(string $fp): array
    {
        if ($fp === '') return [null, null];

        $date     = null;
        $location = null;

        // Normalise slashes to dashes in date part before parsing
        $fp = preg_replace('/^(\d{4})\/(\d{2})(?:\/(\d{2}))?/', '$1-$2-$3', $fp);

        // Try "YYYY-MM-DD in Location\n..." — take only first line of location
        if (preg_match('/^(\d{4}(?:-\d{2}(?:-\d{2})?)?)\s+in\s+(.+)$/is', $fp, $m)) {
            $date     = trim($m[1]);
            $location = trim(explode("\n", trim($m[2]))[0]);
        } elseif (preg_match('/^(\d{4}(?:-\d{2}(?:-\d{2})?)?),\s*(.+)$/s', $fp, $m)) {
            $date     = trim($m[1]);
            $location = trim(explode("\n", trim($m[2]))[0]);
        } elseif (preg_match('/^(\d{4}(?:-\d{2}(?:-\d{2})?)?)/', $fp, $m)) {
            $date = trim($m[1]);
        }

        // Strip wiki markup from location
        if ($location !== null) {
            $location = $this->stripWikiMarkup($location);
            $location = $location !== '' ? $location : null;
        }

        return [$date, $location];
    }

    private function markDetailSynced(int $pageId): void
    {
        $this->db->executeStatement(
            'UPDATE imslp_work SET detail_synced_at = ? WHERE page_id = ?',
            [(new \DateTime())->format('Y-m-d H:i:s'), $pageId]
        );
    }

    // -------------------------------------------------------------------------
    // Category → abbreviated tag conversion
    // -------------------------------------------------------------------------

    private const INSTR_TO_ABBR = [
        'flute'          => 'fl',
        'piccolo'        => 'pic',
        'oboe'           => 'ob',
        'cor anglais'    => 'ca',
        'clarinet'       => 'cl',
        'bassoon'        => 'bn',
        'contrabassoon'  => 'cbn',
        'horn'           => 'hn',
        'trumpet'        => 'tp',
        'trombone'       => 'tb',
        'tuba'           => 'tba',
        'violin'         => 'vn',
        'viola'          => 'va',
        'cello'          => 'vc',
        'violoncello'    => 'vc',
        'double bass'    => 'cb',
        'contrabass'     => 'cb',
        'basso continuo' => 'bc',
        'continuo'       => 'bc',
        'harpsichord'    => 'hpd',
        'cembalo'        => 'hpd',
        'organ'          => 'org',
        'piano'          => 'pf',
        'keyboard'       => 'kbd',
        'lute'           => 'lute',
        'theorbo'        => 'the',
        'guitar'         => 'gt',
        'recorder'       => 'rec',
        'viola da gamba' => 'vdg',
        'gamba'          => 'vdg',
        'viol'           => 'vdg',
        'strings'        => 'str',
        'orchestra'      => 'orch',
        'voice'          => 'v',
        'soprano'        => 'sop',
        'mezzo-soprano'  => 'mez',
        'alto'           => 'alt',
        'tenor'          => 'ten',
        'baritone'       => 'bar',
        'bass'           => 'bas',
        'chorus'         => 'chor',
        'choir'          => 'chor',
    ];

    /**
     * Converts a list of MediaWiki category objects to a semicolon-delimited tag string.
     * Only "For X, Y, Z" categories are processed; "For N players" is skipped.
     */
    private function tagsFromCategories(array $categories): string
    {
        $tagSections = [];
        foreach ($categories as $cat) {
            $title   = $cat['title'] ?? '';
            $catText = preg_replace('/^Category:/i', '', $title);
            if (!preg_match('/^For (.+)$/i', $catText, $m)) {
                continue;
            }
            if (preg_match('/^For \d+ players?$/i', $catText)) {
                continue;
            }
            $tag = $this->categoryTextToTag($m[1]);
            if ($tag !== '') {
                $tagSections[] = $tag;
            }
        }
        return implode(' ; ', array_unique($tagSections));
    }

    /** "2 flutes, basso continuo" → "2fl bc" */
    private function categoryTextToTag(string $text): string
    {
        $parts = array_map('trim', explode(',', strtolower($text)));
        $abbrs = [];
        foreach ($parts as $part) {
            $abbr = $this->instrNameToAbbr($part);
            if ($abbr !== '') {
                $abbrs[] = $abbr;
            }
        }
        return implode(' ', $abbrs);
    }

    private function instrNameToAbbr(string $name): string
    {
        $name = trim($name);
        if (isset(self::INSTR_TO_ABBR[$name])) {
            return self::INSTR_TO_ABBR[$name];
        }
        // Handle "N instrument(s)" — strip count and depluralize if needed
        if (preg_match('/^(\d+)\s+(.+)$/', $name, $m)) {
            $n    = $m[1];
            $word = $m[2];
            $abbr = self::INSTR_TO_ABBR[$word] ?? null;
            if (!$abbr && str_ends_with($word, 's') && strlen($word) > 3) {
                $abbr = self::INSTR_TO_ABBR[substr($word, 0, -1)] ?? null;
            }
            if ($abbr) {
                return $n . $abbr;
            }
        }
        // Try depluralize for single name
        if (str_ends_with($name, 's') && strlen($name) > 3) {
            $abbr = self::INSTR_TO_ABBR[substr($name, 0, -1)] ?? null;
            if ($abbr) {
                return $abbr;
            }
        }
        return '';
    }

    /**
     * Extracts genre labels from MediaWiki categories — both composer-specific subcategories
     * (e.g. "Category:Telemann, Georg Philipp/Cantatas" → "Cantatas") and global genre
     * categories (e.g. "Category:Cantatas" → "Cantatas").  Administrative, style, key,
     * instrumentation, and contributor categories are excluded.
     */
    private function genresFromCategories(array $categories, string $composer): array
    {
        $genres = [];

        // Contributor role suffixes to skip for composer-specific subcategories
        static $roles = ['Arranger', 'Editor', 'Performer', 'Copyist', 'Translator', 'Librettist'];

        // Composer-specific subcategories: "Category:Composer, Name/Genre"
        $prefix = 'Category:' . $composer . '/';
        foreach ($categories as $cat) {
            $title = $cat['title'] ?? '';
            if (str_starts_with($title, $prefix)) {
                $genre = substr($title, strlen($prefix));
                if ($genre !== '' && !in_array($genre, $roles, true)) $genres[] = $genre;
            }
        }

        // Global genre categories: "Category:Cantatas", "Category:Symphonies", etc.
        // Exclude noise by matching against known non-genre patterns.
        static $styles = ['Ancient', 'Baroque', 'Classical', 'Medieval', 'Modern', 'Renaissance', 'Romantic', 'Contemporary', 'Traditional'];
        static $adminExact = ['Recordings', 'Urtext', 'Manuscripts', 'Scores', 'Parts'];

        foreach ($categories as $cat) {
            $title = $cat['title'] ?? '';
            if (!str_starts_with($title, 'Category:')) continue;
            $name = substr($title, 9);

            if (str_contains($name, '/')) continue;                     // sub-categories already handled
            if (in_array($name, $styles, true)) continue;               // style period names
            if (in_array($name, $adminExact, true)) continue;           // exact admin names
            if (str_ends_with($name, ' style')) continue;               // "Baroque style"
            if (str_ends_with($name, ' language')) continue;            // "English language" (stored in language field)

            // Prefixes that identify non-genre categories
            if (preg_match('/^(Pages |Scores |Works |For |Manuscripts|Composers\'|Performers\'|Arrangers\'|Editors\')/i', $name)) continue;

            // Key categories: "F major", "C minor", "B-flat major"
            if (preg_match('/^[A-G][#b\-]*([\s-]+(major|minor))?$/i', $name)) continue;

            // Noise keywords anywhere in the name
            if (preg_match('/(century|RISM|WIMA|holograph|arrangement|purchase|Self-published|Unknown)/i', $name)) continue;

            // Contributor role categories (global, e.g. "Category:Arranger")
            if (in_array($name, $roles, true)) continue;

            // Composer name format "Lastname, Firstname"
            if (preg_match('/^[A-Z][a-z]+,\s+[A-Z]/', $name)) continue;

            $genres[] = $name;
        }

        return array_values(array_unique($genres));
    }

    // -------------------------------------------------------------------------
    // Wikitext parser
    // -------------------------------------------------------------------------

    public function parseWikitext(string $wikitext): array
    {
        $result = [
            'key'              => '',
            'instrumentation'  => '',
            'pieceStyle'       => '',
            'yearComposed'     => '',
            'yearPublished'    => '',
            'tags'             => '',
            'pageType'         => '',
            'movements'        => '',
            'language'         => '',
            'alternativeTitle' => '',
            'averageDuration'  => '',
            'librettist'       => '',
            'dedication'       => '',
            'firstPerformance' => '',
            'editions'         => [],
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
                'Language'                     => 'language',
                'Alternative Title'            => 'alternativeTitle',
                'Average Duration'             => 'averageDuration',
                'Librettist'                   => 'librettist',
                'Dedication'                   => 'dedication',
                'First Performance'            => 'firstPerformance',
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
        $fileBlocks   = [[], []]; // [0=>fullmatches, 1=>captures]
        $blockStarts  = [];       // byte offset of each block in $wikitext
        $searchFrom   = 0;
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
                $blockStarts[]   = $start;
                $searchFrom = $end + 2;
            } else {
                break; // unclosed block, stop
            }
        }
        foreach ($fileBlocks[1] as $idx => $block) {
            $fields = $this->parseWikiFields($block);

            // Find the nearest "=====For X (Arranger)=====" section heading before this block.
            // These headings appear in the "Arrangements and Transcriptions" section and
            // describe what instrument(s) each arrangement is written for.
            $arrangementFor = '';
            if (isset($blockStarts[$idx])) {
                $textBefore = substr($wikitext, 0, $blockStarts[$idx]);
                // Match the last level-3+ heading of the form "=====For X (optionalName)====="
                if (preg_match_all('/={3,}For\s+([^=\n]+?)(?:\s*\([^)]*\))?\s*={3,}/i', $textBefore, $hm)) {
                    $arrangementFor = trim(end($hm[1]));
                }
            }

            $rawMiscNotes = $fields['Misc. Notes'] ?? '';
            $edition = [
                'copyright'      => $this->stripWikiMarkup($fields['Copyright'] ?? ''),
                'publisher'      => $this->stripWikiMarkup($fields['Publisher Information'] ?? ''),
                'arranger'       => $this->stripWikiMarkup($fields['Arranger'] ?? ''),
                'editor'         => $this->stripWikiMarkup($fields['Editor'] ?? ''),
                'dateSubmitted'  => $fields['Date Submitted'] ?? '',
                'imageType'      => $this->stripWikiMarkup($fields['Image Type'] ?? ''),
                'uploader'       => $this->stripWikiMarkup($fields['Uploader'] ?? ''),
                'scanner'        => $this->stripWikiMarkup($fields['Scanner'] ?? ''),
                'plateNumber'    => $this->stripWikiMarkup($fields['Plate Number'] ?? ''),
                'miscNotes'      => $this->stripWikiMarkup($rawMiscNotes),
                'arrangementFor' => $arrangementFor,
                // RISM source ID extracted from raw (pre-stripWikiMarkup) misc notes
                // so the URL survives the link-stripping pass.
                'rismSourceId'   => $this->extractRismId($rawMiscNotes),
                'files'          => [],
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
     * Strip any trailing |FieldName=Value pairs that appear at brace depth 0.
     * Safe: leaves |param=value inside {{Template|...}} untouched.
     */
    private function cutInlineFields(string $val): string
    {
        $depth = 0;
        $len   = strlen($val);
        for ($i = 0; $i < $len; $i++) {
            if (substr($val, $i, 2) === '{{') { $depth++; $i++; continue; }
            if (substr($val, $i, 2) === '}}') { if ($depth > 0) $depth--; $i++; continue; }
            if ($depth === 0 && $val[$i] === '|'
                && preg_match('/^[A-Za-z][^=\n]*=/', substr($val, $i + 1))
            ) {
                return rtrim(substr($val, 0, $i));
            }
        }
        return $val;
    }

    /**
     * Parse wikitext |Field=Value lines into an associative array.
     * Handles multi-line values: a field's value continues until the next |Field= line
     * at brace depth 0.  Lines that look like |Field=Value inside an open {{ template
     * (depth > 0) are treated as continuation lines, not new fields.
     */
    private function parseWikiFields(string $text): array
    {
        $fields = [];
        $text   = str_replace("\r\n", "\n", $text);
        $lines  = explode("\n", $text);

        $currentKey  = null;
        $currentVal  = [];
        $braceDepth  = 0; // net {{ depth carried across lines

        foreach ($lines as $line) {
            $isFieldLine = $braceDepth === 0
                && preg_match('/^\s*\|\s*([^=|]+?)\s*=\s*(.*)/s', $line, $m);

            if ($isFieldLine) {
                if ($currentKey !== null) {
                    $fields[$currentKey] = trim(implode("\n", $currentVal));
                }
                $currentKey = trim($m[1]);
                $val        = $this->cutInlineFields($m[2]);
                $currentVal = [$val];
                // Track net open templates in the value portion of this line
                $braceDepth = max(0, substr_count($m[2], '{{') - substr_count($m[2], '}}'));
            } elseif ($currentKey !== null) {
                $currentVal[] = $line;
                $braceDepth   = max(0, $braceDepth + substr_count($line, '{{') - substr_count($line, '}}'));
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

        // {{LinkEd|Firstname|Lastname|...}}, {{LinkCopy|...}}, {{LinkArr|...}} → "Firstname Lastname"
        $nameCb = function (string $inner) {
            $params = $this->extractTemplateParams($inner);
            $first  = $params[1] ?? '';
            $last   = $params[2] ?? '';
            return trim("$first $last");
        };
        $text = $this->replaceTemplateWithCallback($text, 'LinkEd',   $nameCb);
        $text = $this->replaceTemplateWithCallback($text, 'LinkCopy', $nameCb);
        $text = $this->replaceTemplateWithCallback($text, 'LinkArr',  $nameCb);

        // {{Key|G}} → "G major", {{Key|G|minor}} → "G minor"
        $text = $this->replaceTemplateWithCallback($text, 'Key', function (string $inner) {
            $params = $this->extractTemplateParams($inner);
            $root   = trim($params[1] ?? '');
            $mode   = strtolower(trim($params[2] ?? ''));
            if ($root === '') return '';
            if (str_starts_with($mode, 'min')) return "$root minor";
            return "$root major";
        });

        // {{FE|...}} — remove (it's a tag on the edition object, not part of a text field)
        $text = $this->replaceNamedTemplate($text, 'FE', '');

        // {{P|Name|Short|City|Country|Year|Plate|...}} → "Name, Year" (year scanned by value)
        $text = $this->replaceTemplateWithCallback($text, 'P', function (string $inner) {
            $params = $this->extractTemplateParams($inner);
            $name = trim($params[1] ?? '');
            $year = '';
            for ($i = 2; $i < count($params); $i++) {
                if (preg_match('/^\d{4}$/', trim($params[$i]))) {
                    $year = trim($params[$i]);
                    break;
                }
            }
            return $year !== '' ? "$name, $year" : $name;
        });

        // {{WC|id|Label}} → just the label (Wikipedia-Commons link)
        $text = $this->replaceTemplateWithCallback($text, 'WC', function (string $inner) {
            $params = $this->extractTemplateParams($inner);
            // params: [0]=WC, [1]=id, [2]=label  or  [0]=WC, [1]=label
            $label = count($params) >= 3 ? trim($params[2]) : trim($params[1] ?? '');
            return $label !== '' ? $label : '';
        });

        // {{LinkWork|page_id|title}} → just the title
        $text = $this->replaceTemplateWithCallback($text, 'LinkWork', function (string $inner) {
            $params = $this->extractTemplateParams($inner);
            return trim($params[2] ?? $params[1] ?? '');
        });

        // Remove remaining {{...}} iteratively (any nesting depth, outermost first)
        $prev = null;
        while ($prev !== $text) {
            $prev = $text;
            $text = preg_replace('/\{\{[^{}]*\}\}/', '', $text);
        }
        // Remove any leftover {{ or }} fragments (e.g. truncated templates from old fetches)
        $text = str_replace(['{{', '}}'], '', $text);
        // '''bold''' and ''italic''
        $text = str_replace(["'''", "''"], '', $text);
        // HTML tags (e.g. <br>, <br/>, <ref>…</ref>)
        $text = preg_replace('/<[^>]+>/', ' ', $text);
        // Bare year ranges left over after template stripping, e.g. " (1687-1863)" or "(1687–1863)"
        $text = preg_replace('/\s*\(\d{3,4}\s*[-–]\d{3,4}\)/', '', $text);
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
