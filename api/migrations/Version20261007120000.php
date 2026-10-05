<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gamification: the XP gains, one row per source (then run app:gamification:rebuild for the past)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gamification_xp_entry (id BINARY(16) NOT NULL, kind VARCHAR(16) NOT NULL, module VARCHAR(32) DEFAULT NULL, xp SMALLINT UNSIGNED NOT NULL, source_type VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, source_id VARCHAR(80) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, training_run_id BINARY(16) DEFAULT NULL, local_date DATE NOT NULL, occurred_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_gamification_xp_entry_user_date (user_id, local_date), INDEX idx_gamification_xp_entry_run (training_run_id), UNIQUE INDEX uniq_gamification_xp_entry_source (source_type, source_id), INDEX IDX_DDE50AD5A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE gamification_xp_entry ADD CONSTRAINT FK_DDE50AD5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gamification_xp_entry');
    }
}
