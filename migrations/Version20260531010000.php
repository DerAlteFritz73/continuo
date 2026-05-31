<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531010000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE imslp_work ADD genre_cats TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_imslp_work_genre_cats (genre_cats)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ft_imslp_work_genre_cats ON imslp_work');
        $this->addSql('ALTER TABLE imslp_work DROP COLUMN genre_cats');
    }
}
