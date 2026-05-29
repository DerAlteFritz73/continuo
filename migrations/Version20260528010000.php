<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528010000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_imslp_composer_name ON imslp_composer (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_imslp_composer_name ON imslp_composer');
    }
}
