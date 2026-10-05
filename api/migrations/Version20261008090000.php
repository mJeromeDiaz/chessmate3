<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Early access: invitation keys and their log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE early_access_invitation_key (id BINARY(16) NOT NULL, key_hash CHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, key_hint CHAR(4) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, email VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, email_status VARCHAR(8) NOT NULL, email_sent_at DATETIME DEFAULT NULL, send_count SMALLINT UNSIGNED NOT NULL, created_by_id BINARY(16) DEFAULT NULL, used_by_id BINARY(16) DEFAULT NULL, INDEX idx_early_access_invitation_key_created (created_at), UNIQUE INDEX uniq_early_access_invitation_key_hash (key_hash), INDEX IDX_4EDD48FB03A8386 (created_by_id), INDEX IDX_4EDD48F4C2B72A8 (used_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE early_access_invitation_log (id BINARY(16) NOT NULL, action VARCHAR(24) NOT NULL, details JSON NOT NULL, created_at DATETIME NOT NULL, invitation_id BINARY(16) NOT NULL, actor_id BINARY(16) DEFAULT NULL, INDEX idx_early_access_invitation_log_created (created_at), INDEX IDX_14217E3A35D7AF0 (invitation_id), INDEX IDX_14217E310DAF24A (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE early_access_invitation_key ADD CONSTRAINT FK_4EDD48FB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE early_access_invitation_key ADD CONSTRAINT FK_4EDD48F4C2B72A8 FOREIGN KEY (used_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE early_access_invitation_log ADD CONSTRAINT FK_14217E3A35D7AF0 FOREIGN KEY (invitation_id) REFERENCES early_access_invitation_key (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE early_access_invitation_log ADD CONSTRAINT FK_14217E310DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE early_access_invitation_key DROP FOREIGN KEY FK_4EDD48FB03A8386');
        $this->addSql('ALTER TABLE early_access_invitation_key DROP FOREIGN KEY FK_4EDD48F4C2B72A8');
        $this->addSql('ALTER TABLE early_access_invitation_log DROP FOREIGN KEY FK_14217E3A35D7AF0');
        $this->addSql('ALTER TABLE early_access_invitation_log DROP FOREIGN KEY FK_14217E310DAF24A');
        $this->addSql('DROP TABLE early_access_invitation_key');
        $this->addSql('DROP TABLE early_access_invitation_log');
    }
}
