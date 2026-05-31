<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        // B-tree indexes for filtered queries
        $this->addSql('CREATE INDEX idx_imslp_work_detail_synced_at ON imslp_work (detail_synced_at)');
        $this->addSql('CREATE INDEX idx_imslp_work_piece_style ON imslp_work (piece_style)');

        // FULLTEXT indexes for fast text search (replaces LIKE + REGEXP full scans)
        $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_imslp_work_title (title, composer, catalog_number)');
        $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_imslp_work_tags (tags)');
        $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_imslp_work_instrumentation (instrumentation)');
        $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_imslp_work_composer (composer)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_imslp_work_detail_synced_at ON imslp_work');
        $this->addSql('DROP INDEX idx_imslp_work_piece_style ON imslp_work');
        $this->addSql('DROP INDEX ft_imslp_work_title ON imslp_work');
        $this->addSql('DROP INDEX ft_imslp_work_tags ON imslp_work');
        $this->addSql('DROP INDEX ft_imslp_work_instrumentation ON imslp_work');
        $this->addSql('DROP INDEX ft_imslp_work_composer ON imslp_work');
    }
}
