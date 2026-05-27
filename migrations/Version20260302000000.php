<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create voice_leading_rule table for DB-driven voice-leading rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE voice_leading_rule (
                id       INT AUTO_INCREMENT NOT NULL,
                name     VARCHAR(100)  NOT NULL,
                source   VARCHAR(255)  NOT NULL,
                priority INT           NOT NULL,
                definition   LONGTEXT  NOT NULL,
                translation  LONGTEXT  NOT NULL,
                implementation LONGTEXT NOT NULL,
                enabled  TINYINT(1)    NOT NULL DEFAULT 1,
                UNIQUE INDEX UNIQ_name (name),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE voice_leading_rule');
    }
}
