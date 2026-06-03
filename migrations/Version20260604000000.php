<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Query performance optimisation pass:
 *
 *  - Drop 5 unused / duplicate FULLTEXT indexes (they were created and
 *    partially cleaned up across several earlier migrations, leaving
 *    orphan copies that inflate every INSERT/UPDATE).
 *
 *  - Add (composer, title) covering B-tree index so ORDER BY title on a
 *    composer browse page is satisfied from the index (no filesort).
 *
 *  - Add (piece_style, composer, title) covering index so style-filter
 *    browsing can skip the filesort too.
 *
 *  - Add died_year / born_year indexes on imslp_composer so the
 *    correlated NOT EXISTS subquery used by the year-range filter
 *    builds its materialized hash from an index scan instead of a
 *    full table scan.
 *
 *  - Add dates_synced_at index on imslp_composer (used in sync-status
 *    JOIN).
 *
 *  - Add year_composed_int INT column: stores the first 4-digit year
 *    extracted from year_composed.  Allows the year filter to use a
 *    range index scan instead of applying REGEXP_SUBSTR on every row.
 */
final class Version20260604000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $conn     = $this->connection;
        $wiKeys   = array_column($conn->fetchAllAssociative('SHOW INDEX FROM imslp_work'), 'Key_name');
        $compKeys = array_column($conn->fetchAllAssociative('SHOW INDEX FROM imslp_composer'), 'Key_name');
        $workCols = array_column($conn->fetchAllAssociative('SHOW COLUMNS FROM imslp_work'), 'Field');

        // ── Drop orphaned / duplicate FULLTEXT indexes ──────────────────────
        // ft_composer        duplicate of ft_imslp_work_composer
        // ft_instrumentation duplicate of ft_imslp_work_instrumentation
        // ft_genre_cats      duplicate of ft_imslp_work_genre_cats
        // ft_imslp_work_title 4-column (title,composer,catalog,alt_title) — no
        //                     query ever issues MATCH on those 4 columns;
        //                     the 3-column ft_title_composer_catalog is used.
        // ft_imslp_work_tags  FULLTEXT on tags — all code uses REGEXP on tags,
        //                     not MATCH ... AGAINST.
        foreach (['ft_composer', 'ft_instrumentation', 'ft_genre_cats',
                  'ft_imslp_work_title', 'ft_imslp_work_tags'] as $idx) {
            if (in_array($idx, $wiKeys, true)) {
                $this->addSql("ALTER TABLE imslp_work DROP INDEX $idx");
            }
        }

        // ── Covering B-tree indexes for ORDER BY ────────────────────────────
        if (!in_array('idx_composer_title', $wiKeys, true)) {
            $this->addSql('CREATE INDEX idx_composer_title ON imslp_work (composer, title(255))');
        }
        if (!in_array('idx_style_composer_title', $wiKeys, true)) {
            $this->addSql('CREATE INDEX idx_style_composer_title ON imslp_work (piece_style, composer, title(255))');
        }

        // ── imslp_composer indexes for year-filter NOT EXISTS subqueries ────
        if (!in_array('idx_composer_died_year', $compKeys, true)) {
            $this->addSql('CREATE INDEX idx_composer_died_year ON imslp_composer (died_year)');
        }
        if (!in_array('idx_composer_born_year', $compKeys, true)) {
            $this->addSql('CREATE INDEX idx_composer_born_year ON imslp_composer (born_year)');
        }
        if (!in_array('idx_composer_dates_synced_at', $compKeys, true)) {
            $this->addSql('CREATE INDEX idx_composer_dates_synced_at ON imslp_composer (dates_synced_at)');
        }

        // ── year_composed_int: precomputed integer year ─────────────────────
        if (!in_array('year_composed_int', $workCols, true)) {
            $this->addSql('ALTER TABLE imslp_work ADD COLUMN year_composed_int INT NULL');
        }
        // Backfill runs whenever year_composed_int is still NULL on a row that has
        // a year string.  Guarded with IF(...REGEXP...) to avoid CAST('') errors
        // in STRICT_TRANS_TABLES mode (MariaDB returns '' not NULL on no-match).
        $this->addSql(
            "UPDATE imslp_work
             SET year_composed_int = IF(
                 year_composed REGEXP '[0-9]{4}',
                 CAST(REGEXP_SUBSTR(year_composed, '[0-9]{4}') AS UNSIGNED),
                 NULL
             )
             WHERE year_composed IS NOT NULL AND year_composed != '' AND year_composed_int IS NULL"
        );
        if (!in_array('idx_year_composed_int', $wiKeys, true)) {
            $this->addSql('CREATE INDEX idx_year_composed_int ON imslp_work (year_composed_int)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_year_composed_int ON imslp_work');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN year_composed_int');
        $this->addSql('DROP INDEX idx_composer_dates_synced_at ON imslp_composer');
        $this->addSql('DROP INDEX idx_composer_born_year ON imslp_composer');
        $this->addSql('DROP INDEX idx_composer_died_year ON imslp_composer');
        $this->addSql('DROP INDEX idx_style_composer_title ON imslp_work');
        $this->addSql('DROP INDEX idx_composer_title ON imslp_work');
        // Dropped FT indexes are not restored in down() because rebuilding
        // them on a 260K-row table takes several minutes and they were
        // genuinely redundant.
    }
}
