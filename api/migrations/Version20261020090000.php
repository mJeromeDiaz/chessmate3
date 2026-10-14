<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crowns earned by completed sessions (docs/SHOP.md, § 4 bis): the admin's reward rule (one row,
 * missing until changed: the defaults apply) and one reward per session.
 */
final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store: shop_reward_rule and shop_session_reward (crowns earned by sessions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shop_reward_rule (id SMALLINT UNSIGNED NOT NULL, crowns_per_hour INT UNSIGNED NOT NULL, perfect_percent SMALLINT UNSIGNED NOT NULL, near_percent SMALLINT UNSIGNED NOT NULL, missed_percent SMALLINT UNSIGNED NOT NULL, daily_minutes SMALLINT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL, updated_by_id BINARY(16) DEFAULT NULL, INDEX IDX_95BECA47896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop_session_reward (id BINARY(16) NOT NULL, session_id BINARY(16) NOT NULL, local_date DATE NOT NULL, item_count SMALLINT UNSIGNED NOT NULL, success_count SMALLINT UNSIGNED NOT NULL, paid_seconds INT UNSIGNED NOT NULL, capped TINYINT NOT NULL, tier VARCHAR(8) NOT NULL, crowns INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, opened_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_B11B790CA76ED395 (user_id), INDEX idx_shop_session_reward_user_day (user_id, local_date), INDEX idx_shop_session_reward_date (created_at), UNIQUE INDEX uniq_shop_session_reward_session (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shop_reward_rule ADD CONSTRAINT FK_95BECA47896DBBDE FOREIGN KEY (updated_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE shop_session_reward ADD CONSTRAINT FK_B11B790CA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shop_reward_rule DROP FOREIGN KEY FK_95BECA47896DBBDE');
        $this->addSql('ALTER TABLE shop_session_reward DROP FOREIGN KEY FK_B11B790CA76ED395');
        $this->addSql('DROP TABLE shop_reward_rule');
        $this->addSql('DROP TABLE shop_session_reward');
    }
}
