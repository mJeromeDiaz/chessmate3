<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Timed runs (docs/TRAINING.md): one active run per user, enforced by a generated column; the
 * Woodpecker attempts played in a run reference it.
 */
final class Version20260929082658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create training_run, add woodpecker_attempt.training_run_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE training_run (id BINARY(16) NOT NULL, module VARCHAR(32) NOT NULL, subject_type VARCHAR(32) NOT NULL, subject_id BINARY(16) NOT NULL, config JSON NOT NULL, budget_seconds INT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL, active_user_id BINARY(16) GENERATED ALWAYS AS (IF(status = \'active\', user_id, NULL)) VIRTUAL, started_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, close_reason VARCHAR(24) DEFAULT NULL, summary JSON DEFAULT NULL, parent_id BINARY(16) DEFAULT NULL, user_id BINARY(16) NOT NULL, INDEX idx_training_run_user_started (user_id, started_at), INDEX idx_training_run_subject (subject_type, subject_id, started_at), INDEX idx_training_run_user (user_id), UNIQUE INDEX uniq_training_run_active_user (active_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE training_run ADD CONSTRAINT fk_training_run_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE woodpecker_attempt ADD training_run_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_woodpecker_attempt_training_run_status ON woodpecker_attempt (training_run_id, status)');
        $this->addSql('CREATE INDEX idx_woodpecker_attempt_training_run ON woodpecker_attempt (training_run_id)');
        $this->addSql('ALTER TABLE woodpecker_attempt ADD CONSTRAINT fk_woodpecker_attempt_training_run FOREIGN KEY (training_run_id) REFERENCES training_run (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE woodpecker_attempt DROP FOREIGN KEY fk_woodpecker_attempt_training_run');
        $this->addSql('DROP INDEX idx_woodpecker_attempt_training_run_status ON woodpecker_attempt');
        $this->addSql('DROP INDEX idx_woodpecker_attempt_training_run ON woodpecker_attempt');
        $this->addSql('ALTER TABLE woodpecker_attempt DROP training_run_id');
        $this->addSql('ALTER TABLE training_run DROP FOREIGN KEY fk_training_run_user');
        $this->addSql('DROP TABLE training_run');
    }
}
