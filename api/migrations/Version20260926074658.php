<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Access-token versioning (user.token_version) and an absolute lifetime for refresh-token families
 * (refresh_token.family_expires_at).
 */
final class Version20260926074658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_user.token_version and refresh_token.family_expires_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD token_version INT DEFAULT 0 NOT NULL');

        // Tokens issued before this migration get their own expiry as their family's absolute
        // expiry: they can still be used, but a rotation won't extend them any further.
        $this->addSql('ALTER TABLE refresh_token ADD family_expires_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE refresh_token SET family_expires_at = valid');
        $this->addSql('ALTER TABLE refresh_token CHANGE family_expires_at family_expires_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP token_version');
        $this->addSql('ALTER TABLE refresh_token DROP family_expires_at');
    }
}
