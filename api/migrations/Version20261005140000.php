<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User preferences: board colours, move sounds, public profile flag (stored only)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD board_theme VARCHAR(12) DEFAULT \'wood\' NOT NULL, ADD move_sound TINYINT DEFAULT 1 NOT NULL, ADD public_profile TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP board_theme, DROP move_sound, DROP public_profile');
    }
}
