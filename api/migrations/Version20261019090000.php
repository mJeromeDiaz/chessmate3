<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use App\Shop\Catalog\DefaultCatalog;

/**
 * Crowns and the store (docs/SHOP.md): wallets, ledger, offers, carousel, inventory, freezes; the
 * default catalogue (prices validated 2026-10-13, the admin changes them).
 */
final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crowns and the store: shop_* tables and the default catalogue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shop_offer (id BINARY(16) NOT NULL, slug VARCHAR(40) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, section VARCHAR(16) NOT NULL, name VARCHAR(80) NOT NULL, subtitle VARCHAR(80) DEFAULT NULL, description VARCHAR(255) NOT NULL, contents JSON NOT NULL, price INT UNSIGNED NOT NULL, was_price INT UNSIGNED DEFAULT NULL, position SMALLINT DEFAULT 0 NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, suspended TINYINT DEFAULT 0 NOT NULL, coming_soon TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_shop_offer_section (section, position), UNIQUE INDEX uniq_shop_offer_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop_slide (id BINARY(16) NOT NULL, tag VARCHAR(40) NOT NULL, image VARCHAR(40) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, position SMALLINT DEFAULT 0 NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, suspended TINYINT DEFAULT 0 NOT NULL, offer_id BINARY(16) NOT NULL, INDEX IDX_B5F9B43053C674EE (offer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop_wallet (balance INT UNSIGNED DEFAULT 0 NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop_ledger_entry (id BINARY(16) NOT NULL, kind VARCHAR(16) NOT NULL, delta INT NOT NULL, balance_after INT UNSIGNED NOT NULL, offer_name VARCHAR(80) DEFAULT NULL, quantity SMALLINT UNSIGNED DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, offer_id BINARY(16) DEFAULT NULL, admin_id BINARY(16) DEFAULT NULL, INDEX IDX_79424DFBA76ED395 (user_id), INDEX IDX_79424DFB53C674EE (offer_id), INDEX IDX_79424DFB642B8210 (admin_id), INDEX idx_shop_ledger_user_date (user_id, created_at), INDEX idx_shop_ledger_kind_date (kind, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop_inventory_item (item VARCHAR(24) NOT NULL, quantity INT UNSIGNED DEFAULT 0 NOT NULL, user_id BINARY(16) NOT NULL, PRIMARY KEY (user_id, item)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop_freeze (id BINARY(16) NOT NULL, item VARCHAR(24) NOT NULL, days SMALLINT UNSIGNED NOT NULL, days_left SMALLINT UNSIGNED NOT NULL, acquired_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_C333D298A76ED395 (user_id), INDEX idx_shop_freeze_user_left (user_id, days_left), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shop_slide ADD CONSTRAINT FK_B5F9B43053C674EE FOREIGN KEY (offer_id) REFERENCES shop_offer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shop_wallet ADD CONSTRAINT FK_F9CAB49DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shop_ledger_entry ADD CONSTRAINT FK_79424DFBA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shop_ledger_entry ADD CONSTRAINT FK_79424DFB53C674EE FOREIGN KEY (offer_id) REFERENCES shop_offer (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE shop_ledger_entry ADD CONSTRAINT FK_79424DFB642B8210 FOREIGN KEY (admin_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE shop_inventory_item ADD CONSTRAINT FK_C51CEB36A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shop_freeze ADD CONSTRAINT FK_C333D298A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');

        foreach (DefaultCatalog::statements(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) as [$sql, $params]) {
            $this->addSql($sql, $params);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shop_slide DROP FOREIGN KEY FK_B5F9B43053C674EE');
        $this->addSql('ALTER TABLE shop_wallet DROP FOREIGN KEY FK_F9CAB49DA76ED395');
        $this->addSql('ALTER TABLE shop_ledger_entry DROP FOREIGN KEY FK_79424DFBA76ED395');
        $this->addSql('ALTER TABLE shop_ledger_entry DROP FOREIGN KEY FK_79424DFB53C674EE');
        $this->addSql('ALTER TABLE shop_ledger_entry DROP FOREIGN KEY FK_79424DFB642B8210');
        $this->addSql('ALTER TABLE shop_inventory_item DROP FOREIGN KEY FK_C51CEB36A76ED395');
        $this->addSql('ALTER TABLE shop_freeze DROP FOREIGN KEY FK_C333D298A76ED395');
        $this->addSql('DROP TABLE shop_slide');
        $this->addSql('DROP TABLE shop_offer');
        $this->addSql('DROP TABLE shop_wallet');
        $this->addSql('DROP TABLE shop_ledger_entry');
        $this->addSql('DROP TABLE shop_inventory_item');
        $this->addSql('DROP TABLE shop_freeze');
    }
}
