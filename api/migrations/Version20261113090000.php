<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Shop\Catalog\DefaultCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The gift wheel (docs/SHOP.md, § 4 ter): its spins, its odds, the day's wheel on the daily
 * reward, the player's setting, the ticket's offer (the default catalogue's missing offers).
 */
final class Version20261113090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store: the gift wheel (shop_wheel_spin, shop_wheel_rule, shop_daily_reward.wheel, app_user.daily_wheel, wheel_ticket offer)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shop_wheel_rule (id SMALLINT UNSIGNED NOT NULL, weight_c5 SMALLINT UNSIGNED NOT NULL, weight_c30 SMALLINT UNSIGNED NOT NULL, weight_c300 SMALLINT UNSIGNED NOT NULL, weight_gift SMALLINT UNSIGNED NOT NULL, weight_lose SMALLINT UNSIGNED NOT NULL, freeze_percent SMALLINT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL, updated_by_id BINARY(16) DEFAULT NULL, INDEX IDX_89BB117B896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop_wheel_spin (id BINARY(16) NOT NULL, prize VARCHAR(10) NOT NULL, gift VARCHAR(10) DEFAULT NULL, crowns INT UNSIGNED NOT NULL, ticket TINYINT NOT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, daily_reward_id BINARY(16) DEFAULT NULL, INDEX idx_shop_wheel_spin_user_created (user_id, created_at), INDEX IDX_DD699938A76ED395 (user_id), INDEX IDX_DD6999386562B5BB (daily_reward_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shop_wheel_rule ADD CONSTRAINT FK_89BB117B896DBBDE FOREIGN KEY (updated_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE shop_wheel_spin ADD CONSTRAINT FK_DD699938A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shop_wheel_spin ADD CONSTRAINT FK_DD6999386562B5BB FOREIGN KEY (daily_reward_id) REFERENCES shop_daily_reward (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE app_user ADD daily_wheel TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE shop_daily_reward ADD wheel VARCHAR(10) DEFAULT NULL');
        // INSERT IGNORE by slug: only the ticket is new (no slide for it).
        foreach (DefaultCatalog::statements(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) as [$sql, $params]) {
            $this->addSql($sql, $params);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM shop_offer WHERE slug = 'wheel_ticket'");
        $this->addSql('ALTER TABLE shop_wheel_rule DROP FOREIGN KEY FK_89BB117B896DBBDE');
        $this->addSql('ALTER TABLE shop_wheel_spin DROP FOREIGN KEY FK_DD699938A76ED395');
        $this->addSql('ALTER TABLE shop_wheel_spin DROP FOREIGN KEY FK_DD6999386562B5BB');
        $this->addSql('DROP TABLE shop_wheel_rule');
        $this->addSql('DROP TABLE shop_wheel_spin');
        $this->addSql('ALTER TABLE app_user DROP daily_wheel');
        $this->addSql('ALTER TABLE shop_daily_reward DROP wheel');
    }
}
