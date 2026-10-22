<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store (docs/SHOP.md, § 4 ter): the daily chest, with the streak badge's bonus.
 */
final class Version20261111090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store: shop_daily_reward';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shop_daily_reward (id BINARY(16) NOT NULL, local_date DATE NOT NULL, streak INT UNSIGNED NOT NULL, badge_days SMALLINT UNSIGNED DEFAULT NULL, daily_crowns SMALLINT UNSIGNED NOT NULL, badge_crowns INT UNSIGNED NOT NULL, crowns INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, opened_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, INDEX idx_shop_daily_reward_user_opened (user_id, opened_at), UNIQUE INDEX uniq_shop_daily_reward_user_day (user_id, local_date), INDEX IDX_D28A226DA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shop_daily_reward ADD CONSTRAINT FK_D28A226DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shop_daily_reward DROP FOREIGN KEY FK_D28A226DA76ED395');
        $this->addSql('DROP TABLE shop_daily_reward');
    }
}
