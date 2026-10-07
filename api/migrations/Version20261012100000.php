<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Streak in danger" reminders (docs/NOTIFICATIONS.md, § 5): settings and next due time, one row per user.
 */
final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gamification: gamification_streak_reminder';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gamification_streak_reminder (id BINARY(16) NOT NULL, enabled TINYINT NOT NULL, hour SMALLINT NOT NULL, email TINYINT NOT NULL, next_due_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, INDEX idx_gamification_streak_reminder_due (next_due_at), UNIQUE INDEX uniq_gamification_streak_reminder_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE gamification_streak_reminder ADD CONSTRAINT FK_62E5AABAA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gamification_streak_reminder DROP FOREIGN KEY FK_62E5AABAA76ED395');
        $this->addSql('DROP TABLE gamification_streak_reminder');
    }
}
