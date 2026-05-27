<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260306000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add citations JSON column to voice_leading_rule';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE voice_leading_rule ADD citations LONGTEXT NOT NULL DEFAULT '[]' COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voice_leading_rule DROP COLUMN citations');
    }
}
