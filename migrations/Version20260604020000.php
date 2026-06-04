<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add filtered index on detail_synced_at for countWithoutDetail() optimization';
    }

    public function up(Schema $schema): void
    {
        // MariaDB 10.11 doesn't support partial/filtered indexes; regular index on
        // detail_synced_at helps with IS NULL / IS NOT NULL queries in countWithoutDetail()
        $this->addSql('CREATE INDEX idx_detail_synced_at ON imslp_work (detail_synced_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_detail_synced_at ON imslp_work');
    }
}
