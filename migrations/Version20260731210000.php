<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which build of the app a comment was written against';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment ADD app_version VARCHAR(12) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment DROP app_version');
    }
}
