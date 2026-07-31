<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create comment table (visitor feedback with mandatory e-mail address)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comment (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(180) NOT NULL,
            body LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            locale VARCHAR(8) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            notified TINYINT(1) DEFAULT NULL,
            notify_error LONGTEXT DEFAULT NULL,
            INDEX idx_comment_created_at (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE comment');
    }
}
