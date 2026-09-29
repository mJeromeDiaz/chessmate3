<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * IANA timezone of the user (local dates for activity and Woodpecker deadlines).
 */
final class Version20260928161325 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_user.timezone';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD timezone VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP timezone');
    }
}
