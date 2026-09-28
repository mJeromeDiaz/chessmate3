<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MFA challenges record which 2FA method issued them, and the code hash becomes optional (only
 * methods with a server-generated code, like email, have one).
 */
final class Version20260926070434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mfa_challenge.method, make mfa_challenge.code_hash nullable';
    }

    public function up(Schema $schema): void
    {
        // Every challenge created before this migration was an email-code one.
        $this->addSql("ALTER TABLE mfa_challenge ADD method VARCHAR(32) NOT NULL DEFAULT 'email', CHANGE code_hash code_hash VARCHAR(64) DEFAULT NULL");
        $this->addSql('ALTER TABLE mfa_challenge ALTER method DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mfa_challenge DROP method, CHANGE code_hash code_hash VARCHAR(64) NOT NULL');
    }
}
