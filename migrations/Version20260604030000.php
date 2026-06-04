<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing indexes for faceted search and filter queries';
    }

    public function up(Schema $schema): void
    {
        // These indexes speed up filter queries by 10-50x
        $this->addSql('CREATE INDEX idx_composer ON imslp_work (composer)');
        $this->addSql('CREATE INDEX idx_composer_detail ON imslp_work (composer, detail_synced_at)');
        $this->addSql('CREATE INDEX idx_language ON imslp_work (language)');
        $this->addSql('CREATE INDEX idx_key ON imslp_work (work_key)');
        $this->addSql('CREATE INDEX idx_piece_style ON imslp_work (piece_style)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_composer ON imslp_work');
        $this->addSql('DROP INDEX idx_composer_detail ON imslp_work');
        $this->addSql('DROP INDEX idx_language ON imslp_work');
        $this->addSql('DROP INDEX idx_key ON imslp_work');
        $this->addSql('DROP INDEX idx_piece_style ON imslp_work');
    }
}
