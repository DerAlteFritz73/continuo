<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531020000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE imslp_work ADD language VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE imslp_work ADD alternative_title VARCHAR(512) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_imslp_work_language ON imslp_work (language)');

        // Rebuild title FULLTEXT index to include alternative_title
        $this->addSql('DROP INDEX ft_imslp_work_title ON imslp_work');
        $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_imslp_work_title (title, composer, catalog_number, alternative_title)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_imslp_work_language ON imslp_work');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN language');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN alternative_title');

        $this->addSql('DROP INDEX ft_imslp_work_title ON imslp_work');
        $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_imslp_work_title (title, composer, catalog_number)');
    }
}
