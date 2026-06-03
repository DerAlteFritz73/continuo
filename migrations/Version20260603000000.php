<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $indexes = array_column(
            $this->connection->fetchAllAssociative('SHOW INDEX FROM imslp_work'),
            'Key_name'
        );
        if (!in_array('idx_synced_at', $indexes, true)) {
            $this->addSql('ALTER TABLE imslp_work ADD INDEX idx_synced_at (synced_at)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE imslp_work DROP INDEX idx_synced_at');
    }
}
