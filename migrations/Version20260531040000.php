<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531040000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        // NOTE: ALTER TABLE statements and indexes were applied in a prior partial run.
        // They are guarded here so that re-running is idempotent.
        $conn    = $this->connection;
        $cols    = array_column($conn->fetchAllAssociative('SHOW COLUMNS FROM imslp_composer'), 'Field');
        $workCols = array_column($conn->fetchAllAssociative('SHOW COLUMNS FROM imslp_work'), 'Field');

        if (!in_array('nationality', $cols, true)) {
            $this->addSql('ALTER TABLE imslp_composer ADD nationality VARCHAR(100) DEFAULT NULL');
        }
        if (!in_array('time_period', $cols, true)) {
            $this->addSql('ALTER TABLE imslp_composer ADD time_period VARCHAR(50) DEFAULT NULL');
        }
        if (!in_array('composer_id', $workCols, true)) {
            $this->addSql('ALTER TABLE imslp_work ADD composer_id INT DEFAULT NULL');
        }
        if (!in_array('duration_seconds', $workCols, true)) {
            $this->addSql('ALTER TABLE imslp_work ADD duration_seconds INT DEFAULT NULL');
        }
        if (!in_array('first_perf_date', $workCols, true)) {
            $this->addSql('ALTER TABLE imslp_work ADD first_perf_date VARCHAR(50) DEFAULT NULL');
        }
        if (!in_array('first_perf_location', $workCols, true)) {
            $this->addSql('ALTER TABLE imslp_work ADD first_perf_location VARCHAR(255) DEFAULT NULL');
        }

        $indexes = array_column($conn->fetchAllAssociative('SHOW INDEX FROM imslp_work'), 'Key_name');
        if (!in_array('idx_imslp_work_composer_id', $indexes, true)) {
            $this->addSql('CREATE INDEX idx_imslp_work_composer_id ON imslp_work (composer_id)');
        }
        if (!in_array('idx_imslp_work_duration', $indexes, true)) {
            $this->addSql('CREATE INDEX idx_imslp_work_duration ON imslp_work (duration_seconds)');
        }

        // Backfill composer_id
        $this->addSql(
            'UPDATE imslp_work w JOIN imslp_composer c ON c.name = w.composer SET w.composer_id = c.id'
        );

        // imslp_edition
        $this->addSql('CREATE TABLE imslp_edition (
            id INT AUTO_INCREMENT NOT NULL,
            work_id INT NOT NULL,
            sort_order SMALLINT NOT NULL DEFAULT 0,
            copyright VARCHAR(255) DEFAULT NULL,
            publisher TEXT DEFAULT NULL,
            arranger VARCHAR(512) DEFAULT NULL,
            editor VARCHAR(512) DEFAULT NULL,
            date_submitted VARCHAR(20) DEFAULT NULL,
            image_type VARCHAR(100) DEFAULT NULL,
            uploader VARCHAR(255) DEFAULT NULL,
            scanner VARCHAR(255) DEFAULT NULL,
            plate_number VARCHAR(255) DEFAULT NULL,
            misc_notes TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_edition_work_id (work_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // imslp_edition_file
        $this->addSql('CREATE TABLE imslp_edition_file (
            id INT AUTO_INCREMENT NOT NULL,
            edition_id INT NOT NULL,
            position SMALLINT NOT NULL DEFAULT 1,
            filename VARCHAR(512) NOT NULL,
            description VARCHAR(512) DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_efile_edition_id (edition_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // imslp_category
        $this->addSql('CREATE TABLE imslp_category (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_imslp_category_name (name)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // imslp_work_category
        $this->addSql('CREATE TABLE imslp_work_category (
            work_id INT NOT NULL,
            category_id INT NOT NULL,
            PRIMARY KEY (work_id, category_id),
            INDEX idx_wcat_category_id (category_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE imslp_work_category');
        $this->addSql('DROP TABLE imslp_category');
        $this->addSql('DROP TABLE imslp_edition_file');
        $this->addSql('DROP TABLE imslp_edition');
        $this->addSql('DROP INDEX idx_imslp_work_duration ON imslp_work');
        $this->addSql('DROP INDEX idx_imslp_work_composer_id ON imslp_work');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN first_perf_location');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN first_perf_date');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN duration_seconds');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN composer_id');
        $this->addSql('ALTER TABLE imslp_composer DROP COLUMN time_period');
        $this->addSql('ALTER TABLE imslp_composer DROP COLUMN nationality');
    }
}
