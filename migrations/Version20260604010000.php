<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix composite indexes created in Version20260604000000 with title(255) prefixes.
 *
 * Prefix indexes cannot be used by MariaDB for ORDER BY optimisation because
 * they don't guarantee a total order on the full column value.  The fixes:
 *
 *  • idx_composer_title: recreate as (composer, title) without any prefix.
 *    composer(255) + title(512) in utf8mb4 = 3068 bytes, just under the
 *    3072-byte InnoDB DYNAMIC row-format limit. MariaDB can now satisfy
 *    ORDER BY title on a single-composer scan from the index alone (no
 *    filesort for the common case of browsing one composer's works).
 *
 *  • idx_style_composer_title: a full (piece_style, composer, title) index
 *    would be 3468 bytes — too large. Replace with a two-column
 *    (piece_style, composer) index instead. The style-filter sort still
 *    needs a filesort for the title column, but MariaDB's LIMIT 30
 *    priority-queue optimisation keeps this cheap (O(N·log 30) ≈ O(N)).
 */
final class Version20260604010000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $conn   = $this->connection;
        $wiKeys = array_column($conn->fetchAllAssociative('SHOW INDEX FROM imslp_work'), 'Key_name');

        if (in_array('idx_composer_title', $wiKeys, true)) {
            $this->addSql('DROP INDEX idx_composer_title ON imslp_work');
        }
        if (!in_array('idx_composer_title', $wiKeys, true) || true) {
            $this->addSql('CREATE INDEX idx_composer_title ON imslp_work (composer, title)');
        }

        if (in_array('idx_style_composer_title', $wiKeys, true)) {
            $this->addSql('DROP INDEX idx_style_composer_title ON imslp_work');
        }
        $this->addSql('CREATE INDEX idx_style_composer ON imslp_work (piece_style, composer)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_composer_title ON imslp_work');
        $this->addSql('DROP INDEX idx_style_composer ON imslp_work');
        $this->addSql('CREATE INDEX idx_composer_title ON imslp_work (composer, title(255))');
        $this->addSql('CREATE INDEX idx_style_composer_title ON imslp_work (piece_style, composer, title(255))');
    }
}
