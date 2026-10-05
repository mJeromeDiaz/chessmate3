<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User display name, unique handle and piece avatar (profile, null until chosen)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD display_name VARCHAR(40) DEFAULT NULL, ADD handle VARCHAR(20) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, ADD avatar VARCHAR(8) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_handle ON app_user (handle)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_handle ON app_user');
        $this->addSql('ALTER TABLE app_user DROP display_name, DROP handle, DROP avatar');
    }
}
