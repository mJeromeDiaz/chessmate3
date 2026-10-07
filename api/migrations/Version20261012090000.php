<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Streak announcements (docs/GAMIFICATION.md, "Annonce de la série"): one row per user.
 */
final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gamification: gamification_streak_notice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gamification_streak_notice (id BINARY(16) NOT NULL, local_date DATE NOT NULL, streak INT NOT NULL, previous_streak INT NOT NULL, badge VARCHAR(32) DEFAULT NULL, acknowledged_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_gamification_streak_notice_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE gamification_streak_notice ADD CONSTRAINT FK_DB580427A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gamification_streak_notice DROP FOREIGN KEY FK_DB580427A76ED395');
        $this->addSql('DROP TABLE gamification_streak_notice');
    }
}
