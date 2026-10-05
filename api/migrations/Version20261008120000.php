<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Early access: account suspension';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD suspended_at DATETIME DEFAULT NULL, ADD suspension_reason VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP suspended_at, DROP suspension_reason');
    }
}
