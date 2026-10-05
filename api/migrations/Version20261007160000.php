<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gamification: the weekly quests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gamification_quest (id BINARY(16) NOT NULL, completed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, week_start DATE NOT NULL, template VARCHAR(24) NOT NULL, theme VARCHAR(32) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, goal SMALLINT UNSIGNED NOT NULL, reward SMALLINT UNSIGNED NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_gamification_quest_user_week (user_id, week_start), INDEX IDX_7D8CDD5A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE gamification_quest ADD CONSTRAINT FK_7D8CDD5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gamification_quest');
    }
}
