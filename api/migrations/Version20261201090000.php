<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guests (docs/GUEST.md): accounts opened by « Essayer sans compte », purged once unreachable.
 */
final class Version20261201090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guests: app_user.guest and its index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD guest TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_user_guest ON app_user (guest, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_guest ON app_user');
        $this->addSql('ALTER TABLE app_user DROP guest');
    }
}
