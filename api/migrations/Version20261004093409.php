<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261004093409 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Saved training sessions (training_session_plan) and the plan of a launched session';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE training_session_plan (id BINARY(16) NOT NULL, title VARCHAR(120) NOT NULL, description VARCHAR(500) NOT NULL, steps JSON NOT NULL, repetition VARCHAR(16) NOT NULL, time VARCHAR(5) DEFAULT NULL, weekdays JSON NOT NULL, public TINYINT DEFAULT 0 NOT NULL, reminder_enabled TINYINT DEFAULT 0 NOT NULL, reminder_channels JSON NOT NULL, reminder_minutes SMALLINT UNSIGNED DEFAULT 30 NOT NULL, calendar_enabled TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_training_session_plan_user_updated (user_id, updated_at), INDEX idx_training_session_plan_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE training_session_plan ADD CONSTRAINT fk_training_session_plan_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_session ADD plan_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE training_session ADD CONSTRAINT fk_training_session_plan FOREIGN KEY (plan_id) REFERENCES training_session_plan (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_training_session_plan ON training_session (plan_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_session DROP FOREIGN KEY fk_training_session_plan');
        $this->addSql('DROP INDEX idx_training_session_plan ON training_session');
        $this->addSql('ALTER TABLE training_session DROP plan_id');
        $this->addSql('ALTER TABLE training_session_plan DROP FOREIGN KEY fk_training_session_plan_user');
        $this->addSql('DROP TABLE training_session_plan');
    }
}
