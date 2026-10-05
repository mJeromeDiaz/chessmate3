<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refresh tokens: sign-in and issue times, User-Agent and IP (active sessions of the profile)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_token ADD signed_in_at DATETIME DEFAULT NULL, ADD issued_at DATETIME DEFAULT NULL, ADD user_agent VARCHAR(255) DEFAULT NULL, ADD ip VARCHAR(45) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_token DROP signed_in_at, DROP issued_at, DROP user_agent, DROP ip');
    }
}
