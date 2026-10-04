<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261004095500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Web Push subscriptions (notification_push_subscription) and the reminder log of saved sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_push_subscription (id BINARY(16) NOT NULL, endpoint VARCHAR(2048) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, endpoint_hash CHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, public_key VARCHAR(128) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, auth_token VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, user_agent VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL, last_success_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, INDEX idx_notification_push_subscription_user (user_id), UNIQUE INDEX uniq_notification_push_subscription_endpoint (endpoint_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE training_reminder_log (id INT UNSIGNED AUTO_INCREMENT NOT NULL, occurs_at DATETIME NOT NULL, sent_at DATETIME NOT NULL, plan_id BINARY(16) NOT NULL, INDEX idx_training_reminder_log_plan (plan_id), UNIQUE INDEX uniq_training_reminder_log_plan_occurrence (plan_id, occurs_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification_push_subscription ADD CONSTRAINT fk_notification_push_subscription_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_reminder_log ADD CONSTRAINT fk_training_reminder_log_plan FOREIGN KEY (plan_id) REFERENCES training_session_plan (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_training_session_plan_reminder ON training_session_plan (reminder_enabled)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_push_subscription DROP FOREIGN KEY fk_notification_push_subscription_user');
        $this->addSql('ALTER TABLE training_reminder_log DROP FOREIGN KEY fk_training_reminder_log_plan');
        $this->addSql('DROP TABLE notification_push_subscription');
        $this->addSql('DROP TABLE training_reminder_log');
        $this->addSql('DROP INDEX idx_training_session_plan_reminder ON training_session_plan');
    }
}
