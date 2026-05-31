<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531030000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE imslp_work ADD average_duration VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE imslp_work ADD librettist TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE imslp_work ADD dedication TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE imslp_work ADD first_performance VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN average_duration');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN librettist');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN dedication');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN first_performance');
    }
}
