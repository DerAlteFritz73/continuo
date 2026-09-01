<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260827000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add arrangement_for to imslp_edition — the instrument label from the "For X" section heading on IMSLP arrangement pages, previously parsed but discarded';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE imslp_edition ADD arrangement_for VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_imslp_edition_arrangement_for ON imslp_edition (arrangement_for)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_imslp_edition_arrangement_for ON imslp_edition');
        $this->addSql('ALTER TABLE imslp_edition DROP COLUMN arrangement_for');
    }
}
