<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create imslp_composer and imslp_work tables for IMSLP browser';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE imslp_composer (
                id         INT AUTO_INCREMENT NOT NULL,
                imslp_id   VARCHAR(512)  NOT NULL,
                name       VARCHAR(255)  NOT NULL,
                permlink   VARCHAR(512)  NOT NULL,
                synced_at  DATETIME      NOT NULL,
                UNIQUE INDEX UNIQ_imslp_composer_id (imslp_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE imslp_work (
                id                INT AUTO_INCREMENT NOT NULL,
                imslp_id          VARCHAR(512)  NOT NULL,
                title             VARCHAR(512)  NOT NULL,
                composer          VARCHAR(255)  NOT NULL,
                catalog_number    VARCHAR(150)  NOT NULL,
                page_id           INT           NOT NULL,
                permlink          VARCHAR(512)  NOT NULL,
                work_key          VARCHAR(255)  DEFAULT NULL,
                instrumentation   LONGTEXT      DEFAULT NULL,
                piece_style       VARCHAR(100)  DEFAULT NULL,
                year_composed     VARCHAR(100)  DEFAULT NULL,
                year_published    VARCHAR(100)  DEFAULT NULL,
                tags              LONGTEXT      DEFAULT NULL,
                page_type         VARCHAR(100)  DEFAULT NULL,
                movements         LONGTEXT      DEFAULT NULL,
                files_json        LONGTEXT      DEFAULT NULL COMMENT '(DC2Type:json)',
                detail_synced_at  DATETIME      DEFAULT NULL,
                synced_at         DATETIME      NOT NULL,
                UNIQUE INDEX UNIQ_imslp_work_imslp_id (imslp_id),
                INDEX idx_imslp_work_composer (composer),
                INDEX idx_imslp_work_page_id (page_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE imslp_work');
        $this->addSql('DROP TABLE imslp_composer');
    }
}
