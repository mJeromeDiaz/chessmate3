<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260924164020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mfa_challenge (id BINARY(16) NOT NULL, pending_token_hash VARCHAR(64) NOT NULL, code_hash VARCHAR(64) NOT NULL, attempts INT NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, locked_at DATETIME DEFAULT NULL, last_sent_at DATETIME NOT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_mfa_pending_token_hash (pending_token_hash), INDEX IDX_69813C88A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mfa_challenge ADD CONSTRAINT FK_69813C88A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mfa_challenge DROP FOREIGN KEY FK_69813C88A76ED395');
        $this->addSql('DROP TABLE mfa_challenge');
    }
}
