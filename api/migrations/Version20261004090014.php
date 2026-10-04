<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261004090014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training sessions (training_session) and the runs of a session (idx_training_run_parent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE training_session (id BINARY(16) NOT NULL, title VARCHAR(120) NOT NULL, description VARCHAR(500) NOT NULL, steps JSON NOT NULL, current_index SMALLINT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL, active_user_id BINARY(16) GENERATED ALWAYS AS (IF(status = \'active\', user_id, NULL)) VIRTUAL, started_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, INDEX idx_training_session_user_started (user_id, started_at), INDEX idx_training_session_user (user_id), UNIQUE INDEX uniq_training_session_active_user (active_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE training_session ADD CONSTRAINT fk_training_session_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_training_run_parent ON training_run (parent_id, started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_session DROP FOREIGN KEY fk_training_session_user');
        $this->addSql('DROP TABLE training_session');
        $this->addSql('DROP INDEX idx_training_run_parent ON training_run');
    }
}
