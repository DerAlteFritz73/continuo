<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FULLTEXT index on imslp_edition.arrangement_for, so search can match a work by what its individual arrangements are scored for, not just the parent work\'s aggregate instrumentation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE imslp_edition ADD FULLTEXT INDEX ft_imslp_edition_arrangement_for (arrangement_for)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ft_imslp_edition_arrangement_for ON imslp_edition');
    }
}
