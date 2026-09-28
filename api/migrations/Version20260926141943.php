<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unconfirmed address of an account adding a password (it becomes the email once verified).
 */
final class Version20260926141943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_user.pending_email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD pending_email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP pending_email');
    }
}
