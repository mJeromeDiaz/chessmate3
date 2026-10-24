<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Items given by an admin (docs/SHOP.md, § 4 quater): a player's gifts (their history and the
 * message shown at the next visit) and the journal of the gifts to every active account.
 */
final class Version20261115090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store: items given by an admin (shop_item_grant, shop_grant_batch)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shop_grant_batch (id BINARY(16) NOT NULL, item VARCHAR(24) NOT NULL, quantity INT UNSIGNED NOT NULL, message VARCHAR(255) NOT NULL, recipients INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, admin_id BINARY(16) DEFAULT NULL, INDEX idx_shop_grant_batch_created (created_at), INDEX IDX_670C5D5F642B8210 (admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop_item_grant (id BINARY(16) NOT NULL, item VARCHAR(24) NOT NULL, quantity INT UNSIGNED NOT NULL, message VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, seen_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, admin_id BINARY(16) DEFAULT NULL, batch_id BINARY(16) DEFAULT NULL, INDEX idx_shop_item_grant_user_seen (user_id, seen_at), INDEX IDX_99D190BAA76ED395 (user_id), INDEX IDX_99D190BA642B8210 (admin_id), INDEX IDX_99D190BAF39EBE7A (batch_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shop_grant_batch ADD CONSTRAINT FK_670C5D5F642B8210 FOREIGN KEY (admin_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE shop_item_grant ADD CONSTRAINT FK_99D190BAA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shop_item_grant ADD CONSTRAINT FK_99D190BA642B8210 FOREIGN KEY (admin_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE shop_item_grant ADD CONSTRAINT FK_99D190BAF39EBE7A FOREIGN KEY (batch_id) REFERENCES shop_grant_batch (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shop_grant_batch DROP FOREIGN KEY FK_670C5D5F642B8210');
        $this->addSql('ALTER TABLE shop_item_grant DROP FOREIGN KEY FK_99D190BAA76ED395');
        $this->addSql('ALTER TABLE shop_item_grant DROP FOREIGN KEY FK_99D190BA642B8210');
        $this->addSql('ALTER TABLE shop_item_grant DROP FOREIGN KEY FK_99D190BAF39EBE7A');
        $this->addSql('DROP TABLE shop_grant_batch');
        $this->addSql('DROP TABLE shop_item_grant');
    }
}
