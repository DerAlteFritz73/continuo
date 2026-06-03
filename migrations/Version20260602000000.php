<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $conn    = $this->connection;
        $indexes = array_column(
            $conn->fetchAllAssociative('SHOW INDEX FROM imslp_work'),
            'Key_name'
        );

        if (!in_array('ft_composer', $indexes, true)) {
            $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_composer (composer)');
        }
        if (!in_array('ft_title_composer_catalog', $indexes, true)) {
            $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_title_composer_catalog (title, composer, catalog_number)');
        }
        if (!in_array('ft_instrumentation', $indexes, true)) {
            $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_instrumentation (instrumentation)');
        }
        if (!in_array('ft_genre_cats', $indexes, true)) {
            $this->addSql('ALTER TABLE imslp_work ADD FULLTEXT INDEX ft_genre_cats (genre_cats)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE imslp_work DROP INDEX ft_composer');
        $this->addSql('ALTER TABLE imslp_work DROP INDEX ft_title_composer_catalog');
        $this->addSql('ALTER TABLE imslp_work DROP INDEX ft_instrumentation');
        $this->addSql('ALTER TABLE imslp_work DROP INDEX ft_genre_cats');
    }
}
