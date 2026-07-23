<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add abstract ensemble columns (part_count_min/max, voice_registers) parsed from instrumentation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE imslp_work
            ADD part_count_min INT DEFAULT NULL,
            ADD part_count_max INT DEFAULT NULL,
            ADD voice_registers VARCHAR(16) DEFAULT NULL');

        $this->addSql('CREATE INDEX idx_imslp_work_part_count_min ON imslp_work (part_count_min)');
        $this->addSql('CREATE INDEX idx_imslp_work_part_count_max ON imslp_work (part_count_max)');
        $this->addSql('CREATE INDEX idx_imslp_work_voice_registers ON imslp_work (voice_registers)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_imslp_work_part_count_min ON imslp_work');
        $this->addSql('DROP INDEX idx_imslp_work_part_count_max ON imslp_work');
        $this->addSql('DROP INDEX idx_imslp_work_voice_registers ON imslp_work');
        $this->addSql('ALTER TABLE imslp_work
            DROP part_count_min,
            DROP part_count_max,
            DROP voice_registers');
    }
}
