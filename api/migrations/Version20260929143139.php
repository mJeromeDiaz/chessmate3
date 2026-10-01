<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Case-sensitive identifiers compared byte for byte (MySQL's default collation ignores case and
 * accents): the OAuth subject, the reset-password selector (random mixed case), the PKCE verifier and
 * the SHA-256 digests looked up by unique indexes. Going from a case-insensitive to a binary
 * collation cannot create duplicates, so existing rows migrate as they are. The down migration fails
 * if rows differing only by case exist by then (a unique index would reject them), which is intended.
 */
final class Version20260929143139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Binary collations for case-sensitive identifiers and token digests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_identity CHANGE provider_user_id provider_user_id VARCHAR(255) NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE mfa_challenge CHANGE pending_token_hash pending_token_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, CHANGE code_hash code_hash VARCHAR(64) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`');
        $this->addSql('ALTER TABLE oauth_flow CHANGE binding_hash binding_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, CHANGE state_hash state_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, CHANGE code_verifier code_verifier VARCHAR(128) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`');
        $this->addSql('ALTER TABLE refresh_token CHANGE refresh_token refresh_token VARCHAR(128) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`');
        $this->addSql('ALTER TABLE reset_password_request CHANGE selector selector VARCHAR(20) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`');
        $this->addSql('ALTER TABLE trusted_device CHANGE token_hash token_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_identity CHANGE provider_user_id provider_user_id VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE mfa_challenge CHANGE pending_token_hash pending_token_hash VARCHAR(64) NOT NULL, CHANGE code_hash code_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE oauth_flow CHANGE binding_hash binding_hash VARCHAR(64) NOT NULL, CHANGE state_hash state_hash VARCHAR(64) NOT NULL, CHANGE code_verifier code_verifier VARCHAR(128) NOT NULL');
        $this->addSql('ALTER TABLE refresh_token CHANGE refresh_token refresh_token VARCHAR(128) NOT NULL');
        $this->addSql('ALTER TABLE reset_password_request CHANGE selector selector VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE trusted_device CHANGE token_hash token_hash VARCHAR(64) NOT NULL');
    }
}
