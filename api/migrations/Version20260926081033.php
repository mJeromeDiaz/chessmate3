<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * OAuth flows in progress (state + PKCE verifier, bound to the browser).
 */
final class Version20260926081033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create oauth_flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_flow (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, provider VARCHAR(20) NOT NULL, purpose VARCHAR(10) NOT NULL, binding_hash VARCHAR(64) NOT NULL, state_hash VARCHAR(64) NOT NULL, code_verifier VARCHAR(128) NOT NULL, user_id BINARY(16) DEFAULT NULL, INDEX idx_oauth_flow_expires_at (expires_at), UNIQUE INDEX uniq_oauth_flow_binding (binding_hash), INDEX IDX_598915E1A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE oauth_flow ADD CONSTRAINT FK_598915E1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_flow DROP FOREIGN KEY FK_598915E1A76ED395');
        $this->addSql('DROP TABLE oauth_flow');
    }
}
